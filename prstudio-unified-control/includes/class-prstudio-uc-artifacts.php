<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_UC_Artifacts {
	private const MAX_BYTES = 12582912;
	private const TTL = 3600;
	private const KEEP_PER_DEVICE = 20;
	private const MIN_FREE_BYTES = 67108864; // keep 64 MiB headroom before accepting a new capture.

	public static function root(): string { return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-unified-private/screenshots'; }

	private static function ensure(): bool {
		$dir = self::root();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
		if ( ! is_dir( $dir ) ) { return false; }
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX );
		@file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", LOCK_EX );
		return is_writable( $dir );
	}

	private static function device_key( string $device_uuid ): string { return substr( hash( 'sha256', $device_uuid ), 0, 32 ); }
	private static function artifact_id(): string { return bin2hex( random_bytes( 16 ) ); }
	private static function meta_path_id( string $id ): string { return trailingslashit( self::root() ) . $id . '.json'; }
	private static function extension_for_mime( string $mime ): string {
		return match ( strtolower( $mime ) ) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/webp' => 'webp',
			default => 'png',
		};
	}
	private static function mime_for_extension( string $ext ): string {
		return match ( strtolower( $ext ) ) {
			'jpg', 'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			default => 'image/png',
		};
	}
	private static function image_path_id( string $id, string $mime = 'image/png' ): string {
		return trailingslashit( self::root() ) . $id . '.' . self::extension_for_mime( $mime );
	}
	private static function image_path_from_meta( string $id, ?array $meta = null ): string {
		if ( is_array( $meta ) ) {
			$filename = basename( (string) ( $meta['filename'] ?? '' ) );
			if ( preg_match( '/^' . preg_quote( $id, '/' ) . '\.(png|jpe?g|webp)$/i', $filename ) ) {
				return trailingslashit( self::root() ) . $filename;
			}
			$mime = (string) ( $meta['mime_type'] ?? '' );
			if ( '' !== $mime ) {
				$path = self::image_path_id( $id, $mime );
				if ( is_file( $path ) ) { return $path; }
			}
		}
		foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
			$path = trailingslashit( self::root() ) . $id . '.' . $ext;
			if ( is_file( $path ) ) { return $path; }
		}
		return self::image_path_id( $id, 'image/png' );
	}
	private static function read_meta( string $id ): ?array {
		$path = self::meta_path_id( $id );
		if ( ! is_file( $path ) ) { return null; }
		$meta = json_decode( (string) @file_get_contents( $path ), true );
		return is_array( $meta ) ? $meta : null;
	}
	private static function unlink_artifact( string $id, ?array $meta = null ): bool {
		$deleted = false;
		$image = self::image_path_from_meta( $id, $meta );
		foreach ( array_unique( array( $image, self::meta_path_id( $id ) ) ) as $path ) {
			if ( is_file( $path ) && @unlink( $path ) ) { $deleted = true; }
		}
		return $deleted;
	}

	public static function status(): array {
		$ready = self::ensure();
		$root = self::root();
		$free = $ready ? @disk_free_space( $root ) : false;
		$total = $ready ? @disk_total_space( $root ) : false;
		$count = 0;
		$bytes = 0;
		if ( $ready ) {
			foreach ( glob( trailingslashit( $root ) . '*.json' ) ?: array() as $meta_path ) {
				$meta = json_decode( (string) @file_get_contents( $meta_path ), true );
				if ( ! is_array( $meta ) || ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $meta['artifact_id'] ?? '' ) ) ) { continue; }
				$count++;
				$bytes += max( 0, (int) ( $meta['bytes'] ?? 0 ) );
			}
		}
		$headroom_ok = false === $free || $free >= self::MIN_FREE_BYTES;
		return array(
			'ok' => $ready && $headroom_ok,
			'writable' => $ready,
			'headroom_ok' => $headroom_ok,
			'free_bytes' => false === $free ? null : (int) $free,
			'total_bytes' => false === $total ? null : (int) $total,
			'artifact_count' => $count,
			'artifact_bytes' => $bytes,
			'max_artifact_bytes' => self::MAX_BYTES,
			'min_free_bytes' => self::MIN_FREE_BYTES,
			'ttl_seconds' => self::TTL,
			'keep_per_device' => self::KEEP_PER_DEVICE,
			'accepted_mime_types' => array( 'image/png', 'image/jpeg', 'image/webp' ),
			'storage' => 'filesystem_private',
		);
	}

	private static function prune_device( string $device_uuid, int $keep = self::KEEP_PER_DEVICE ): void {
		$rows = array();
		foreach ( glob( trailingslashit( self::root() ) . '*.json' ) ?: array() as $meta_path ) {
			$meta = json_decode( (string) @file_get_contents( $meta_path ), true );
			if ( ! is_array( $meta ) || (string) ( $meta['device_uuid'] ?? '' ) !== $device_uuid ) { continue; }
			$id = (string) ( $meta['artifact_id'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) { continue; }
			$rows[] = array( 'id'=>$id, 'ts'=>(int) ( @filemtime( $meta_path ) ?: 0 ), 'meta'=>$meta );
		}
		usort( $rows, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		foreach ( array_slice( $rows, max( 1, $keep ) ) as $row ) { self::unlink_artifact( $row['id'], $row['meta'] ); }
	}

	public static function store( string $device_uuid, string $data_url, array $context = array() ) {
		if ( ! preg_match( '#^data:image/(png|jpe?g|webp);base64,(.+)$#si', $data_url, $m ) ) {
			return new WP_Error( 'prstudio_screenshot_input', 'Screenshot non valido o formato non supportato.', array( 'status'=>400 ) );
		}
		$declared = strtolower( (string) $m[1] );
		$mime = in_array( $declared, array( 'jpeg', 'jpg' ), true ) ? 'image/jpeg' : 'image/' . $declared;
		$raw = base64_decode( $m[2], true );
		if ( false === $raw || 0 === strlen( $raw ) || strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error( 'prstudio_screenshot_size', 'Screenshot troppo grande o non valido.', array( 'status'=>413, 'max_bytes'=>self::MAX_BYTES, 'bytes'=>false === $raw ? 0 : strlen( $raw ) ) );
		}
		$image_info = @getimagesizefromstring( $raw );
		if ( false === $image_info ) { return new WP_Error( 'prstudio_screenshot_decode', 'Il contenuto non è un’immagine valida.', array( 'status'=>400 ) ); }
		$detected = (string) ( $image_info['mime'] ?? '' );
		if ( ! in_array( $detected, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) || $detected !== $mime ) {
			return new WP_Error( 'prstudio_screenshot_mime', 'Il MIME dichiarato non coincide con l’immagine.', array( 'status'=>400, 'declared'=>$mime, 'detected'=>$detected ) );
		}
		$status = self::status();
		if ( empty( $status['writable'] ) ) { return new WP_Error( 'prstudio_screenshot_storage', 'Directory screenshot privata non scrivibile.', array( 'status'=>507, 'storage'=>$status ) ); }
		if ( empty( $status['headroom_ok'] ) ) { return new WP_Error( 'prstudio_screenshot_disk_pressure', 'Spazio disco insufficiente per salvare screenshot in sicurezza.', array( 'status'=>507, 'storage'=>$status ) ); }

		$id = self::artifact_id();
		$path = self::image_path_id( $id, $mime );
		$tmp = $path . '.tmp-' . substr( wp_generate_uuid4(), 0, 8 );
		if ( false === file_put_contents( $tmp, $raw, LOCK_EX ) || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'prstudio_screenshot_write', 'Salvataggio screenshot non riuscito.', array( 'status'=>507, 'storage'=>self::status() ) );
		}
		$meta = array(
			'artifact_id'=>$id,
			'device_uuid'=>$device_uuid,
			'device_fingerprint'=>self::device_key( $device_uuid ),
			'sha256'=>hash( 'sha256', $raw ),
			'bytes'=>strlen( $raw ),
			'mime_type'=>$mime,
			'filename'=>basename( $path ),
			'width'=>(int) ( $image_info[0] ?? 0 ),
			'height'=>(int) ( $image_info[1] ?? 0 ),
			'created_gmt'=>gmdate( 'c' ),
			'task_id'=>sanitize_text_field( (string) ( $context['task_id'] ?? '' ) ),
			'step_index'=>(int) ( $context['step_index'] ?? 0 ),
			'capture_mode'=>sanitize_key( (string) ( $context['capture_mode'] ?? '' ) ),
			'full_page'=>! empty( $context['full_page'] ),
			'full_page_complete'=>isset( $context['full_page_complete'] ) ? (bool) $context['full_page_complete'] : null,
		);
		if ( false === @file_put_contents( self::meta_path_id( $id ), wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			@unlink( $path );
			return new WP_Error( 'prstudio_screenshot_meta', 'Metadati screenshot non salvati.', array( 'status'=>507 ) );
		}
		self::cleanup();
		self::prune_device( $device_uuid, self::KEEP_PER_DEVICE );
		$meta['url'] = self::signed_url( $meta['artifact_id'] );
		$meta['expires_gmt'] = gmdate( 'c', time() + self::TTL );
		return $meta;
	}

	public static function delete_current( string $device_uuid ): array {
		self::ensure();
		$deleted = false; $count = 0;
		foreach ( glob( trailingslashit( self::root() ) . '*.json' ) ?: array() as $meta_path ) {
			$meta = json_decode( (string) @file_get_contents( $meta_path ), true );
			if ( ! is_array( $meta ) || (string) ( $meta['device_uuid'] ?? '' ) !== $device_uuid ) { continue; }
			$id = (string) ( $meta['artifact_id'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) { continue; }
			if ( self::unlink_artifact( $id, $meta ) ) { $deleted = true; }
			$count++;
		}
		return array( 'deleted'=>$deleted, 'deleted_artifacts'=>$count, 'device_id'=>$device_uuid );
	}

	public static function cleanup(): int {
		if ( ! self::ensure() ) { return 0; }
		$count = 0; $cut = time() - self::TTL;
		$known = array();
		foreach ( glob( trailingslashit( self::root() ) . '*.json' ) ?: array() as $meta_path ) {
			$meta = json_decode( (string) @file_get_contents( $meta_path ), true );
			$id = is_array( $meta ) ? (string) ( $meta['artifact_id'] ?? '' ) : '';
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) { if ( @filemtime( $meta_path ) < $cut ) { @unlink( $meta_path ); } continue; }
			$known[$id] = true;
			$mtime = (int) ( @filemtime( $meta_path ) ?: 0 );
			if ( $mtime < $cut ) { self::unlink_artifact( $id, $meta ); $count++; }
		}
		foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
			foreach ( glob( trailingslashit( self::root() ) . '*.' . $ext ) ?: array() as $path ) {
				$id = pathinfo( $path, PATHINFO_FILENAME );
				if ( isset( $known[$id] ) || @filemtime( $path ) >= $cut ) { continue; }
				if ( @unlink( $path ) ) { $count++; }
			}
		}
		return $count;
	}

	private static function signed_url( string $id ): string {
		$expires = time() + self::TTL;
		$sig = hash_hmac( 'sha256', $id . '|' . $expires, PRSTUDIO_UC_Auth::signing_secret() );
		return add_query_arg( array( 'expires'=>$expires, 'sig'=>$sig ), rest_url( 'prstudio-unified/v1/artifact/screenshot/' . $id ) );
	}

	public static function read_for_mcp( string $artifact_id, int $max_bytes = 8388608 ) {
		$id = strtolower( trim( $artifact_id ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) { return new WP_Error( 'prstudio_artifact_id', 'Screenshot artifact id non valido.', array( 'status'=>400 ) ); }
		$max_bytes = max( 1, min( self::MAX_BYTES, $max_bytes ) );
		$meta = self::read_meta( $id );
		$path = self::image_path_from_meta( $id, $meta );
		$meta_path = self::meta_path_id( $id );
		if ( ! is_file( $path ) || ! is_file( $meta_path ) || ! is_array( $meta ) ) { return new WP_Error( 'prstudio_artifact_missing', 'Screenshot non trovato.', array( 'status'=>404 ) ); }
		$mtime = @filemtime( $path );
		if ( false === $mtime || $mtime < time() - self::TTL - 60 ) { return new WP_Error( 'prstudio_artifact_expired', 'Screenshot scaduto.', array( 'status'=>410 ) ); }
		$size = @filesize( $path );
		if ( false === $size || $size < 1 || $size > $max_bytes ) { return new WP_Error( 'prstudio_artifact_mcp_size', 'Screenshot oltre il budget MCP.', array( 'status'=>413, 'bytes'=>(int) $size, 'max_bytes'=>$max_bytes ) ); }
		$raw = @file_get_contents( $path );
		if ( false === $raw || strlen( $raw ) !== (int) $size || false === @getimagesizefromstring( $raw ) ) { return new WP_Error( 'prstudio_artifact_mcp_read', 'Screenshot non leggibile o non valido.', array( 'status'=>500 ) ); }
		if ( ! hash_equals( (string) ( $meta['sha256'] ?? '' ), hash( 'sha256', $raw ) ) ) { return new WP_Error( 'prstudio_artifact_integrity', 'Integrità screenshot non verificata.', array( 'status'=>409 ) ); }
		$mime = (string) ( $meta['mime_type'] ?? self::mime_for_extension( pathinfo( $path, PATHINFO_EXTENSION ) ) );
		return array( 'artifact_id'=>$id, 'mime_type'=>$mime, 'bytes'=>(int) $size, 'raw'=>$raw );
	}

	public static function serve( WP_REST_Request $request ) {
		$id = strtolower( sanitize_key( (string) $request['id'] ) );
		$expires = absint( $request->get_param( 'expires' ) );
		$sig = sanitize_text_field( (string) $request->get_param( 'sig' ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || $expires < time() || $expires > time() + self::TTL + 60 || ! hash_equals( hash_hmac( 'sha256', $id . '|' . $expires, PRSTUDIO_UC_Auth::signing_secret() ), $sig ) ) {
			return new WP_Error( 'prstudio_artifact_forbidden', 'Collegamento screenshot scaduto o non valido.', array( 'status'=>403 ) );
		}
		$meta = self::read_meta( $id );
		$path = self::image_path_from_meta( $id, $meta );
		if ( ! is_file( $path ) ) { return new WP_Error( 'prstudio_artifact_missing', 'Screenshot non trovato.', array( 'status'=>404 ) ); }
		$mime = is_array( $meta ) ? (string) ( $meta['mime_type'] ?? '' ) : '';
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) { $mime = self::mime_for_extension( pathinfo( $path, PATHINFO_EXTENSION ) ); }
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="prstudio-screenshot.' . self::extension_for_mime( $mime ) . '"' );
		readfile( $path );
		exit;
	}
}
