<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Technical Playwright adapter for PR STUDIO AI BRIDGE.
 *
 * Safety properties:
 * - no worker or installer is started on plugin activation;
 * - no cron process starts or restarts the worker;
 * - no frontend CSS, JS, HTML or HTTP request is added;
 * - the worker is started only by an authenticated browser MCP tool call;
 * - an atomic start lock prevents duplicate processes;
 * - runtime absence returns a diagnostic error and never provisions software.
 */
final class PRSTUDIO_Browser_Runtime {
	private const OPTION = 'prstudio_browser_runtime';
	private const START_LOCK = 'prstudio_browser_runtime_start_lock';
	private const REST_NS = 'prstudio-browser-runtime/v1';
	private const PORT_START = 17321;
	private const PORT_END = 17339;
	private const ARTIFACT_TTL = 1800;
	private const START_LOCK_TTL = 30;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 30 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public static function activate(): void {
		$self = self::instance();
		$self->ensure_directories();
		$self->ensure_settings();
		$self->clear_start_lock_if_stale();
		// Deliberately do not start, install or probe Chromium here.
	}

	public static function deactivate(): void {
		$self = self::instance();
		$self->stop_worker();
		delete_option( self::START_LOCK );
	}

	public function boot(): void {
		if ( ! defined( 'WPAIB_DIR' ) || ! class_exists( 'PRSTUDIO_Agency' ) ) {
			return;
		}
		$this->ensure_directories();
		$this->ensure_settings();
		$this->register_bridge_adapters();
	}

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NS,
			'/artifact/(?P<filename>[a-f0-9-]+\.(?:png|jpg|jpeg|webp|json|html|txt))',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_artifact' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	public function serve_artifact( WP_REST_Request $request ) {
		$filename = sanitize_file_name( (string) $request['filename'] );
		$expires  = absint( $request->get_param( 'expires' ) );
		$sig      = sanitize_text_field( (string) $request->get_param( 'sig' ) );

