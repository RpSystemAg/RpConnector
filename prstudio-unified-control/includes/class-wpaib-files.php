<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Files {
	private const MAX_SEARCH_BYTES = 8388608;
	private const TEXT_EXTENSIONS = array( 'php','css','scss','sass','less','js','mjs','cjs','json','xml','html','htm','txt','md','yml','yaml','ini','conf','htaccess','po','pot','mo','sql','csv','ts','tsx','jsx','svg' );

	public static function backup_root(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'wp-ai-bridge-private';
	}

	public static function ensure_backup_directory(): void {
		$root = self::backup_root();
		if ( ! is_dir( $root ) ) { wp_mkdir_p( $root ); }
		if ( is_dir( $root ) ) {
			$index = trailingslashit( $root ) . 'index.php';
			if ( ! file_exists( $index ) ) { @file_put_contents( $index, "<?php\n// Silence is golden.\n" ); }
			$deny = trailingslashit( $root ) . '.htaccess';
			if ( ! file_exists( $deny ) ) { @file_put_contents( $deny, "Deny from all\n" ); }
		}
	}

	private static function settings(): array { return WPAIB_Auth::settings(); }

	private static function relative( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		$parts = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) { continue; }
			if ( '..' === $part || false !== strpos( $part, "\0" ) ) {
				throw new InvalidArgumentException( 'Percorso non valido.' );
			}
			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	private static function absolute( string $path, bool $must_exist = true ) {
		try {
			$relative = self::relative( $path );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'wpaib_path_invalid',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		}

		$base = wp_normalize_path( ABSPATH );
		$full = wp_normalize_path( trailingslashit( ABSPATH ) . $relative );

		if (
			0 !== strpos( trailingslashit( $full ), trailingslashit( $base ) )
			&& $full !== untrailingslashit( $base )
		) {
			return new WP_Error(
				'wpaib_path_outside_root',
				'Il percorso è fuori dalla webroot.',
				array( 'status' => 403 )
			);
		}

		if ( $must_exist && ! file_exists( $full ) ) {
			return new WP_Error(
				'wpaib_file_missing',
				'File o cartella non trovato.',
				array( 'status' => 404 )
			);
		}

		if ( file_exists( $full ) && is_link( $full ) ) {
			return new WP_Error(
				'wpaib_symlink_forbidden',
				'I collegamenti simbolici non sono consentiti.',
				array( 'status' => 403 )
			);
		}

		return array(
			'relative' => $relative,
			'absolute' => $full,
		);
	}

	private static function sensitive_path( string $relative ): bool {
		$relative = strtolower( str_replace( '\\', '/', $relative ) );
		$basename = basename( $relative );

		if (
			in_array(
				$basename,
				array( 'wp-config.php', '.env', '.env.local', '.user.ini', 'php.ini' ),
				true
			)
		) {
			return true;
		}

		if ( preg_match( '#(?:^|/)(?:private|secrets?|credentials?|keys?)(?:/|$)#i', $relative ) ) {
			return true;
		}

		if (
			0 === strpos(
				$relative,
				strtolower(
					ltrim(
						str_replace( ABSPATH, '', self::backup_root() ),
						'/'
					)
				)
			)
		) {
			return true;
		}

		return false;
	}

	private static function writable_path( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );

		if ( self::sensitive_path( $relative ) ) {
			return false;
		}

		/* Unified 0.3 never creates or edits must-use plugins. */
		if ( 0 === strpos( strtolower( $relative ), 'wp-content/mu-plugins/' ) ) {
			return false;
		}

		return true;
	}

	private static function redact( string $content ): string {
		$patterns = array(
			'/(define\s*\(\s*[\'\"](?:DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)[\'\"]\s*,\s*)[\'\"][^\'\"]*[\'\"](\s*\))/i' => '$1\'[REDACTED]\'$2',
			'/((?:password|passwd|secret|token|api[_-]?key|client[_-]?secret)\s*[=:]\s*)[^\s\r\n,;]+/i' => '$1[REDACTED]',
			'/\b(?:sk|pk|wpaib_(?:at|rt|pk|ac))_[A-Za-z0-9._-]{16,}\b/' => '[REDACTED]',
		);

		return (string) preg_replace(
			array_keys( $patterns ),
			array_values( $patterns ),
			$content
		);
	}

	public static function manifest(
		string $path = '',
		int $cursor = 0,
		int $limit = 300,
		bool $hashes = false
	) {
		$resolved = self::absolute( $path, true );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! is_dir( $resolved['absolute'] ) ) {
			return new WP_Error(
				'wpaib_not_directory',
				'Il percorso non è una cartella.',
				array( 'status' => 400 )
			);
		}

		$limit  = max( 1, min( 1000, $limit ) );
		$cursor = max( 0, $cursor );
		$items  = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$resolved['absolute'],
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$index = 0;
		$next  = null;

		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) { continue; }
			if ( $index++ < $cursor ) { continue; }

			if ( count( $items ) >= $limit ) {
				$next = $index - 1;
				break;
			}

			$abs = wp_normalize_path( $file->getPathname() );
			$rel = ltrim(
				str_replace( wp_normalize_path( ABSPATH ), '', $abs ),
				'/'
			);

			if ( self::sensitive_path( $rel ) ) { continue; }

			$item = array(
				'path'      => $rel,
				'type'      => $file->isDir() ? 'directory' : 'file',
				'size'      => $file->isFile() ? $file->getSize() : null,
				'modified'  => gmdate( DATE_ATOM, $file->getMTime() ),
				'readable'  => $file->isReadable(),
				'writable'  => $file->isWritable(),
				'sensitive' => false,
			);

			if (
				$hashes
				&& $file->isFile()
				&& $file->getSize() <= 32 * 1024 * 1024
			) {
				$item['sha256'] = hash_file( 'sha256', $abs );
			}

			$items[] = $item;
		}

		return array(
			'root'        => $resolved['relative'],
			'cursor'      => $cursor,
			'next_cursor' => $next,
			'truncated'   => null !== $next,
			'entries'     => $items,
		);
	}

	public static function list_directory( string $path = '' ) {
		$resolved = self::absolute( $path, true );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! is_dir( $resolved['absolute'] ) ) {
			return new WP_Error(
				'wpaib_not_directory',
				'Il percorso non è una cartella.',
				array( 'status' => 400 )
			);
		}

		$items = array();

		foreach ( new DirectoryIterator( $resolved['absolute'] ) as $file ) {
			if ( $file->isDot() || $file->isLink() ) { continue; }

			$rel = ltrim(
				str_replace(
					wp_normalize_path( ABSPATH ),
					'',
					wp_normalize_path( $file->getPathname() )
				),
				'/'
			);

			if ( self::sensitive_path( $rel ) ) { continue; }

			$items[] = array(
				'name'     => $file->getFilename(),
				'path'     => $rel,
				'type'     => $file->isDir() ? 'directory' : 'file',
				'size'     => $file->isFile() ? $file->getSize() : null,
				'modified' => gmdate( DATE_ATOM, $file->getMTime() ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $a['type'] === $b['type']
					? strcasecmp( $a['name'], $b['name'] )
					: ( 'directory' === $a['type'] ? -1 : 1 );
			}
		);

		return array(
			'path'  => $resolved['relative'],
			'items' => $items,
			'count' => count( $items ),
		);
	}

	public static function read_file(
		string $path,
		int $offset = 0,
		?int $length = null
	) {
		$resolved = self::absolute( $path, true );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if (
			! is_file( $resolved['absolute'] )
			|| ! is_readable( $resolved['absolute'] )
		) {
			return new WP_Error(
				'wpaib_file_unreadable',
				'File non leggibile.',
				array( 'status' => 403 )
			);
		}

		if ( self::sensitive_path( $resolved['relative'] ) ) {
			return new WP_Error(
				'wpaib_sensitive_file',
				'File sensibile non esposto.',
				array( 'status' => 403 )
			);
		}

		$size   = filesize( $resolved['absolute'] );
		$offset = max( 0, min( $offset, $size ) );
		$length = null === $length
			? min( 1048576, $size - $offset )
			: max( 1, min( 1048576, $length ) );

		$handle = fopen( $resolved['absolute'], 'rb' );

		if ( ! $handle ) {
			return new WP_Error(
				'wpaib_read_failed',
				'Impossibile aprire il file.',
				array( 'status' => 500 )
			);
		}

		fseek( $handle, $offset );
		$raw = (string) fread( $handle, $length );
		fclose( $handle );

		$is_utf8 = function_exists( 'mb_check_encoding' )
			? mb_check_encoding( $raw, 'UTF-8' )
			: (bool) preg_match( '//u', $raw );

		$content = $is_utf8 ? self::redact( $raw ) : base64_encode( $raw );

		return array(
			'path'     => $resolved['relative'],
			'size'     => $size,
			'offset'   => $offset,
			'bytes'    => strlen( $raw ),
			'complete' => $offset + strlen( $raw ) >= $size,
			'sha256'   => hash_file( 'sha256', $resolved['absolute'] ),
			'modified' => gmdate( DATE_ATOM, filemtime( $resolved['absolute'] ) ),
			'encoding' => $is_utf8 ? 'utf-8' : 'base64',
			'redacted' => $is_utf8 && $content !== $raw,
			'content'  => $content,
		);
	}

	public static function search(
		string $query,
		string $path = '',
		array $extensions = array(),
		int $cursor = 0,
		int $max_results = 100
	) {
		$query = trim( $query );

		if ( '' === $query ) {
			return new WP_Error(
				'wpaib_search_empty',
				'Testo da cercare obbligatorio.',
				array( 'status' => 400 )
			);
		}

		$resolved = self::absolute( $path, true );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$extensions = array_values(
			array_filter(
				array_map( 'sanitize_key', $extensions )
			)
		);

		$cursor      = max( 0, $cursor );
		$max_results = max( 1, min( 300, $max_results ) );
		$matches     = array();
		$file_index  = 0;
		$next        = null;

		$iterator = is_dir( $resolved['absolute'] )
			? new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$resolved['absolute'],
					FilesystemIterator::SKIP_DOTS
				)
			)
			: array( new SplFileInfo( $resolved['absolute'] ) );

		foreach ( $iterator as $file ) {
			if (
				! $file->isFile()
				|| $file->isLink()
				|| $file_index++ < $cursor
			) {
				continue;
			}

			$ext = strtolower(
				pathinfo( $file->getFilename(), PATHINFO_EXTENSION )
			);

			if ( $extensions && ! in_array( $ext, $extensions, true ) ) {
				continue;
			}

			if (
				! $extensions
				&& ! in_array( $ext, self::TEXT_EXTENSIONS, true )
			) {
				continue;
			}

			if ( $file->getSize() > self::MAX_SEARCH_BYTES ) {
				continue;
			}

			$rel = ltrim(
				str_replace(
					wp_normalize_path( ABSPATH ),
					'',
					wp_normalize_path( $file->getPathname() )
				),
				'/'
			);

			if ( self::sensitive_path( $rel ) ) { continue; }

			$handle = @fopen( $file->getPathname(), 'rb' );

			if ( ! $handle ) { continue; }

			$line = 0;

			while ( false !== ( $text = fgets( $handle ) ) ) {
				++$line;

				if ( false === stripos( $text, $query ) ) {
					continue;
				}

				$matches[] = array(
					'path'    => $rel,
					'line'    => $line,
					'excerpt' => trim(
						self::redact(
							function_exists( 'mb_substr' )
								? mb_substr( $text, 0, 500 )
								: substr( $text, 0, 500 )
						)
					),
				);

				if ( count( $matches ) >= $max_results ) {
					$next = $file_index - 1;
					break 2;
				}
			}

			fclose( $handle );
		}

		return array(
			'query'       => $query,
			'matches'     => $matches,
			'next_cursor' => $next,
		);
	}

	private static function create_backup(
		string $relative,
		string $absolute
	): array {
		/*
		 * Backup policy 0.3: capture the original only once in the active
		 * work journal. A single consolidated backup is produced when the
		 * prompt is finalized; no directory/archive is created per mutation.
		 */
		if ( class_exists( 'PRSTUDIO_UC_Work_Session' ) ) {
			return PRSTUDIO_UC_Work_Session::capture_original( $relative, $absolute );
		}
		return array(
			'backup_id' => 'journal-unavailable',
			'path' => $relative,
			'existed' => file_exists( $absolute ),
			'sha256' => file_exists( $absolute ) ? hash_file( 'sha256', $absolute ) : null,
		);
	}

	public static function write_file(
		string $path,
		string $content_b64,
		?string $expected_sha256 = null
	) {
		$content = base64_decode( $content_b64, true );
		if ( false === $content ) {
			return new WP_Error( 'wpaib_base64_invalid', 'Contenuto Base64 non valido.', array( 'status' => 400 ) );
		}
		return self::write_raw( $path, $content, $expected_sha256 );
	}

	/** Internal binary-native path. Avoid Base64 when bytes are already resident. */
	public static function write_raw( string $path, string $content, ?string $expected_sha256 = null ) {
		$settings = self::settings();
		$resolved = self::absolute( $path, false );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( ! self::writable_path( $resolved['relative'] ) ) {
			return new WP_Error( 'wpaib_path_not_writable', 'Il percorso non è scrivibile; i mu-plugin sono esclusi dalla suite Unified.', array( 'status' => 403 ) );
		}

		$max = max(
			1024,
			min(
				32 * 1024 * 1024,
				(int) ( $settings['max_file_bytes'] ?? 8388608 )
			)
		);

		if ( strlen( $content ) > $max ) {
			return new WP_Error(
				'wpaib_file_too_large',
				'Il file supera il limite configurato.',
				array( 'status' => 413 )
			);
		}

		$exists       = file_exists( $resolved['absolute'] );
		$before_hash  = $exists
			? hash_file( 'sha256', $resolved['absolute'] )
			: null;
		$before_perms = $exists
			? ( fileperms( $resolved['absolute'] ) & 0777 )
			: 0644;

		if ( $exists && null === $expected_sha256 ) {
			return new WP_Error(
				'wpaib_expected_hash_required',
				'Per sostituire un file è richiesto expected_sha256.',
				array(
					'status'         => 409,
					'current_sha256' => $before_hash,
				)
			);
		}

		if (
			$exists
			&& ! hash_equals(
				(string) $before_hash,
				(string) $expected_sha256
			)
		) {
			return new WP_Error(
				'wpaib_file_conflict',
				'Il file è cambiato.',
				array(
					'status'         => 409,
					'current_sha256' => $before_hash,
				)
			);
		}

		$backup = self::create_backup(
			$resolved['relative'],
			$resolved['absolute']
		);

		$dir = dirname( $resolved['absolute'] );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'wpaib_directory_create_failed',
				'Impossibile creare la cartella.',
				array( 'status' => 500 )
			);
		}

		/*
		 * wp_tempnam() non è caricato automaticamente nelle richieste
		 * REST/MCP. La funzione è definita in wp-admin/includes/file.php.
		 */
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam(
			basename( $resolved['absolute'] ),
			$dir
		);

		if (
			! $tmp
			|| false === file_put_contents( $tmp, $content, LOCK_EX )
		) {
			return new WP_Error(
				'wpaib_write_failed',
				'Scrittura temporanea non riuscita.',
				array( 'status' => 500 )
			);
		}

		if ( ! @rename( $tmp, $resolved['absolute'] ) ) {
			@unlink( $tmp );

			return new WP_Error(
				'wpaib_atomic_replace_failed',
				'Sostituzione atomica non riuscita.',
				array( 'status' => 500 )
			);
		}

		@chmod( $resolved['absolute'], $before_perms );

		$after_hash = hash_file(
			'sha256',
			$resolved['absolute']
		);

		WPAIB_Audit::log(
			'file.write',
			'success',
			$resolved['relative'],
			array(
				'before_sha256' => $before_hash,
				'after_sha256'  => $after_hash,
				'backup_id'     => $backup['backup_id'],
				'bytes'         => strlen( $content ),
			)
		);

		PRSTUDIO_Report::record_change(
			'Filesystem',
			$resolved['relative'],
			array( 'sha256' => $before_hash ),
			array( 'sha256' => $after_hash ),
			array( 'backup_id' => $backup['backup_id'] )
		);
		if ( class_exists( 'PRSTUDIO_UC_Work_Session' ) ) {
			PRSTUDIO_UC_Work_Session::record_change( 'file_write', $resolved['relative'], array( 'before_sha256'=>$before_hash, 'after_sha256'=>$after_hash, 'bytes'=>strlen($content) ) );
		}

		return array(
			'path'          => $resolved['relative'],
			'created'       => ! $exists,
			'before_sha256' => $before_hash,
			'after_sha256'  => $after_hash,
			'backup_id'     => $backup['backup_id'],
			'bytes'         => strlen( $content ),
		);
	}

	public static function patch_exact(
		string $path,
		string $expected_sha256,
		string $search,
		string $replacement,
		int $expected_replacements = 1,
		string $search_sha256 = '',
		array $health_checks = array()
	) {
		$resolved = self::absolute( $path, true );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( ! self::writable_path( $resolved['relative'] ) || ! is_file( $resolved['absolute'] ) || ! is_readable( $resolved['absolute'] ) ) {
			return new WP_Error( 'wpaib_patch_path_invalid', 'File non consentito o non leggibile.', array( 'status' => 403 ) );
		}
		$current_hash = hash_file( 'sha256', $resolved['absolute'] );
		if ( '' === $expected_sha256 || ! hash_equals( $current_hash, $expected_sha256 ) ) {
			return new WP_Error( 'wpaib_file_conflict', 'Il file è cambiato.', array( 'status' => 409, 'current_sha256' => $current_hash ) );
		}
		if ( '' === $search ) { return new WP_Error( 'wpaib_patch_search_required', 'Testo esatto da sostituire obbligatorio.', array( 'status' => 400 ) ); }
		if ( $search_sha256 && ! hash_equals( strtolower( $search_sha256 ), hash( 'sha256', $search ) ) ) {
			return new WP_Error( 'wpaib_patch_search_hash_mismatch', 'Hash del testo di ricerca non valido.', array( 'status' => 400 ) );
		}
		$raw = file_get_contents( $resolved['absolute'] );
		if ( false === $raw ) { return new WP_Error( 'wpaib_patch_read_failed', 'Lettura server-side non riuscita.', array( 'status' => 500 ) ); }
		$next = str_replace( $search, $replacement, $raw, $count );
		if ( $count !== max( 1, $expected_replacements ) ) {
			return new WP_Error( 'wpaib_patch_count_mismatch', 'Numero di sostituzioni diverso da quello atteso.', array( 'status' => 409, 'expected' => max( 1, $expected_replacements ), 'actual' => $count ) );
		}

		$validators = array( 'exact_replacements' => $count, 'token_parse' => null, 'php_lint' => null );
		if ( preg_match( '/\.php$/i', $resolved['relative'] ) ) {
			try {
				token_get_all( $next, TOKEN_PARSE );
				$validators['token_parse'] = true;
			} catch ( ParseError $error ) {
				return new WP_Error( 'wpaib_patch_php_invalid', $error->getMessage(), array( 'status' => 400, 'validator' => 'token_parse' ) );
			}
		}

		$result = self::write_raw( $resolved['relative'], $next, $current_hash );
		if ( is_wp_error( $result ) ) { return $result; }

		if ( preg_match( '/\.php$/i', $resolved['relative'] ) ) {
			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			$php_candidates = array_values( array_unique( array_filter( array(
				defined( 'PHP_BINARY' ) ? (string) PHP_BINARY : '',
				defined( 'PHP_BINDIR' ) ? rtrim( (string) PHP_BINDIR, '/\\' ) . '/php' : '',
				'/usr/bin/php',
				'/usr/local/bin/php',
				'/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			) ) ) );
			$php_binary = '';
			foreach ( $php_candidates as $candidate ) {
				$name = strtolower( basename( $candidate ) );
				if ( false !== strpos( $name, 'php-fpm' ) || false !== strpos( $name, 'php-cgi' ) ) { continue; }
				if ( is_file( $candidate ) && is_executable( $candidate ) ) { $php_binary = $candidate; break; }
			}
			if ( function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true ) && '' !== $php_binary ) {
				$descriptors = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
				$process = @proc_open( array( $php_binary, '-l', $resolved['absolute'] ), $descriptors, $pipes, null, null, array( 'bypass_shell' => true, 'suppress_errors' => true ) );
				if ( is_resource( $process ) ) {
					$lint_output = trim( (string) stream_get_contents( $pipes[1] ) . "\n" . (string) stream_get_contents( $pipes[2] ) );
					fclose( $pipes[1] );
					fclose( $pipes[2] );
					$exit_code = proc_close( $process );
					$validators['php_lint'] = 0 === $exit_code;
					$validators['php_lint_binary'] = $php_binary;
					if ( 0 !== $exit_code ) {
						$rollback = self::restore( (string) $result['backup_id'], (string) $result['after_sha256'] );
						return new WP_Error( 'wpaib_patch_php_lint_failed', 'php -l non superato; rollback eseguito.', array( 'status' => 500, 'lint_output' => implode( "\n", array_slice( preg_split( '/\R/', $lint_output ) ?: array(), 0, 20 ) ), 'rollback' => $rollback ) );
					}
				} else {
					$validators['php_lint'] = 'skipped_proc_open_failed';
				}
			} else {
				$validators['php_lint'] = 'skipped_no_cli';
			}
		}

		if ( ! $health_checks && preg_match( '/\.php$/i', $resolved['relative'] ) ) {
			$health_checks = array( 'home', 'wp_admin', 'mcp' );
		}
		$health = array();
		$failed = false;
		foreach ( array_values( array_unique( $health_checks ) ) as $check ) {
			$check = sanitize_key( (string) $check );
			if ( 'mcp' === $check ) {
				$response = wp_safe_remote_post(
					rest_url( 'wp-ai-bridge/v1/mcp' ),
					array(
						'timeout' => 20,
						'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
						'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 'patch-health', 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25', 'capabilities' => array(), 'clientInfo' => array( 'name' => 'bridge-self-test', 'version' => WPAIB_VERSION ) ) ) ),
					)
				);
				$url = rest_url( 'wp-ai-bridge/v1/mcp' );
			} else {
				$url = 'wp_admin' === $check ? admin_url( '/' ) : home_url( '/' );
				$response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3 ) );
			}
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$health[] = array( 'check' => $check, 'url' => $url, 'status' => $status, 'ok' => $status > 0 && $status < 500, 'warning' => is_wp_error( $response ) ? $response->get_error_message() : null );
			if ( $status >= 500 ) { $failed = true; }
		}
		if ( $failed ) {
			$rollback = self::restore( (string) $result['backup_id'], (string) $result['after_sha256'] );
			return new WP_Error( 'wpaib_patch_health_failed', 'Verifica HTTP non superata; rollback eseguito.', array( 'status' => 500, 'health' => $health, 'rollback' => $rollback ) );
		}

		$result['patch'] = array(
			'server_side_raw' => true,
			'redacted_content_rewritten' => false,
			'search_sha256' => hash( 'sha256', $search ),
			'validators' => $validators,
			'health_checks' => $health,
			'rollback_on_failure' => true,
		);
		return $result;
	}

	public static function append_file(
		string $path,
		string $suffix,
		string $expected_sha256 = ''
	) {
		$resolved = self::absolute( $path, true );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( ! self::writable_path( $resolved['relative'] ) || ! is_file( $resolved['absolute'] ) || ! is_readable( $resolved['absolute'] ) ) {
			return new WP_Error( 'wpaib_append_path_invalid', 'File non consentito o non leggibile.', array( 'status' => 403 ) );
		}

		$current_hash = hash_file( 'sha256', $resolved['absolute'] );
		if ( '' !== $expected_sha256 && ! hash_equals( $current_hash, $expected_sha256 ) ) {
			return new WP_Error( 'wpaib_file_conflict', 'Il file è cambiato.', array( 'status' => 409, 'current_sha256' => $current_hash ) );
		}

		$raw = file_get_contents( $resolved['absolute'] );
		if ( false === $raw ) {
			return new WP_Error( 'wpaib_append_read_failed', 'Lettura server-side non riuscita.', array( 'status' => 500 ) );
		}

		$result = self::write_raw( $resolved['relative'], $raw . $suffix, $current_hash );
		if ( is_wp_error( $result ) ) { return $result; }
		$result['mutation'] = array(
			'type'                       => 'append',
			'server_side_raw'            => true,
			'redacted_content_rewritten' => false,
			'appended_bytes'             => strlen( $suffix ),
		);
		return $result;
	}

	public static function truncate_file(
		string $path,
		string $expected_sha256 = ''
	) {
		$resolved = self::absolute( $path, true );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( ! self::writable_path( $resolved['relative'] ) || ! is_file( $resolved['absolute'] ) ) {
			return new WP_Error( 'wpaib_truncate_path_invalid', 'File non consentito.', array( 'status' => 403 ) );
		}

		$current_hash = hash_file( 'sha256', $resolved['absolute'] );
		if ( '' !== $expected_sha256 && ! hash_equals( $current_hash, $expected_sha256 ) ) {
			return new WP_Error( 'wpaib_file_conflict', 'Il file è cambiato.', array( 'status' => 409, 'current_sha256' => $current_hash ) );
		}

		$result = self::write_raw( $resolved['relative'], '', $current_hash );
		if ( is_wp_error( $result ) ) { return $result; }
		$result['mutation'] = array(
			'type'                       => 'truncate',
			'server_side_raw'            => true,
			'redacted_content_rewritten' => false,
		);
		return $result;
	}

	public static function validate_file(
		string $path,
		string $format = ''
	) {
		$resolved = self::absolute( $path, true );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( ! is_file( $resolved['absolute'] ) || ! is_readable( $resolved['absolute'] ) ) {
			return new WP_Error( 'wpaib_validate_path_invalid', 'File non leggibile.', array( 'status' => 403 ) );
		}
		$raw = file_get_contents( $resolved['absolute'] );
		if ( false === $raw ) {
			return new WP_Error( 'wpaib_validate_read_failed', 'Lettura server-side non riuscita.', array( 'status' => 500 ) );
		}
		$format = strtolower( $format ?: (string) pathinfo( $resolved['relative'], PATHINFO_EXTENSION ) );
		$result = array(
			'path'            => $resolved['relative'],
			'format'          => $format,
			'sha256'          => hash( 'sha256', $raw ),
			'server_side_raw' => true,
			'content_exposed' => false,
			'valid'           => false,
		);
		if ( 'php' === $format ) {
			try {
				token_get_all( $raw, TOKEN_PARSE );
				$result['valid'] = true;
			} catch ( ParseError $error ) {
				$result['error'] = $error->getMessage();
			}
			return $result;
		}
		if ( 'json' === $format ) {
			json_decode( $raw, true );
			$result['valid'] = JSON_ERROR_NONE === json_last_error();
			$result['error'] = $result['valid'] ? null : json_last_error_msg();
			return $result;
		}
		return new WP_Error( 'wpaib_validate_format_unsupported', 'Formato di validazione non supportato.', array( 'status' => 400, 'format' => $format ) );
	}

	public static function delete_file(
		string $path,
		string $expected_sha256
	) {
		$settings = self::settings();


		$resolved = self::absolute( $path, true );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if (
			! is_file( $resolved['absolute'] )
			|| ! self::writable_path( $resolved['relative'] )
		) {
			return new WP_Error(
				'wpaib_delete_path_forbidden',
				'Eliminazione non consentita.',
				array( 'status' => 403 )
			);
		}

		$current = hash_file(
			'sha256',
			$resolved['absolute']
		);

		if ( ! hash_equals( $current, $expected_sha256 ) ) {
			return new WP_Error(
				'wpaib_file_conflict',
				'Il file è cambiato.',
				array(
					'status'         => 409,
					'current_sha256' => $current,
				)
			);
		}

		$backup = self::create_backup(
			$resolved['relative'],
			$resolved['absolute']
		);

		if ( ! unlink( $resolved['absolute'] ) ) {
			return new WP_Error(
				'wpaib_delete_failed',
				'Eliminazione non riuscita.',
				array( 'status' => 500 )
			);
		}

		WPAIB_Audit::log(
			'file.delete',
			'success',
			$resolved['relative'],
			array(
				'before_sha256' => $current,
				'backup_id'     => $backup['backup_id'],
			)
		);

		PRSTUDIO_Report::record_change(
			'Filesystem',
			$resolved['relative'],
			array( 'sha256' => $current ),
			array( 'deleted' => true ),
			array( 'backup_id' => $backup['backup_id'] )
		);
		if ( class_exists( 'PRSTUDIO_UC_Work_Session' ) ) {
			PRSTUDIO_UC_Work_Session::record_change( 'file_delete', $resolved['relative'], array( 'before_sha256'=>$current ) );
		}

		return array(
			'path'      => $resolved['relative'],
			'deleted'   => true,
			'backup_id' => $backup['backup_id'],
		);
	}

	public static function restore(
		string $backup_id,
		?string $expected_current_sha256 = null
	) {
		if ( 0 === strpos( $backup_id, 'pending:' ) && class_exists( 'PRSTUDIO_UC_Work_Session' ) ) {
			return PRSTUDIO_UC_Work_Session::restore_pending( $backup_id, $expected_current_sha256 );
		}
		$backup_id = preg_replace(
			'/[^a-zA-Z0-9_-]/',
			'',
			$backup_id
		);

		$dir = trailingslashit( self::backup_root() ) . $backup_id;
		$meta_file = trailingslashit( $dir ) . 'meta.json';

		if ( ! is_file( $meta_file ) ) {
			return new WP_Error(
				'wpaib_backup_missing',
				'Backup non trovato.',
				array( 'status' => 404 )
			);
		}

		$meta = json_decode(
			(string) file_get_contents( $meta_file ),
			true
		);

		if ( ! is_array( $meta ) || empty( $meta['path'] ) ) {
			return new WP_Error(
				'wpaib_backup_invalid',
				'Metadati backup non validi.',
				array( 'status' => 500 )
			);
		}

		$resolved = self::absolute(
			(string) $meta['path'],
			false
		);

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$current = file_exists( $resolved['absolute'] )
			? hash_file( 'sha256', $resolved['absolute'] )
			: null;

		if (
			null !== $expected_current_sha256
			&& ! hash_equals(
				(string) $current,
				$expected_current_sha256
			)
		) {
			return new WP_Error(
				'wpaib_file_conflict',
				'Il file corrente è cambiato.',
				array(
					'status'         => 409,
					'current_sha256' => $current,
				)
			);
		}

		$pre_restore = self::create_backup(
			$resolved['relative'],
			$resolved['absolute']
		);

		if ( ! empty( $meta['existed'] ) ) {
			$data = trailingslashit( $dir ) . 'data.bin';

			if ( ! is_file( $data ) ) {
				return new WP_Error(
					'wpaib_backup_data_missing',
					'Dati backup mancanti.',
					array( 'status' => 500 )
				);
			}

			if ( ! is_dir( dirname( $resolved['absolute'] ) ) ) {
				wp_mkdir_p( dirname( $resolved['absolute'] ) );
			}

			if ( ! copy( $data, $resolved['absolute'] ) ) {
				return new WP_Error(
					'wpaib_restore_failed',
					'Ripristino non riuscito.',
					array( 'status' => 500 )
				);
			}
		} elseif ( file_exists( $resolved['absolute'] ) ) {
			unlink( $resolved['absolute'] );
		}

		$after = file_exists( $resolved['absolute'] )
			? hash_file( 'sha256', $resolved['absolute'] )
			: null;

		WPAIB_Audit::log(
			'file.restore',
			'success',
			$resolved['relative'],
			array(
				'source_backup_id'      => $backup_id,
				'pre_restore_backup_id' => $pre_restore['backup_id'],
				'before_sha256'         => $current,
				'after_sha256'          => $after,
			)
		);

		PRSTUDIO_Report::record_change(
			'Filesystem rollback',
			$resolved['relative'],
			array( 'sha256' => $current ),
			array( 'sha256' => $after ),
			array( 'source_backup_id' => $backup_id )
		);

		return array(
			'path'                  => $resolved['relative'],
			'restored'              => true,
			'sha256'                => $after,
			'pre_restore_backup_id' => $pre_restore['backup_id'],
		);
	}
}