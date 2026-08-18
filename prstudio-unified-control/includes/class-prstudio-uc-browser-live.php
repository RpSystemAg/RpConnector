<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Private ephemeral signaling for Browser LIVE MediaStream/WebRTC.
 *
 * Only SDP/ICE and bounded state/diagnostic metadata are persisted. Media
 * frames, blobs, recordings and data URLs are rejected by construction.
 */
final class PRSTUDIO_UC_Browser_Live {
    public const VERSION = '1.1.0';
    private const TTL = 28800; // 8 hours while active.
    private const STOP_TTL = 120;
    private const MAX_EVENTS = 160;
    private const MAX_EVENT_BYTES = 131072;
    private const ALLOWED_TYPES = array( 'offer', 'answer', 'ice', 'state', 'diagnostic', 'restart', 'stop', 'error' );

    private static function dir(): string {
        $base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : sys_get_temp_dir();
        $dir = rtrim( $base, '/\\' ) . '/prstudio-unified-private/browser-live';
        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $dir ); }
            else { @mkdir( $dir, 0750, true ); }
        }
        if ( is_dir( $dir ) ) {
            $ht = $dir . '/.htaccess';
            if ( ! is_file( $ht ) ) { @file_put_contents( $ht, "Deny from all\n" ); }
            $idx = $dir . '/index.php';
            if ( ! is_file( $idx ) ) { @file_put_contents( $idx, "<?php http_response_code(404); exit;\n" ); }
        }
        return $dir;
    }

    private static function valid_id( string $id ): bool { return 1 === preg_match( '/^[a-f0-9]{32}$/', $id ); }
    private static function path( string $id ): string { return self::dir() . '/' . $id . '.json'; }
    private static function lock_path( string $id ): string { return self::dir() . '/' . $id . '.lock'; }

    private static function read_file( string $id ): ?array {
        if ( ! self::valid_id( $id ) ) { return null; }
        $raw = @file_get_contents( self::path( $id ) );
        if ( ! is_string( $raw ) || '' === $raw ) { return null; }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    private static function write_file( string $id, array $data ): bool {
        if ( ! self::valid_id( $id ) ) { return false; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { return false; }
        $tmp = self::path( $id ) . '.tmp-' . substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) { return false; }
        @chmod( $tmp, 0640 );
        $ok = @rename( $tmp, self::path( $id ) );
        if ( ! $ok ) { @unlink( $tmp ); }
        return $ok;
    }

    private static function mutate( string $id, callable $callback ) {
        if ( ! self::valid_id( $id ) ) { return new WP_Error( 'browser_live_session_invalid', 'Sessione WebRTC non valida.', array( 'status'=>400 ) ); }
        $lock = @fopen( self::lock_path( $id ), 'c+' );
        if ( ! $lock ) { return new WP_Error( 'browser_live_lock_unavailable', 'Lock signaling WebRTC non disponibile.', array( 'status'=>503, 'retryable'=>true ) ); }
        try {
            if ( ! @flock( $lock, LOCK_EX ) ) { return new WP_Error( 'browser_live_lock_failed', 'Impossibile acquisire il lock signaling WebRTC.', array( 'status'=>503, 'retryable'=>true ) ); }
            $data = self::read_file( $id );
            if ( ! is_array( $data ) ) { return new WP_Error( 'browser_live_session_not_found', 'Sessione WebRTC non trovata.', array( 'status'=>404 ) ); }
            if ( (int) ( $data['expires_at'] ?? 0 ) < time() ) {
                @unlink( self::path( $id ) );
                return new WP_Error( 'browser_live_session_expired', 'Sessione WebRTC scaduta.', array( 'status'=>410 ) );
            }
            $result = $callback( $data );
            if ( is_wp_error( $result ) ) { return $result; }
            if ( ! self::write_file( $id, $data ) ) { return new WP_Error( 'browser_live_write_failed', 'Persistenza signaling WebRTC fallita.', array( 'status'=>503, 'retryable'=>true ) ); }
            return $result;
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    private static function sanitize_payload( string $type, $payload ) {
        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) { return new WP_Error( 'browser_live_event_type_invalid', 'Tipo evento WebRTC non consentito.', array( 'status'=>400 ) ); }
        if ( ! is_array( $payload ) ) { $payload = array(); }
        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_EVENT_BYTES ) { return new WP_Error( 'browser_live_event_too_large', 'Evento signaling WebRTC oltre il limite.', array( 'status'=>413 ) ); }
        if ( false !== stripos( $encoded, 'data:image/' ) || false !== stripos( $encoded, 'data:video/' ) || false !== stripos( $encoded, 'data:audio/' ) ) {
            return new WP_Error( 'browser_live_media_payload_forbidden', 'Il signaling non accetta payload media.', array( 'status'=>400 ) );
        }
        return $payload;
    }

    private static function append_events( array &$data, string $from, array $events ) {
        $events = array_slice( $events, 0, 32 );
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) { continue; }
            $type = sanitize_key( (string) ( $event['type'] ?? '' ) );
            $payload = self::sanitize_payload( $type, $event['payload'] ?? array() );
            if ( is_wp_error( $payload ) ) { return $payload; }
            $data['seq'] = (int) ( $data['seq'] ?? 0 ) + 1;
            $row = array( 'seq'=>$data['seq'], 'from'=>$from, 'type'=>$type, 'payload'=>$payload, 'at'=>gmdate( 'c' ) );
            $data['events'][] = $row;
            if ( 'diagnostic' === $type ) {
                $gate = sanitize_key( (string) ( $payload['gate'] ?? '' ) );
                if ( '' !== $gate ) {
                    if ( ! isset( $data['diagnostic'] ) || ! is_array( $data['diagnostic'] ) ) { $data['diagnostic'] = array(); }
                    $data['diagnostic'][ $gate ] = array( 'ok'=>(bool)($payload['ok']??false), 'detail'=>(array)($payload['detail']??array()), 'at'=>gmdate('c') );
                }
            }
            if ( 'state' === $type && isset( $payload['status'] ) ) { $data['state'] = sanitize_key( (string) $payload['status'] ); }
            if ( 'stop' === $type ) { $data['state'] = 'stopped'; $data['expires_at'] = time() + self::STOP_TTL; }
            if ( 'error' === $type ) { $data['state'] = 'error'; }
        }
        if ( count( $data['events'] ) > self::MAX_EVENTS ) { $data['events'] = array_slice( $data['events'], -self::MAX_EVENTS ); }
        return true;
    }

    private static function public_session( array $data, int $after = 0, string $for = '' ): array {
        $events = array();
        foreach ( (array) ( $data['events'] ?? array() ) as $event ) {
            if ( (int) ( $event['seq'] ?? 0 ) <= $after ) { continue; }
            if ( 'agent' === $for && 'agent' === (string) ( $event['from'] ?? '' ) ) { continue; }
            if ( 'viewer' === $for && 'viewer' === (string) ( $event['from'] ?? '' ) ) { continue; }
            $events[] = $event;
        }
        return array(
            'ok'=>true,
            'session_id'=>(string)$data['session_id'],
            'device_id'=>(string)$data['device_id'],
            'tab_id'=>(int)$data['tab_id'],
            'state'=>(string)($data['state']??'created'),
            'seq'=>(int)($data['seq']??0),
            'events'=>$events,
            'updated_at'=>(string)($data['updated_at']??''),
            'expires_at'=>(int)($data['expires_at']??0),
        );
    }

    public static function cleanup(): void {
        $dir = self::dir();
        foreach ( glob( $dir . '/*.json' ) ?: array() as $file ) {
            $raw = @file_get_contents( $file );
            $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
            if ( ! is_array( $data ) || (int) ( $data['expires_at'] ?? 0 ) < time() ) {
                @unlink( $file );
                $base = basename( $file, '.json' );
                @unlink( $dir . '/' . $base . '.lock' );
            }
        }
    }

    public static function create_agent_session( string $device_id, int $tab_id, array $meta = array() ) {
        $device_id = sanitize_text_field( $device_id );
        if ( '' === $device_id || $tab_id <= 0 ) { return new WP_Error( 'browser_live_create_args', 'device_id e tab_id sono obbligatori.', array( 'status'=>400 ) ); }
        self::cleanup();
        $existing = self::find_active( $tab_id, $device_id );
        if ( is_array( $existing ) ) { self::close_agent( (string)$existing['session_id'], $device_id, 'superseded' ); }
        $id = bin2hex( random_bytes( 16 ) );
        $now = time();
        $data = array(
            'schema_version'=>'1.1.0', 'session_id'=>$id, 'device_id'=>$device_id, 'tab_id'=>$tab_id,
            'created_at'=>gmdate('c',$now), 'updated_at'=>gmdate('c',$now), 'expires_at'=>$now+self::TTL,
            'state'=>'created', 'viewer_owner'=>'', 'seq'=>0, 'events'=>array(), 'diagnostic'=>array(),
            'meta'=>array(
                'source'=>sanitize_text_field((string)($meta['source']??'')),
                'title'=>sanitize_text_field((string)($meta['title']??'')),
                'url'=>esc_url_raw((string)($meta['url']??'')),
            ),
        );
        if ( ! self::write_file( $id, $data ) ) { return new WP_Error( 'browser_live_create_failed', 'Creazione sessione signaling WebRTC fallita.', array( 'status'=>503, 'retryable'=>true ) ); }
        return self::public_session( $data );
    }

    public static function find_active( int $tab_id, string $device_id = '' ): ?array {
        self::cleanup();
        $best = null;
        foreach ( glob( self::dir() . '/*.json' ) ?: array() as $file ) {
            $raw = @file_get_contents( $file ); $data = is_string($raw)?json_decode($raw,true):null;
            if ( ! is_array( $data ) || (int)($data['tab_id']??0)!==$tab_id ) { continue; }
            if ( '' !== $device_id && ! hash_equals( $device_id, (string)($data['device_id']??'') ) ) { continue; }
            if ( in_array( (string)($data['state']??''), array('stopped','closed','error'), true ) ) { continue; }
            if ( (int)($data['expires_at']??0)<time() ) { continue; }
            if ( null === $best || strcmp( (string)($data['updated_at']??''), (string)($best['updated_at']??'') ) > 0 ) { $best=$data; }
        }
        return is_array($best)?self::public_session($best):null;
    }

    public static function claim_viewer( string $id, string $owner ) {
        if ( '' === $owner ) { return new WP_Error( 'browser_live_viewer_owner_missing', 'Viewer owner mancante.', array( 'status'=>403 ) ); }
        return self::mutate( $id, static function( array &$data ) use ( $owner ) {
            $current=(string)($data['viewer_owner']??'');
            if ( ''!==$current && !hash_equals($current,$owner) ) { return new WP_Error('browser_live_viewer_claimed','Sessione LIVE già associata a un altro viewer.',array('status'=>409)); }
            $data['viewer_owner']=$owner; $data['updated_at']=gmdate('c'); $data['expires_at']=time()+self::TTL;
            if('created'===(string)($data['state']??''))$data['state']='viewer_attached';
            return self::public_session($data);
        } );
    }

    public static function agent_exchange( string $id, string $device_id, int $after, array $events ) {
        return self::mutate( $id, static function( array &$data ) use ( $device_id, $after, $events ) {
            if ( ! hash_equals( (string)($data['device_id']??''), $device_id ) ) { return new WP_Error('browser_live_device_mismatch','Sessione LIVE non appartiene al dispositivo.',array('status'=>403)); }
            $ok=self::append_events($data,'agent',$events); if(is_wp_error($ok))return $ok;
            $data['updated_at']=gmdate('c'); if(!in_array((string)($data['state']??''),array('stopped','error'),true))$data['expires_at']=time()+self::TTL;
            return self::public_session($data,max(0,$after),'agent');
        } );
    }

    public static function viewer_exchange( string $id, string $owner, int $after, array $events ) {
        return self::mutate( $id, static function( array &$data ) use ( $owner, $after, $events ) {
            if ( ''===(string)($data['viewer_owner']??'') || !hash_equals((string)$data['viewer_owner'],$owner) ) { return new WP_Error('browser_live_viewer_mismatch','Viewer LIVE non autorizzato per questa sessione.',array('status'=>403)); }
            $ok=self::append_events($data,'viewer',$events); if(is_wp_error($ok))return $ok;
            $data['updated_at']=gmdate('c'); if(!in_array((string)($data['state']??''),array('stopped','error'),true))$data['expires_at']=time()+self::TTL;
            return self::public_session($data,max(0,$after),'viewer');
        } );
    }

    public static function close_agent( string $id, string $device_id, string $reason = 'agent_stop' ) {
        return self::mutate( $id, static function( array &$data ) use ( $device_id, $reason ) {
            if ( !hash_equals((string)($data['device_id']??''),$device_id) )return new WP_Error('browser_live_device_mismatch','Sessione LIVE non appartiene al dispositivo.',array('status'=>403));
            $data['state']='stopped';$data['updated_at']=gmdate('c');$data['expires_at']=time()+self::STOP_TTL;
            self::append_events($data,'agent',array(array('type'=>'stop','payload'=>array('reason'=>sanitize_text_field($reason)))));
            return array('ok'=>true,'session_id'=>(string)$data['session_id'],'stopped'=>true);
        } );
    }

    public static function close_viewer( string $id, string $owner, string $reason = 'viewer_close' ) {
        return self::mutate( $id, static function( array &$data ) use ( $owner, $reason ) {
            if ( ''===(string)($data['viewer_owner']??'') || !hash_equals((string)$data['viewer_owner'],$owner) )return new WP_Error('browser_live_viewer_mismatch','Viewer LIVE non autorizzato.',array('status'=>403));
            self::append_events($data,'viewer',array(array('type'=>'stop','payload'=>array('reason'=>sanitize_text_field($reason)))));
            $data['updated_at']=gmdate('c');$data['expires_at']=time()+self::STOP_TTL;
            return array('ok'=>true,'session_id'=>(string)$data['session_id'],'closed'=>true);
        } );
    }

    public static function inspect( string $id ): ?array { $data=self::read_file($id);return is_array($data)?array_merge(self::public_session($data),array('diagnostic'=>(array)($data['diagnostic']??array()))):null; }

    public static function status(): array {
        self::cleanup();$active=0;$latest=null;
        foreach(glob(self::dir().'/*.json')?:array() as $file){$raw=@file_get_contents($file);$d=is_string($raw)?json_decode($raw,true):null;if(!is_array($d))continue;if(!in_array((string)($d['state']??''),array('stopped','error','closed'),true))$active++;if(null===$latest||strcmp((string)($d['updated_at']??''),(string)($latest['updated_at']??''))>0)$latest=$d;}
        return array(
            'ok'=>true,'version'=>self::VERSION,'active_sessions'=>$active,'transport'=>'webrtc','media'=>'video',
            'persistent_media_storage'=>false,'signaling_storage'=>'private_ephemeral_file',
            'diagnostic'=>is_array($latest)?array('available'=>!empty($latest['diagnostic']),'session_id'=>(string)($latest['session_id']??''),'tab_id'=>(int)($latest['tab_id']??0),'gates'=>(array)($latest['diagnostic']??array())):array('available'=>false),
        );
    }
}
