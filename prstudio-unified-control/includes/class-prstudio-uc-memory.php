<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
/**
 * PR STUDIO 4.0 multi-site durable memory.
 *
 * Human evidence is appended to memory-summary.txt. Machine reuse uses a bounded
 * JSON index. Mutating index operations are serialized with flock and committed
 * by atomic rename. The NDJSON evidence chain links every event to the previous
 * event hash, making accidental/tampered edits detectable.
 */
final class PRSTUDIO_UC_Memory {
    public const VERSION = '5.0.1';
    private const MAX_RESOURCES = 50000;
    private const MAX_TXT_BYTES = 8388608;
    private const MAX_CHAIN_BYTES = 33554432;
    private const MAX_VALUE_BYTES = 8192;

    private static function day(): int { return defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400; }

    public static function root(): string {
        $base = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : sys_get_temp_dir();
        return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/prstudio-unified-private/memory';
    }

    /**
     * Whether this installation can actually keep what it learns, and if not, why.
     *
     * Everything the suite remembers lives under
     * wp-content/prstudio-unified-private/ -- learned site modules, procedural
     * skills, evidence. On a hardened host the web user often cannot write
     * inside wp-content at all, which is a reasonable way to run WordPress and
     * completely fatal to remembering anything.
     *
     * The failure never looked like a permission problem. The skill store said
     * "Unable to persist procedural skill store", the site module said "Unable
     * to create private site-learning directory", and an operator who hit it
     * could only report that saving "had a technical error". Three subsystems,
     * three fixed sentences, and no path or permission between them.
     *
     * This is the single answer to "can it remember", callable before anything
     * tries: which directory, whether it exists, whether it and the nearest
     * existing parent are writable, whether wp-content itself is, and what PHP
     * last complained about.
     *
     * A read-only theme or plugin directory is a different matter and is
     * deliberately absent here. That is frequently intentional on a hardened
     * host, it does not stop the suite from remembering, and conflating the two
     * sends the fix in the wrong direction.
     *
     * @param string $subpath Optional directory under the memory root.
     * @return array<string,mixed>
     */
    public static function writability( string $subpath = '' ): array {
        $root = self::root();
        $dir  = '' === $subpath ? $root : rtrim( $root, '/' ) . '/' . ltrim( str_replace( '\\', '/', $subpath ), '/' );

        // Walk up to whatever does exist: on a fresh install nothing under the
        // root has been created yet, and "the directory is not writable" is
        // useless when the directory is not there. The nearest existing parent
        // is the one whose permissions actually decide the outcome.
        $existing = $dir;
        while ( '' !== $existing && ! is_dir( $existing ) && dirname( $existing ) !== $existing ) {
            $existing = dirname( $existing );
        }

        $last = error_get_last();
        return array(
            'memory_root'          => $root,
            'target_dir'           => $dir,
            'target_exists'        => is_dir( $dir ),
            'target_writable'      => is_dir( $dir ) && is_writable( $dir ),
            'nearest_existing_dir' => $existing,
            'nearest_writable'     => '' !== $existing && is_writable( $existing ),
            'wp_content_writable'  => defined( 'WP_CONTENT_DIR' ) ? is_writable( (string) WP_CONTENT_DIR ) : null,
            'free_bytes'           => ( '' !== $existing && is_dir( $existing ) ) ? @disk_free_space( $existing ) : null,
            'last_php_error'       => is_array( $last ) ? substr( (string) ( $last['message'] ?? '' ), 0, 300 ) : '',
        );
    }

    public static function installation_identity(): string {
        $option = 'prstudio_uc_installation_identity';
        if ( function_exists( 'get_option' ) ) {
            $existing = (string) get_option( $option, '' );
            if ( preg_match( '/^[a-f0-9]{32,64}$/', $existing ) ) { return $existing; }
            try { $created = bin2hex( random_bytes( 24 ) ); } catch ( Throwable $e ) { $created = hash( 'sha256', uniqid( 'prstudio-install-', true ) ); }
            if ( function_exists( 'update_option' ) ) { update_option( $option, $created, false ); }
            return $created;
        }
        return substr( hash( 'sha256', ( defined( 'ABSPATH' ) ? ABSPATH : __DIR__ ) . '|prstudio-installation' ), 0, 48 );
    }