		if ( $expires < time() || $expires > time() + self::ARTIFACT_TTL + 60 ) {
			return new WP_Error( 'prstudio_browser_artifact_expired', 'Collegamento artifact scaduto.', array( 'status' => 403 ) );
		}
		$expected = hash_hmac( 'sha256', $filename . '|' . $expires, $this->secret() );
		if ( '' === $sig || ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'prstudio_browser_artifact_signature', 'Firma artifact non valida.', array( 'status' => 403 ) );
		}

		$path = $this->data_dir() . '/artifacts/' . $filename;
		$real = realpath( $path );
		$root = realpath( $this->data_dir() . '/artifacts' );
		if ( ! $real || ! $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			return new WP_Error( 'prstudio_browser_artifact_missing', 'Artifact non trovato.', array( 'status' => 404 ) );
		}

		$mime = wp_check_filetype( $filename )['type'] ?: 'application/octet-stream';
		if ( str_ends_with( $filename, '.json' ) ) { $mime = 'application/json'; }
		if ( str_ends_with( $filename, '.html' ) ) { $mime = 'text/html; charset=utf-8'; }
		if ( str_ends_with( $filename, '.txt' ) ) { $mime = 'text/plain; charset=utf-8'; }

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $real ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $filename ) . '"' );
		readfile( $real );
		exit;
	}

	private function register_bridge_adapters(): void {
		$catalog_path = trailingslashit( WPAIB_DIR ) . 'connector/action-catalog.json';
		$catalog = is_readable( $catalog_path ) ? json_decode( (string) file_get_contents( $catalog_path ), true ) : null;
		$registered = array();

		if ( is_array( $catalog ) && is_array( $catalog['actions'] ?? null ) ) {
			foreach ( $catalog['actions'] as $meta ) {
				if ( ! is_array( $meta ) || '/frontend-manage' !== (string) ( $meta['route'] ?? '' ) ) {
					continue;
				}
				$action = sanitize_key( (string) ( $meta['action'] ?? '' ) );
				if ( ! $this->is_browser_action( $action ) ) {
					continue;
				}
				$hook = sanitize_key( (string) ( $meta['adapter_hook'] ?? '' ) );
				if ( '' === $hook ) {
					$hook = 'idealmarket_admin_execute_frontend_manage_' . $action;
				}
				if ( isset( $registered[ $hook ] ) ) {
					continue;
				}
				add_filter( $hook, array( $this, 'dispatch_adapter' ), 10, 3 );
				$registered[ $hook ] = true;
			}
		}

		foreach ( array( 'screenshot', 'create_visual_baseline', 'visual_diff', 'accessibility_tree', 'network_log', 'console_log' ) as $action ) {
			$hook = 'idealmarket_admin_execute_frontend_manage_' . $action;
			if ( ! isset( $registered[ $hook ] ) ) {
				add_filter( $hook, array( $this, 'dispatch_adapter' ), 10, 3 );
			}
		}
	}

	private function canonical_browser_action( string $action ): string {
		$action = sanitize_key( $action );
		if ( 'puppeteer_screenshot' === $action || 'puppeteer_page_screenshot' === $action ) { return 'playwright_screenshot_page'; }
		if ( 'puppeteer_screenshot_element' === $action ) { return 'playwright_screenshot_element'; }
		if ( 'puppeteer_new_page' === $action ) { return 'playwright_new_page'; }
		return str_starts_with( $action, 'puppeteer_' ) ? 'playwright_' . substr( $action, 10 ) : $action;
	}

	private function is_browser_action( string $action ): bool {
		$action = $this->canonical_browser_action( $action );
		return 0 === strpos( $action, 'playwright_' ) || in_array(
			$action,
			array( 'screenshot', 'create_visual_baseline', 'visual_diff', 'accessibility_tree', 'network_log', 'console_log' ),
			true
		);
	}

	/**
	 * Provider hook used by WP AI Bridge.
	 *
	 * @param mixed $current
	 * @param array<string,mixed> $arguments
	 * @param array<string,mixed> $meta
	 * @return mixed
	 */
	public function dispatch_adapter( $current, array $arguments, array $meta ) {
		if ( null !== $current ) {
			return $current;
		}

		$requested_action = sanitize_key( (string) ( $meta['action'] ?? $arguments['action'] ?? '' ) );
		$action = $this->canonical_browser_action( $requested_action );
		if ( $requested_action !== $action ) { $arguments['_prstudio_action_alias'] = $requested_action; }
		if ( ! $this->is_browser_action( $action ) ) {
			return null;
		}

		if ( ! $this->ensure_worker() ) {
			if ( 'playwright_status' === $action ) {
				return $this->status_payload( false );
			}
			return new WP_Error(
				'prstudio_browser_runtime_unavailable',
				$this->last_error() ?: 'Browser Runtime non disponibile.',
				array( 'status' => 503, 'diagnostics' => $this->runtime_diagnostics() )
			);
		}

		$result = $this->worker_request(
			'/v1/action',
			array(
				'action'    => $action,
				'arguments' => $this->normalize_arguments( $arguments ),
			),
			120,
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = $this->sign_artifacts( $result );
		if ( ! is_array( $result ) ) {
			$result = array( 'value' => $result );
		}
		$result['provider'] = 'prstudio_browser_runtime';
		$result['connector'] = 'Ideal Market Master';
		$result['_control_outcome'] = array(
			'status'   => 'verified',
			'executed' => true,
			'mutated'  => false,
			'verified' => true,
					);
		return $result;
	}

	private function status_payload( bool $available ): array {
		$settings = $this->settings();
		return array(
			'available' => $available,
			'provider' => 'prstudio_browser_runtime',
			'connector' => 'Ideal Market Master',
			'version' => PRSTUDIO_BROWSER_RUNTIME_VERSION,
			'read_only' => true,
			'external_worker_required' => false,
			'creates_pending_job' => false,
			'worker_port' => absint( $settings['port'] ?? 0 ),
			'last_error' => $available ? '' : ( $this->last_error() ?: 'Browser Runtime non disponibile.' ),
			'diagnostics' => $this->runtime_diagnostics(),
		);
	}

	private function normalize_arguments( array $arguments ): array {
		foreach ( array( 'payload', 'params', 'body', 'query' ) as $container ) {
			if ( isset( $arguments[ $container ] ) && is_array( $arguments[ $container ] ) ) {
				$arguments = array_replace( $arguments[ $container ], $arguments );
			}
		}
		unset( $arguments['_route'], $arguments['_source'], $arguments['mutation'] );
		return $arguments;
	}

	private function sign_artifacts( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['artifact'] ) && is_array( $value['artifact'] ) && ! empty( $value['artifact']['filename'] ) ) {
			$value['artifact'] = $this->signed_artifact( $value['artifact'] );
		}
		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) && 'artifact' !== $key ) {
				$value[ $key ] = $this->sign_artifacts( $child );
			}
		}
		return $value;
	}

	private function signed_artifact( array $artifact ): array {
		$filename = sanitize_file_name( (string) ( $artifact['filename'] ?? '' ) );
		if ( '' === $filename ) {
			return $artifact;
		}
		$expires = time() + self::ARTIFACT_TTL;
		$sig = hash_hmac( 'sha256', $filename . '|' . $expires, $this->secret() );
		$artifact['url'] = add_query_arg(
			array( 'expires' => $expires, 'sig' => $sig ),
			rest_url( self::REST_NS . '/artifact/' . rawurlencode( $filename ) )
		);
		$artifact['expires_gmt'] = gmdate( 'c', $expires );
		return $artifact;
	}

	private function ensure_worker(): bool {
		if ( $this->worker_healthy() ) {
			$this->set_error( '' );
			return true;
		}

		$runtime = $this->detect_runtime();
		if ( is_wp_error( $runtime ) ) {
			$this->set_error( $runtime->get_error_message() );
			return false;
		}
		if ( ! $this->acquire_start_lock() ) {
			for ( $i = 0; $i < 8; $i++ ) {
				usleep( 125000 );
				if ( $this->worker_healthy() ) {
					$this->set_error( '' );
					return true;
				}
			}
			$this->set_error( 'Avvio Browser Runtime già in corso.' );
			return false;
		}

		try {
			if ( $this->worker_healthy() ) {
				$this->set_error( '' );
				return true;
			}

			$settings = $this->settings();
			$port = absint( $settings['port'] ?? 0 );
			if ( $port < self::PORT_START || $port > self::PORT_END || ! $this->port_is_available_for_runtime( $port ) ) {
				$port = $this->find_free_port();
				$settings['port'] = $port;
				$this->save_settings( $settings );
			}
			if ( $port <= 0 ) {
				$this->set_error( 'Nessuna porta locale disponibile per il Browser Runtime.' );
				return false;
			}

			$worker = PRSTUDIO_BROWSER_RUNTIME_DIR . 'runtime/worker.py';
			if ( ! is_file( $worker ) ) {
				$this->set_error( 'Worker Python mancante dal plugin.' );
				return false;
			}

			$log = $this->data_dir() . '/logs/worker.log';
			$allowed_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$command = array(
				$runtime['python'],
				$worker,
				'--host', '127.0.0.1',
				'--port', (string) $port,
				'--secret', $this->secret(),
				'--data-dir', $this->data_dir(),
				'--chromium', $runtime['chromium'],
				'--allowed-host', $allowed_host,
			);

			$pid = $this->spawn_detached( $command, $log );
			if ( $pid <= 0 ) {
				$this->set_error( 'Impossibile avviare il worker locale.' );
				return false;
			}

			$settings = $this->settings();
			$settings['pid'] = $pid;
			$settings['python'] = $runtime['python'];
			$settings['chromium'] = $runtime['chromium'];
			$settings['started_gmt'] = gmdate( 'c' );
			$this->save_settings( $settings );

			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 150000 );
				if ( $this->worker_healthy() ) {
					$this->set_error( '' );
					return true;
				}
			}
			$this->set_error( 'Il worker non ha superato il controllo locale.' );
			return false;
		} finally {
			$this->release_start_lock();
		}
	}

	private function stop_worker(): void {
		if ( $this->worker_healthy() ) {
			$this->worker_request( '/shutdown', array(), 2, true );
		}
		$settings = $this->settings();
		$settings['pid'] = 0;
		$this->save_settings( $settings );
	}

	private function worker_healthy(): bool {
		$raw = $this->worker_request( '/health', null, 2, false );
		return is_array( $raw )
			&& true === ( $raw['ok'] ?? false )
			&& is_array( $raw['result'] ?? null )
			&& true === ( $raw['result']['available'] ?? false );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function worker_request( string $path, ?array $body = null, int $timeout = 120, bool $unwrap = true ) {
		$settings = $this->settings();
		$port = absint( $settings['port'] ?? 0 );
		if ( $port <= 0 ) {
			return new WP_Error( 'prstudio_browser_port_missing', 'Porta Browser Runtime non configurata.' );
		}

		$url = 'http://127.0.0.1:' . $port . $path;
		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'X-PRStudio-Secret' => $this->secret(),
				'Content-Type'      => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['method'] = 'POST';
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			$args['method'] = 'GET';
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['error'] ?? 'Errore Browser Runtime.' ) : 'Risposta Browser Runtime non valida.';
			return new WP_Error( 'prstudio_browser_worker_error', $message, array( 'status' => max( 400, $status ), 'response' => $data ) );
		}
		if ( isset( $data['ok'] ) && false === $data['ok'] ) {
			return new WP_Error( 'prstudio_browser_action_failed', (string) ( $data['error'] ?? 'Azione browser non riuscita.' ), array( 'status' => 400, 'response' => $data ) );
		}
		return $unwrap && array_key_exists( 'result', $data ) ? $data['result'] : $data;
	}

	private function detect_runtime() {
		$status = $this->bootstrap_status();
		$python_candidates = array_filter( array_unique( array(
			(string) ( $status['python'] ?? '' ),
			$this->data_dir() . '/venv/bin/python',
			'/opt/pyvenv/bin/python',
			'/opt/venv/bin/python',
			'/usr/local/bin/python3',
			'/usr/bin/python3',
			(string) getenv( 'PRSTUDIO_BROWSER_PYTHON' ),
		) ) );
		$chromium_candidates = array_filter( array_unique( array(
			(string) ( $status['chromium'] ?? '' ),
			'/usr/bin/chromium',
			'/usr/bin/chromium-browser',
			'/usr/bin/google-chrome-stable',
			'/usr/bin/google-chrome',
			(string) getenv( 'PRSTUDIO_BROWSER_CHROMIUM' ),
		) ) );

		$python = '';
		foreach ( $python_candidates as $candidate ) {
			if ( is_file( $candidate ) && is_executable( $candidate ) && $this->python_has_dependencies( $candidate ) ) {
				$python = $candidate;
				break;
			}
		}
		$chromium = '';
		foreach ( $chromium_candidates as $candidate ) {
			if ( is_file( $candidate ) && is_executable( $candidate ) ) {
				$chromium = $candidate;
				break;
			}
		}

		if ( '' === $python || '' === $chromium ) {
			return new WP_Error(
				'prstudio_browser_runtime_missing',
				'Runtime Playwright già predisposto non rilevato. Il plugin resta inattivo e non modifica il sito.',
				array( 'status' => 503 )
			);
		}
		return array( 'python' => $python, 'chromium' => $chromium );
	}

	private function python_has_dependencies( string $python ): bool {
		$output = array();
		$code = 1;
		$this->run_command( array( $python, '-c', 'import playwright.sync_api; import PIL' ), $output, $code );
		return 0 === $code;
	}

	private function port_is_available_for_runtime( int $port ): bool {
		if ( $this->worker_healthy() ) {
			return true;
		}
		$socket = @stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $errstr );
		if ( $socket ) {
			fclose( $socket );
			return true;
		}
		return false;
	}

	private function find_free_port(): int {
		for ( $port = self::PORT_START; $port <= self::PORT_END; $port++ ) {
			$socket = @stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $errstr );
			if ( $socket ) {
				fclose( $socket );
				return $port;
			}
		}
		return 0;
	}

	private function acquire_start_lock(): bool {
		$this->clear_start_lock_if_stale();
		return add_option( self::START_LOCK, time(), '', false );
	}

	private function release_start_lock(): void {
		delete_option( self::START_LOCK );
	}

	private function clear_start_lock_if_stale(): void {
		$created = absint( get_option( self::START_LOCK, 0 ) );
		if ( $created > 0 && $created < time() - self::START_LOCK_TTL ) {
			delete_option( self::START_LOCK );
		}
	}

	/**
	 * Start an internally constructed argv command without invoking a shell.
	 *
	 * @param array<int,string> $command Fixed executable + arguments.
	 */
	private function spawn_detached( array $command, string $log ): int {
		if ( ! $this->valid_process_command( $command ) || ! $this->proc_open_available() ) {
			return 0;
		}
		$descriptors = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'file', $log, 'a' ),
			2 => array( 'file', $log, 'a' ),
		);
		$process = @proc_open( $command, $descriptors, $pipes, null, null, array( 'bypass_shell' => true, 'suppress_errors' => true ) );
		if ( ! is_resource( $process ) ) {
			return 0;
		}
		$status = proc_get_status( $process );
		$pid = ! empty( $status['pid'] ) ? absint( $status['pid'] ) : 0;
		// Intentionally release the parent-side handle without waiting for the long-lived worker.
		unset( $process );
		return $pid;
	}

	/**
	 * Execute a short, internally constructed argv command without a shell.
	 *
	 * @param array<int,string> $command Fixed executable + arguments.
	 */
	private function run_command( array $command, array &$output, int &$code ): bool {
		$output = array();
		$code = 127;
		if ( ! $this->valid_process_command( $command ) || ! $this->proc_open_available() ) {
			return false;
		}
		$descriptors = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( $command, $descriptors, $pipes, null, null, array( 'bypass_shell' => true, 'suppress_errors' => true ) );
		if ( ! is_resource( $process ) ) {
			return false;
		}
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		$combined = trim( (string) $stdout . ( '' !== trim( (string) $stderr ) ? "\n" . (string) $stderr : '' ) );
		$output = '' === $combined ? array() : ( preg_split( '/\R/', $combined ) ?: array() );
		return true;
	}

	/** @param array<int,string> $command */
	private function valid_process_command( array $command ): bool {
		if ( empty( $command ) || ! is_string( $command[0] ) || ! is_file( $command[0] ) || ! is_executable( $command[0] ) ) {
			return false;
		}
		foreach ( $command as $argument ) {
			if ( ! is_string( $argument ) || false !== strpos( $argument, "\0" ) ) {
				return false;
			}
		}
		return true;
	}

	private function proc_open_available(): bool {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true );
	}

	private function process_functions_available(): bool {
		return $this->proc_open_available();
	}

	private function runtime_diagnostics(): array {
		$status = $this->bootstrap_status();
		$settings = $this->settings();
		return array(
			'bootstrap_status' => sanitize_key( (string) ( $status['status'] ?? 'missing' ) ),
			'python' => (string) ( $settings['python'] ?? $status['python'] ?? '' ),
			'chromium' => (string) ( $settings['chromium'] ?? $status['chromium'] ?? '' ),
			'worker_port' => absint( $settings['port'] ?? 0 ),
			'worker_pid' => absint( $settings['pid'] ?? 0 ),
			'worker_healthy' => $this->worker_healthy(),
			'process_functions_available' => $this->process_functions_available(),
			'data_dir_exists' => is_dir( $this->data_dir() ),
			'plugin_worker_exists' => is_file( PRSTUDIO_BROWSER_RUNTIME_DIR . 'runtime/worker.py' ),
		);
	}

	private function bootstrap_status(): array {
		$path = $this->data_dir() . '/runtime-status.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$value = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $value ) ? $value : array();
	}

	private function ensure_directories(): void {
		foreach ( array( $this->data_dir(), $this->data_dir() . '/artifacts', $this->data_dir() . '/profiles', $this->data_dir() . '/logs' ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				wp_mkdir_p( $directory );
			}
		}
		$deny = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		@file_put_contents( $this->data_dir() . '/.htaccess', $deny );
		@file_put_contents( $this->data_dir() . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	private function data_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-browser-runtime-data';
	}

	private function ensure_settings(): void {
		$settings = $this->settings();
		if ( empty( $settings['secret'] ) || strlen( (string) $settings['secret'] ) < 48 ) {
			$settings['secret'] = bin2hex( random_bytes( 32 ) );
		}
		if ( empty( $settings['port'] ) ) {
			$settings['port'] = self::PORT_START;
		}
		$settings['version'] = PRSTUDIO_BROWSER_RUNTIME_VERSION;
		$this->save_settings( $settings );
	}

	private function settings(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	private function save_settings( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	private function secret(): string {
		return (string) ( $this->settings()['secret'] ?? '' );
	}

	private function set_error( string $message ): void {
		$settings = $this->settings();
		$settings['last_error'] = sanitize_text_field( $message );
		$settings['last_check_gmt'] = gmdate( 'c' );
		$this->save_settings( $settings );
	}

	private function last_error(): string {
		return (string) ( $this->settings()['last_error'] ?? '' );
	}

	public function admin_menu(): void {
		add_management_page(
			'PR STUDIO Browser Runtime',
			'Browser Runtime',
			'manage_options',
			'prstudio-browser-runtime',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings();
		$status = $this->bootstrap_status();
		echo '<div class="wrap"><h1>PR STUDIO Browser Runtime</h1>';
		echo '<p><strong>Modalità:</strong> ONE-GUARD; avvio tramite Ideal Market Master</p>';
		echo '<p><strong>Connettore:</strong> Ideal Market Master</p>';
		echo '<p><strong>Runtime predisposto:</strong> ' . ( 'complete' === (string) ( $status['status'] ?? '' ) ? 'sì' : 'non rilevato' ) . '</p>';
		echo '<p><strong>Worker configurato:</strong> 127.0.0.1:' . esc_html( (string) ( $settings['port'] ?? '' ) ) . '</p>';
		echo '<p>Questa pagina non avvia processi e non esegue controlli browser.</p></div>';
	}

	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=prstudio-browser-runtime' ) ) . '">Stato</a>' );
		return $links;
	}
}
