<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central, bounded and redacted NDJSON logger for the unified suite.
 *
 * Design goals:
 * - one aggregate stream;
 * - one stream per component;
 * - one stream per source file (identified by SHA-256 of the relative path);
 * - no raw file contents, credentials or unbounded payloads;
 * - one current log generation only after work finalization.
 */
final class PRSTUDIO_UC_Log_Orchestrator {
	private const MAX_ENTRY_BYTES = 32768;
	private const MAX_STREAM_BYTES = 8388608;
	private const MAX_DEPTH = 8;
	private const POINTER = 'CURRENT';
	private static bool $root_ready = false;
	private static array $indexed_sources = array();

	public static function root(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-unified-private/logs';
	}

	private static function ensure_root(): void {
		if ( self::$root_ready ) { return; }
		$root = self::root();
		if ( ! is_dir( $root ) ) { wp_mkdir_p( $root ); }
		if ( is_dir( $root ) ) {
			$guards = array(
				'index.php' => "<?php\n// Silence is golden.\n",
				'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",
				'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
			);
			foreach ( $guards as $name => $contents ) {
				$path = $root . '/' . $name;
				$current = is_readable( $path ) ? @file_get_contents( $path ) : false;
				if ( $contents !== $current ) { self::atomic_write( $path, $contents ); }
			}
			self::$root_ready = true;
		}
	}