    public static function legacy_site_identity(): array {
        $url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : ( function_exists( 'site_url' ) ? (string) site_url( '/' ) : 'local://wordpress/' );
        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
        $parts = is_array( $parts ) ? $parts : array();
        $host = strtolower( (string) ( $parts['host'] ?? 'wordpress' ) );
        $path = '/' . trim( (string) ( $parts['path'] ?? '/' ), '/' ); if ( '//' === $path ) { $path = '/'; }
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        $canonical = $host . '|' . $path . '|' . $blog_id; $slug = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', $host ), '-.' );
        return array( 'key'=>( $slug ? substr( $slug, 0, 50 ) . '-' : '' ) . substr( hash( 'sha256', $canonical ), 0, 16 ), 'host'=>$host, 'path'=>$path, 'blog_id'=>$blog_id, 'canonical_hash'=>hash( 'sha256', $canonical ) );
    }

    public static function site_identity(): array {
        $legacy = self::legacy_site_identity();
        $installation = self::installation_identity();
        $canonical = $legacy['host'] . '|' . $legacy['path'] . '|' . $legacy['blog_id'] . '|' . $installation;
        $slug = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', (string) $legacy['host'] ), '-.' );
        return array(
            'key'            => ( $slug ? substr( $slug, 0, 50 ) . '-' : '' ) . substr( hash( 'sha256', $canonical ), 0, 20 ),
            'host'           => $legacy['host'], 'path'=>$legacy['path'], 'blog_id'=>$legacy['blog_id'],
            'installation_id_hash' => hash( 'sha256', $installation ),
            'canonical_hash' => hash( 'sha256', $canonical ),
        );
    }

    public static function site_dir(): string { return self::root() . '/' . self::site_identity()['key']; }
    public static function summary_path(): string { return self::site_dir() . '/memory-summary.txt'; }
    private static function index_path(): string { return self::site_dir() . '/memory-index.json'; }
    private static function chain_path(): string { return self::site_dir() . '/memory-chain.ndjson'; }
    private static function lock_path(): string { return self::site_dir() . '/.memory.lock'; }

