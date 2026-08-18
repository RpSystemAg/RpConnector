<?php

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Small private, site-scoped and crash-safe state store used by the 10.0 agency
 * services. It intentionally reuses the existing Memory site identity so an
 * update never creates a second operational identity for the same WordPress site.
 */
final class PRSTUDIO_UC_Agency_State {
	public const VERSION = '1.0.0';
	private const MAX_BYTES = 8388608;
	private const FILE_GUARD = "<?php defined( 'ABSPATH' ) || exit; ?>\n";

	private static function safe_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		return preg_match( '/^[a-z0-9][a-z0-9_-]{1,63}$/', $name ) ? $name : 'invalid';
	}

	private static function directory(): string {
		$root = class_exists( 'PRSTUDIO_UC_Memory' )
			? PRSTUDIO_UC_Memory::site_dir()
			: rtrim( str_replace( '\\', '/', defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : sys_get_temp_dir() ), '/' ) . '/prstudio-unified-private/memory/local';
		return $root . '/agency-v10';
	}

	private static function ensure(): bool {
		$dir = self::directory();
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $dir ); }
			else { @mkdir( $dir, 0750, true ); }
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { return false; }
		$protect=array($dir);
		if(class_exists('PRSTUDIO_UC_Memory')){$protect[]=PRSTUDIO_UC_Memory::root();$protect[]=PRSTUDIO_UC_Memory::site_dir();}
		foreach(array_unique($protect) as $protected){
			if(!is_dir($protected)){continue;}
			if(!is_file($protected.'/index.php')){@file_put_contents($protected.'/index.php',"<?php\n// Silence is golden.\n",LOCK_EX);}
			if(!is_file($protected.'/.htaccess')){@file_put_contents($protected.'/.htaccess',"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",LOCK_EX);}
			if(!is_file($protected.'/web.config')){@file_put_contents($protected.'/web.config',"<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",LOCK_EX);}
		}
		return true;
	}

	/*
	 * Use a guarded PHP data file instead of a directly downloadable JSON file.
	 * The surrounding directory denials remain defence in depth for Apache/IIS;
	 * this guard also protects a normal Nginx/PHP WordPress deployment.
	 */
	private static function path( string $name ): string { return self::directory() . '/' . self::safe_name( $name ) . '.state.php'; }
	private static function lock_path( string $name ): string { return self::directory() . '/.' . self::safe_name( $name ) . '.lock'; }

	private static function clean( $value, string $key = '', int $depth = 0 ) {
		if ( $depth > 12 ) { return '[MAX_DEPTH]'; }
		if ( preg_match( '/password|passwd|secret|token|api[_-]?key|credential|authorization|cookie|session|oauth|code_verifier|private_key/i', $key ) ) { return '[REDACTED]'; }
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			$out=array(); $count=0;
			foreach ( $value as $k=>$v ) {
				if ( $count++ >= 20000 ) { $out['_truncated']=true; break; }
				$out[$k]=self::clean($v,(string)$k,$depth+1);
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$value=(string)preg_replace('/\bBearer\s+\S+/i','Bearer [REDACTED]',$value);
			$value=(string)preg_replace('/([?&](?:access_token|refresh_token|token|api[_-]?key|secret|password|code|code_verifier)=)[^&#\s]+/i','$1[REDACTED]',$value);
			return strlen($value)>65536?substr($value,0,65536).'[TRUNCATED]':$value;
		}
		return $value;
	}

	private static function decode( string $name, array $defaults ): array {
		$raw = is_readable( self::path( $name ) ) ? (string) file_get_contents( self::path( $name ) ) : '';
		if ( '' === $raw || strlen( $raw ) > self::MAX_BYTES || ! str_starts_with( $raw, self::FILE_GUARD ) ) { return $defaults; }
		$raw = substr( $raw, strlen( self::FILE_GUARD ) );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? array_replace_recursive( $defaults, $decoded ) : $defaults;
	}

	private static function atomic_write( string $path, string $contents ): bool {
		if ( strlen( $contents ) > self::MAX_BYTES ) { return false; }
		try { $suffix = bin2hex( random_bytes( 6 ) ); }
		catch ( Throwable $e ) { $suffix = str_replace( '.', '', uniqid( 'prstudio-', true ) ); }
		$tmp = dirname( $path ) . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
		if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { return false; }
		@chmod( $tmp, 0640 );
		if ( @rename( $tmp, $path ) ) { return true; }
		@unlink( $tmp );
		return false;
	}

	public static function read( string $name, array $defaults = array() ): array {
		if ( 'invalid' === self::safe_name( $name ) || ! self::ensure() ) { return $defaults; }
		$fh = @fopen( self::lock_path( $name ), 'c+' );
		if ( is_resource( $fh ) ) { @flock( $fh, LOCK_SH ); }
		try { return self::decode( $name, $defaults ); }
		finally {
			if ( is_resource( $fh ) ) { @flock( $fh, LOCK_UN ); @fclose( $fh ); }
		}
	}

	/**
	 * Callback receives the mutable state by reference and may return any value.
	 * The write is serialized and committed by atomic rename.
	 */
	public static function mutate( string $name, array $defaults, callable $callback ) {
		if ( 'invalid' === self::safe_name( $name ) || ! self::ensure() ) { return null; }
		$fh = @fopen( self::lock_path( $name ), 'c+' );
		if ( ! is_resource( $fh ) || ! @flock( $fh, LOCK_EX ) ) {
			if ( is_resource( $fh ) ) { @fclose( $fh ); }
			return null;
		}
		try {
			$state = self::decode( $name, $defaults );
			$result = $callback( $state );
			$state = self::clean( $state );
			$state['_state'] = array(
				'version' => self::VERSION,
				'updated_gmt' => gmdate( 'c' ),
				'site' => class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::site_identity()['key'] : 'local',
			);
			$json = json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json || ! self::atomic_write( self::path( $name ), self::FILE_GUARD . $json . "\n" ) ) { return null; }
			return $result;
		} finally {
			@flock( $fh, LOCK_UN );
			@fclose( $fh );
		}
	}
}