	private static function generation_id(): string {
		self::ensure_root();
		$pointer = self::root() . '/' . self::POINTER;
		$current = is_readable( $pointer ) ? trim( (string) file_get_contents( $pointer ) ) : '';
		if ( preg_match( '/^g_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8}$/', $current ) ) {
			self::ensure_generation( $current );
			return $current;
		}
		$id = 'g_' . gmdate( 'Ymd\THis\Z' ) . '_' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 8 );
		self::ensure_generation( $id );
		self::atomic_write( $pointer, $id . "\n" );
		return $id;
	}

	private static function ensure_generation( string $id ): string {
		$dir = trailingslashit( self::root() ) . $id;
		foreach ( array( $dir, $dir . '/components', $dir . '/files' ) as $path ) {
			if ( ! is_dir( $path ) ) { wp_mkdir_p( $path ); }
		}
		return $dir;
	}

	private static function atomic_write( string $path, string $data ): bool {
		$tmp = $path . '.tmp-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 8 );
		if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) { return false; }
		if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
		return true;
	}

	private static function safe_name( string $value, string $fallback = 'unknown' ): string {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $value ) );
		$value = trim( $value, '-.' );
		return '' !== $value ? substr( $value, 0, 96 ) : $fallback;
	}

	private static function source_path( string $source_file ): string {
		if ( '' === $source_file ) { return ''; }
		$normalized = str_replace( '\\', '/', $source_file );
		$root = defined( 'PRSTUDIO_UC_DIR' ) ? str_replace( '\\', '/', (string) PRSTUDIO_UC_DIR ) : '';
		if ( '' !== $root && str_starts_with( $normalized, $root ) ) { $normalized = ltrim( substr( $normalized, strlen( $root ) ), '/' ); }
		return substr( $normalized, 0, 512 );
	}

	private static function redact( $value, string $key = '', int $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) { return '[MAX_DEPTH]'; }
		if ( preg_match( '/password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|authorization|cookie|session|oauth|code_verifier|iban|card/i', $key ) ) { return '[REDACTED]'; }
		if ( is_array( $value ) ) {
			$out = array(); $count = 0;
			foreach ( $value as $k => $v ) {
				if ( $count++ >= 200 ) { $out['_truncated_items'] = true; break; }
				$out[ $k ] = self::redact( $v, (string) $k, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) { return self::redact( get_object_vars( $value ), $key, $depth + 1 ); }
		if ( is_string( $value ) ) {
			$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i', 'Bearer [REDACTED]', $value );
			$value = preg_replace( '/([?&](?:access_token|refresh_token|token|api[_-]?key|client_secret|code|code_verifier)=)[^&#\s]*/i', '$1[REDACTED]', $value );
			if ( strlen( $value ) > 12000 ) { $value = substr( $value, 0, 12000 ) . '…'; }
		}
		return $value;
	}

	private static function append( string $path, string $line ): bool {
		if ( strlen( $line ) > self::MAX_ENTRY_BYTES ) {
			$line = wp_json_encode( array( 'gmt'=>gmdate('c'), 'event'=>'log_entry_truncated', 'sha256'=>hash('sha256',$line), 'bytes'=>strlen($line) ), JSON_UNESCAPED_SLASHES ) . "\n";
		}
		$handle = @fopen( $path, 'ab' );
		if ( ! is_resource( $handle ) ) { return false; }
		$ok = false;
		try {
			if ( flock( $handle, LOCK_EX ) ) {
				$size = @filesize( $path );
				if ( is_int( $size ) && $size > self::MAX_STREAM_BYTES ) {
					@ftruncate( $handle, 0 ); @rewind( $handle );
					@fwrite( $handle, wp_json_encode( array( 'gmt'=>gmdate('c'), 'event'=>'stream_reset', 'reason'=>'bounded_size' ) ) . "\n" );
				}
				$ok = false !== @fwrite( $handle, $line ); @fflush( $handle ); flock( $handle, LOCK_UN );
			}
		} finally { @fclose( $handle ); }
		return $ok;
	}

	/**
	 * Keep the source catalogue unique without creating one index write per log.
	 *
	 * The source stream itself is intentionally append-only, but its catalogue is
	 * a set: a source_id maps to exactly one relative path in a generation.  The
	 * old implementation appended that same mapping on every event, so a hot
	 * source grew index.ndjson at the same rate as all three log streams.  The
	 * lock covers the read/check/append sequence across PHP workers; the in-memory
	 * set avoids rereading the catalogue for subsequent events in this request.
	 */
	private static function index_source( string $dir, string $source_id, string $source ): void {
		$key = $dir . '|' . $source_id;
		if ( isset( self::$indexed_sources[ $key ] ) ) { return; }
		$index = $dir . '/files/index.ndjson';
		$lock = @fopen( $dir . '/files/.index.lock', 'c+' );
		if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) { @fclose( $lock ); }
			return;
		}
		try {
			$found = false;
			foreach ( @file( $index, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
				$row = json_decode( (string) $line, true );
				if ( is_array( $row ) && hash_equals( $source_id, (string) ( $row['source_id'] ?? '' ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$line = wp_json_encode( array( 'source_id'=>$source_id, 'source_file'=>$source ), JSON_UNESCAPED_SLASHES ) . "\n";
				if ( ! self::append( $index, $line ) ) { return; }
			}
			self::$indexed_sources[ $key ] = true;
		} finally {
			@flock( $lock, LOCK_UN );
			@fclose( $lock );
		}
	}

	public static function log( string $component, string $event, array $context = array(), string $level = 'info', string $source_file = '' ): void {
		$id = self::generation_id();
		$dir = self::ensure_generation( $id );
		$component = self::safe_name( $component, 'suite' );
		$source = self::source_path( $source_file );
		$source_id = '' !== $source ? hash( 'sha256', $source ) : '';
		$entry = array(
			'gmt' => gmdate( 'c' ), 'generation' => $id, 'suite_version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '',
			'component' => $component, 'level' => self::safe_name( $level, 'info' ), 'event' => substr( sanitize_text_field( $event ), 0, 191 ),
			'source_file' => $source, 'source_id' => $source_id, 'context' => self::redact( $context ),
		);
		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		self::append( $dir . '/orchestrator.ndjson', $line );
		self::append( $dir . '/components/' . $component . '.ndjson', $line );
		if ( '' !== $source_id ) {
			self::append( $dir . '/files/' . $source_id . '.ndjson', $line );
			self::index_source( $dir, $source_id, $source );
		}
	}

	public static function ingest_extension( array $events, string $device_id = '' ): array {
		$accepted = 0;
		foreach ( array_slice( $events, 0, 100 ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			self::log( 'extension', (string) ( $item['type'] ?? 'extension.event' ), array(
				'device_id_hash' => '' !== $device_id ? substr( hash( 'sha256', $device_id ), 0, 16 ) : '',
				'at' => absint( $item['at'] ?? 0 ), 'payload' => $item['payload'] ?? array(),
			), 'info', 'prstudio-unified-browser-agent/service-worker.js' );
			if(class_exists('PRSTUDIO_UC_Memory')){PRSTUDIO_UC_Memory::movement('extension.'.(string)($item['type']??'event'),array('resource'=>(string)($item['payload']['url']??$item['payload']['taskId']??''),'outcome'=>(string)($item['payload']['status']??'observed'),'method'=>'browser_agent','device_id_hash'=>''!==$device_id?substr(hash('sha256',$device_id),0,16):''));}
			$accepted++;
		}
		return array( 'ok'=>true, 'accepted'=>$accepted, 'generation'=>self::generation_id() );
	}

	public static function rotate_generation( string $reason, string $work_id = '' ): array {
		self::ensure_root();
		$lock_path = self::root() . '/.rotate.lock'; $lock = @fopen( $lock_path, 'c+' );
		if ( ! is_resource( $lock ) || ! flock( $lock, LOCK_EX ) ) { if ( is_resource($lock) ) @fclose($lock); return array( 'rotated'=>false, 'reason'=>'lock_failed' ); }
		try {
			$old = is_readable( self::root() . '/' . self::POINTER ) ? trim( (string) file_get_contents( self::root() . '/' . self::POINTER ) ) : '';
			$new = 'g_' . gmdate( 'Ymd\THis\Z' ) . '_' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 8 );
			self::ensure_generation( $new );
			if ( ! self::atomic_write( self::root() . '/' . self::POINTER, $new . "\n" ) ) {
				self::remove_tree( trailingslashit( self::root() ) . $new );
				return array( 'rotated'=>false, 'reason'=>'pointer_write_failed' );
			}
			$deleted = 0;
			foreach ( glob( self::root() . '/g_*' ) ?: array() as $path ) {
				if ( is_dir( $path ) && basename( $path ) !== $new && self::remove_tree( $path ) ) { $deleted++; }
			}
		} finally {
			flock( $lock, LOCK_UN );
			@fclose( $lock );
		}
		self::log( 'orchestrator', 'generation.rotated', array( 'previous'=>$old, 'reason'=>$reason, 'work_id'=>$work_id, 'old_generations_deleted'=>$deleted ), 'info', __FILE__ );
		return array( 'rotated'=>true, 'generation'=>$new, 'previous'=>$old, 'old_generations_deleted'=>$deleted );
	}

	private static function remove_tree( string $path ): bool {
		if ( ! is_dir( $path ) ) { return true; }
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) { $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() ); }
		return @rmdir( $path );
	}

	public static function integrity_snapshot( string $root, string $component = 'plugin' ): array {
		$root = rtrim( wp_normalize_path( $root ), '/' ); $files = array(); $bytes = 0; $lines = 0;
		if ( ! is_dir( $root ) ) { return array( 'ok'=>false, 'component'=>$component, 'error'=>'root_missing' ); }
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) { continue; }
			$path = wp_normalize_path( $file->getPathname() ); $relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			if ( preg_match( '#(?:^|/)FILE-INTEGRITY\.json$#', $relative ) ) { continue; }
			$size = (int) $file->getSize(); $line_count = 0;
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'php','js','mjs','json','md','txt','html','css','py','ts','xml','yml','yaml' ), true ) && $size <= 8 * 1024 * 1024 ) {
				$data = (string) @file_get_contents( $path ); $line_count = '' === $data ? 0 : substr_count( $data, "\n" ) + 1;
			}
			$files[] = array( 'path'=>$relative, 'bytes'=>$size, 'lines'=>$line_count, 'sha256'=>hash_file('sha256',$path) ); $bytes += $size; $lines += $line_count;
		}
		usort( $files, static fn($a,$b)=>strcmp($a['path'],$b['path']) );
		$result = array( 'ok'=>true, 'component'=>$component, 'file_count'=>count($files), 'bytes'=>$bytes, 'lines'=>$lines, 'files'=>$files );
		self::log( $component, 'integrity.snapshot', array( 'file_count'=>count($files), 'bytes'=>$bytes, 'lines'=>$lines, 'manifest_sha256'=>hash('sha256',wp_json_encode($files,JSON_UNESCAPED_SLASHES)) ), 'info', __FILE__ );
		return $result;
	}
}