    private static function ensure(): bool {
        foreach ( array( self::root(), self::site_dir() ) as $dir ) {
            if ( ! is_dir( $dir ) ) {
                if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $dir ); } else { @mkdir( $dir, 0750, true ); }
            }
        }
        if ( ! is_dir( self::site_dir() ) || ! is_writable( self::site_dir() ) ) { return false; }
        $root = self::root();
        if ( ! is_file( $root . '/index.php' ) ) { @file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX ); }
        if ( ! is_file( $root . '/.htaccess' ) ) { @file_put_contents( $root . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", LOCK_EX ); }
        if ( ! is_file( $root . '/web.config' ) ) { @file_put_contents( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n", LOCK_EX ); }
        return true;
    }

    private static function defaults(): array {
        return array( 'version'=>3, 'sequence'=>0, 'last_hash'=>str_repeat( '0', 64 ), 'resources'=>array(), 'missions'=>array(), 'metrics'=>array() );
    }

    private static function decode_state(): array {
        $raw = is_readable( self::index_path() ) ? (string) file_get_contents( self::index_path() ) : '';
        $decoded = '' !== $raw ? json_decode( $raw, true ) : array();
        return is_array( $decoded ) ? array_merge( self::defaults(), $decoded ) : self::defaults();
    }

    private static function atomic_write( string $path, string $contents ): bool {
        $dir = dirname( $path );
        try { $suffix = bin2hex( random_bytes( 6 ) ); } catch ( Throwable $e ) { $suffix = str_replace( '.', '', uniqid( '', true ) ); }
        $tmp = $dir . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
        if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { return false; }
        @chmod( $tmp, 0640 );
        if ( @rename( $tmp, $path ) ) { return true; }
        @unlink( $tmp );
        return false;
    }

    private static function normalize_state( array $state ): array {
        $state['version'] = 3;
        $state['site'] = self::site_identity();
        $state['suite_version'] = defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : self::VERSION;
        $state['updated_gmt'] = gmdate( 'c' );
        if ( count( (array) $state['resources'] ) > self::MAX_RESOURCES ) {
            uasort( $state['resources'], static fn( $a, $b ) => strcmp( (string) ( $b['updated_gmt'] ?? '' ), (string) ( $a['updated_gmt'] ?? '' ) ) );
            $state['resources'] = array_slice( $state['resources'], 0, self::MAX_RESOURCES, true );
        }
        return $state;
    }

    private static function with_state_lock( callable $callback ) {
        if ( ! self::ensure() ) { return null; }
        $fh = @fopen( self::lock_path(), 'c+' );
        if ( ! is_resource( $fh ) || ! @flock( $fh, LOCK_EX ) ) { if ( is_resource( $fh ) ) { @fclose( $fh ); } return null; }
        try {
            $state = self::decode_state();
            $result = $callback( $state );
            $state = self::normalize_state( $state );
            if ( ! self::atomic_write( self::index_path(), json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) ) { return null; }
            return $result;
        } finally {
            @flock( $fh, LOCK_UN );
            @fclose( $fh );
        }
    }

    private static function read_state(): array {
        if ( ! self::ensure() ) { return self::defaults(); }
        $fh = @fopen( self::lock_path(), 'c+' );
        if ( is_resource( $fh ) ) { @flock( $fh, LOCK_SH ); }
        try { return self::decode_state(); }
        finally { if ( is_resource( $fh ) ) { @flock( $fh, LOCK_UN ); @fclose( $fh ); } }
    }

    private static function clean( $value, string $key = '', int $depth = 0 ) {
        if ( $depth > 6 ) { return '[MAX_DEPTH]'; }
        // Public continuation credentials are intentionally returned to the MCP
        // caller and are not bearer secrets. Keep them usable while redacting
        // actual authentication/session credentials. The former broad `token`
        // substring rule destroyed write_token and made every observed write
        // precondition unusable before the anti-crash gate could even run.
        if ( preg_match( '/password|secret|(?:^|_)(?:access|refresh|id|lane|pairing)_?token(?:$|_)|api[_-]?key|apikey|credential|authorization|cookie|session|oauth|code_verifier|private_key/i', $key ) ) { return '[REDACTED]'; }
        if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
        if ( is_array( $value ) ) {
            $out = array(); $count = 0;
            foreach ( $value as $k => $v ) {
                if ( $count++ >= 100 ) { $out['_truncated'] = true; break; }
                $out[ $k ] = self::clean( $v, (string) $k, $depth + 1 );
            }
            return $out;
        }
        if ( is_string( $value ) ) {
            $value = preg_replace( '/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $value );
            $value = preg_replace( '/([?&](?:access_token|refresh_token|token|api[_-]?key|secret|password|code|code_verifier)=)[^&#\s]+/i', '$1[REDACTED]', $value );
            return strlen( $value ) > self::MAX_VALUE_BYTES ? substr( $value, 0, self::MAX_VALUE_BYTES ) . '[TRUNCATED]' : $value;
        }
        return $value;
    }

    public static function redact( $value ) { return self::clean( $value ); }

    public static function fingerprint( $value ): string {
        $clean = self::clean( $value );
        $json = class_exists( 'PRSTUDIO_UC_Idempotency' ) ? PRSTUDIO_UC_Idempotency::canonical_json( $clean ) : json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', (string) $json );
    }

    private static function rotate_append_file( string $path, int $max_bytes ): void {
        if ( is_file( $path ) && (int) @filesize( $path ) >= $max_bytes ) {
            @unlink( $path . '.3' ); @rename( $path . '.2', $path . '.3' ); @rename( $path . '.1', $path . '.2' ); @rename( $path, $path . '.1' );
        }
    }

    public static function movement( string $event, array $data = array(), string $job = '' ): array {
        $result = self::with_state_lock( static function ( array &$state ) use ( $event, $data, $job ): array {
            $state['sequence'] = (int) $state['sequence'] + 1;
            $clean = self::clean( $data );
            $previous = (string) $state['last_hash'];
            $entry = array(
                'seq'           => $state['sequence'],
                'gmt'           => gmdate( 'c' ),
                'site'          => self::site_identity()['key'],
                'event'         => substr( (string) preg_replace( '/[^a-zA-Z0-9._:-]+/', '_', $event ), 0, 128 ),
                'job_id'        => substr( $job, 0, 80 ),
                'data'          => $clean,
                'previous_hash' => $previous,
            );
            $entry['hash'] = hash( 'sha256', $previous . "\n" . json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $state['last_hash'] = $entry['hash'];
            $state['metrics']['movements'] = (int) ( $state['metrics']['movements'] ?? 0 ) + 1;
            self::rotate_append_file( self::chain_path(), self::MAX_CHAIN_BYTES );
            @file_put_contents( self::chain_path(), json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
            self::rotate_append_file( self::summary_path(), self::MAX_TXT_BYTES );
            $method = (string) ( $clean['method'] ?? $clean['strategy'] ?? $clean['executor'] ?? '' );
            $resource = (string) ( $clean['resource'] ?? $clean['url'] ?? $clean['product_id'] ?? '' );
            $outcome = (string) ( $clean['outcome'] ?? $clean['status'] ?? '' );
            $resource = (string) self::clean( str_replace( array( "\r", "\n", "\t" ), ' ', substr( $resource, 0, 300 ) ), 'resource' );
            $extras = array();
            foreach ( array( 'mission_id'=>'mission', 'capability'=>'capability', 'state_initial'=>'initial', 'action'=>'action', 'verification'=>'verification', 'evidence'=>'evidence', 'fingerprint'=>'fingerprint', 'memory_reused'=>'memory_reused', 'reason'=>'reason', 'duration_ms'=>'duration_ms' ) as $key=>$label ) {
                if ( ! array_key_exists( $key, $clean ) || is_array( $clean[$key] ) || is_object( $clean[$key] ) ) { continue; }
                $value = str_replace( array("\n","\n","\t"), ' ', substr( (string) $clean[$key], 0, 180 ) ); $extras[] = $label . '=' . $value;
            }
            $line = sprintf( '[%s] #%d %s%s%s%s job=%s%s hash=%s', $entry['gmt'], $entry['seq'], $entry['event'], $method ? ' via=' . substr( $method, 0, 100 ) : '', $resource ? ' resource=' . $resource : '', $outcome ? ' outcome=' . substr( $outcome, 0, 80 ) : '', $job ?: '-', $extras ? ' ' . implode( ' ', $extras ) : '', substr( $entry['hash'], 0, 16 ) );
            @file_put_contents( self::summary_path(), $line . "\n", FILE_APPEND | LOCK_EX );
            return array( 'ok'=>true, 'sequence'=>$entry['seq'], 'hash'=>$entry['hash'], 'summary'=>self::summary_path() );
        } );
        return is_array( $result ) ? $result : array( 'ok'=>false, 'reason'=>'memory_lock_or_write_failed' );
    }

    private static function resource_key( string $type, string $id ): string { return sanitize_key( $type ) . ':' . hash( 'sha256', trim( $id ) ); }

    public static function remember( string $type, string $id, $fingerprint, array $summary = array(), int $ttl = 604800 ): array {
        $id = trim( $id ); if ( '' === $id ) { return array( 'ok'=>false ); }
        $fp = is_string( $fingerprint ) && preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? $fingerprint : self::fingerprint( $fingerprint );
        $result = self::with_state_lock( static function ( array &$state ) use ( $type, $id, $fp, $summary, $ttl ): array {
            $state['resources'][ self::resource_key( $type, $id ) ] = array(
                'type'        => sanitize_key( $type ), 'id'=>substr( $id, 0, 2048 ), 'fingerprint'=>$fp, 'verified'=>true,
                'summary'     => self::clean( $summary ), 'updated_gmt'=>gmdate( 'c' ),
                'expires_gmt' => gmdate( 'c', time() + max( 60, min( 90 * self::day(), $ttl ) ) ),
            );
            $state['metrics']['remembered'] = (int) ( $state['metrics']['remembered'] ?? 0 ) + 1;
            return array( 'ok'=>true );
        } );
        return is_array( $result ) ? $result : array( 'ok'=>false );
    }

    public static function lookup( string $type, string $id ): ?array {
        $state = self::read_state(); $row = $state['resources'][ self::resource_key( $type, $id ) ] ?? null;
        return is_array( $row ) ? $row : null;
    }

    public static function can_reuse( string $type, string $id, string $fingerprint = '' ): bool {
        $row = self::lookup( $type, $id );
        $ok = is_array( $row ) && ! empty( $row['verified'] ) && strtotime( (string) ( $row['expires_gmt'] ?? '' ) ) > time();
        if ( $ok && '' !== $fingerprint ) {
            $fp = preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? $fingerprint : hash( 'sha256', $fingerprint );
            $ok = hash_equals( (string) $row['fingerprint'], $fp );
        }
        return $ok;
    }

    public static function invalidate_all_views( string $id, string $reason = 'changed' ): int {
        $result = self::with_state_lock( static function ( array &$state ) use ( $id ): int {
            $count = 0;
            foreach ( array_keys( (array) $state['resources'] ) as $key ) {
                if ( (string) ( $state['resources'][ $key ]['id'] ?? '' ) === (string) $id ) { unset( $state['resources'][ $key ] ); ++$count; }
            }
            $state['metrics']['invalidations'] = (int) ( $state['metrics']['invalidations'] ?? 0 ) + $count;
            return $count;
        } );
        $count = is_int( $result ) ? $result : 0;
        self::movement( 'memory.invalidated', array( 'resource'=>$id, 'reason'=>$reason, 'outcome'=>'removed', 'count'=>$count ) );
        return $count;
    }

    public static function invalidate_type( string $type, string $reason = 'type_changed' ): int {
        $type = sanitize_key( $type );
        $result = self::with_state_lock( static function ( array &$state ) use ( $type ): int {
            $count = 0;
            foreach ( array_keys( (array) $state['resources'] ) as $key ) {
                if ( (string) ( $state['resources'][ $key ]['type'] ?? '' ) === $type ) { unset( $state['resources'][ $key ] ); ++$count; }
            }
            $state['metrics']['invalidations'] = (int) ( $state['metrics']['invalidations'] ?? 0 ) + $count;
            return $count;
        } );
        $count = is_int( $result ) ? $result : 0;
        self::movement( 'memory.type_invalidated', array( 'resource'=>$type, 'reason'=>$reason, 'outcome'=>'removed', 'count'=>$count ) );
        return $count;
    }

    public static function remember_call( array $governance, array $args, $result, string $job = '' ): array {
        $route = (string) ( $governance['route'] ?? '' );
        $type = str_contains( $route, 'product' ) ? 'product' : ( str_contains( $route, 'seo' ) ? 'seo_resource' : 'resource' );
        $count = 0;
        foreach ( array( 'product_id', 'post_id', 'object_id', 'id' ) as $key ) {
            if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
                self::remember( $type, (string) $args[ $key ], self::fingerprint( $result ), array( 'action'=>$governance['action'] ?? '', 'status'=>'verified', 'job_id'=>$job ) ); ++$count; break;
            }
        }
        foreach ( array( 'url', 'url_or_path', 'page' ) as $key ) {
            if ( ! empty( $args[ $key ] ) && is_string( $args[ $key ] ) ) {
                self::remember( 'url', (string) $args[ $key ], self::fingerprint( $result ), array( 'action'=>$governance['action'] ?? '', 'status'=>'verified', 'job_id'=>$job ), self::day() ); ++$count;
            }
        }
        return array( 'ok'=>true, 'remembered'=>$count );
    }

    public static function mission( string $id, ?array $state = null ): array {
        $key = substr( (string) preg_replace( '/[^a-zA-Z0-9._:-]+/', '_', $id ), 0, 96 );
        if ( null !== $state ) {
            self::with_state_lock( static function ( array &$data ) use ( $key, $state ): bool {
                $clean = self::clean( $state ); $clean['updated_gmt'] = gmdate( 'c' ); $data['missions'][ $key ] = $clean; return true;
            } );
        }
        $data = self::read_state(); return is_array( $data['missions'][ $key ] ?? null ) ? $data['missions'][ $key ] : array();
    }

    public static function save_context( array $context ): bool {
        if ( ! self::ensure() ) { return false; }
        return self::atomic_write( self::site_dir() . '/context.json', json_encode( array( 'version'=>self::VERSION, 'site'=>self::site_identity(), 'updated_gmt'=>gmdate( 'c' ), 'context'=>self::clean( $context ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
    }

    public static function context(): array {
        $path = self::site_dir() . '/context.json'; $data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array();
        return is_array( $data['context'] ?? null ) ? $data['context'] : array();
    }

    public static function search( string $query, string $type = '', int $limit = 20 ): array {
        $state = self::read_state(); $q = strtolower( trim( $query ) ); $type = sanitize_key( $type ); $limit = max( 1, min( 50, $limit ) ); $items = array();
        foreach ( (array) ( $state['resources'] ?? array() ) as $row ) {
            if ( $type && (string) ( $row['type'] ?? '' ) !== $type ) { continue; }
            $haystack = strtolower( (string) ( $row['type'] ?? '' ) . ' ' . (string) ( $row['id'] ?? '' ) . ' ' . json_encode( $row['summary'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            if ( '' !== $q && false === strpos( $haystack, $q ) ) { continue; }
            $items[] = array( 'type'=>$row['type']??'', 'id'=>$row['id']??'', 'fingerprint'=>$row['fingerprint']??'', 'verified'=>(bool)($row['verified']??false), 'updated_gmt'=>$row['updated_gmt']??'', 'expires_gmt'=>$row['expires_gmt']??'', 'summary'=>$row['summary']??array() );
        }
        usort( $items, static fn( $a, $b ) => strcmp( (string) $b['updated_gmt'], (string) $a['updated_gmt'] ) );
        return array( 'query'=>$query, 'type'=>$type, 'count'=>min(count($items),$limit), 'items'=>array_slice($items,0,$limit), 'site'=>self::site_identity(), 'memory_summary'=>basename(self::summary_path()) );
    }

    public static function snapshot(): array {
        $state = self::read_state();
        return array( 'ok'=>true, 'version'=>self::VERSION, 'site'=>self::site_identity(), 'resources'=>count( (array) $state['resources'] ), 'missions'=>count( (array) $state['missions'] ), 'metrics'=>$state['metrics'], 'last_chain_hash'=>$state['last_hash'], 'summary_path'=>self::summary_path() );
    }
}
