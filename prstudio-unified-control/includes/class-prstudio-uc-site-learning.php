<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Durable, installation-scoped site learning.
 * Generic sites retain the rendered same-origin crawler introduced by PR #33.
 * WordPress study learns authenticated wp-admin through verified Browser Agent
 * navigation, safe click, DOM observation and screenshot steps.
 */
final class PRSTUDIO_UC_Site_Learning {
    public const VERSION = '1.1.0';
    private const SCHEMA_VERSION = 2;
    private const DEFAULT_MAX_PAGES = 150;
    private const MAX_PAGES = 1500;
    private const DEFAULT_BATCH_PAGES = 8;
    private const MAX_BATCH_PAGES = 20;
    private const MAX_EVIDENCE_ROWS = 500;
    private const MAX_FAILURES = 100;
    private const MAX_TABLE_ROWS = 5000;

    private static function clamp( $value, int $min, int $max, int $fallback ): int {
        $value = is_numeric( $value ) ? (int) $value : $fallback;
        return max( $min, min( $max, $value ) );
    }

    public static function normalize_url( string $url, bool $persistent = false ): string {
        $url = trim( $url );
        if ( '' === $url ) { return ''; }
        $parts = @parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return ''; }
        $host = strtolower( (string) $parts['host'] );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        if ( ( 'https' === $scheme && ':443' === $port ) || ( 'http' === $scheme && ':80' === $port ) ) { $port = ''; }
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
        $query = '';
        if ( ! $persistent && ! empty( $parts['query'] ) ) { $query = '?' . (string) $parts['query']; }
        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function origin_for_url( string $url ): string {
        $normalized = self::normalize_url( $url );
        if ( '' === $normalized ) { return ''; }
        $parts = @parse_url( $normalized );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . $port;
    }

    public static function module_id_for_url( string $url ): string {
        $origin = self::origin_for_url( $url );
        return '' === $origin ? '' : 'site-' . substr( hash( 'sha256', $origin ), 0, 24 );
    }

    private static function module_dir( string $module_id ): string {
        $base = class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::site_dir() : sys_get_temp_dir() . '/prstudio-memory';
        return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/site-modules/' . preg_replace( '/[^a-z0-9._-]+/', '-', strtolower( $module_id ) );
    }
    private static function module_path( string $module_id ): string { return self::module_dir( $module_id ) . '/module.json'; }
    private static function skill_path( string $module_id ): string { return self::module_dir( $module_id ) . '/SKILL.md'; }
    private static function lock_path( string $module_id ): string { return self::module_dir( $module_id ) . '/.module.lock'; }

    private static function ensure_dir( string $module_id ): bool {
        $dir = self::module_dir( $module_id );
        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $dir ); }
            else { @mkdir( $dir, 0750, true ); }
        }
        return is_dir( $dir ) && is_writable( $dir );
    }

    private static function scrub_inline_images( $value, string $key = '', int $depth = 0 ) {
        if ( $depth > 8 ) { return '[MAX_DEPTH]'; }
        if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
        if ( is_string( $value ) ) {
            if ( preg_match( '#^data:image/[^;]+;base64,#i', $value ) ) {
                return array( '_omitted'=>'inline_image', 'sha256'=>hash( 'sha256', $value ), 'bytes'=>strlen( $value ) );
            }
            if ( preg_match( '/(?:base64|image_data|screenshot_data)/i', $key ) && strlen( $value ) > 1024 ) {
                return array( '_omitted'=>'binary_payload', 'sha256'=>hash( 'sha256', $value ), 'bytes'=>strlen( $value ) );
            }
            return class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::redact( $value ) : $value;
        }
        if ( is_array( $value ) ) {
            $out = array(); $count = 0;
            foreach ( $value as $k=>$v ) {
                if ( $count++ >= 1000 ) { $out['_truncated'] = true; break; }
                $out[$k] = self::scrub_inline_images( $v, (string) $k, $depth + 1 );
            }
            return class_exists( 'PRSTUDIO_UC_Memory' ) ? PRSTUDIO_UC_Memory::redact( $out ) : $out;
        }
        return $value;
    }
    private static function clean( $value ) { return self::scrub_inline_images( $value ); }

    private static function defaults( string $module_id, string $origin ): array {
        return array(
            'schema_version'=>self::SCHEMA_VERSION,
            'version'=>self::VERSION,
            'module_id'=>$module_id,
            'site_key'=>class_exists('PRSTUDIO_UC_Memory') && method_exists('PRSTUDIO_UC_Memory','site_identity') ? (string)(PRSTUDIO_UC_Memory::site_identity()['key']??'') : '',
            'origin'=>$origin,
            'mode'=>'generic','state'=>'new','revision'=>0,'run_id'=>'','active_task_id'=>'',
            'surface_hash'=>'','previous_surface_hash'=>'',
            'drift'=>array('changed'=>false,'revalidation_required'=>false),
            'coverage'=>array('discovered'=>0,'visited'=>0,'remaining'=>0,'percent'=>0.0,'complete'=>false),
            'discovered_urls'=>array(),'pending_urls'=>array(),'visited_urls'=>array(),
            'admin'=>array('url'=>'','menu'=>array(),'sections'=>array(),'submenus'=>array()),
            'pending_sections'=>array(),'active_section'=>array(),'tables'=>array(),'procedures'=>array(),
            'navigation'=>array(),'clicks'=>array(),'evidence'=>array(),'failures'=>array(),'twin'=>array(),
            'reuse'=>array('memory_reused'=>false,'incremental'=>false,'reason'=>''),
            'metrics'=>array(
                'crawl_runs'=>0,'batch_runs'=>0,'verified_batches'=>0,'degraded_batches'=>0,
                'wordpress_probe_runs'=>0,'wordpress_section_runs'=>0,'wordpress_pagination_clicks'=>0,
                'safe_clicks'=>0,'mutating_clicks'=>0,'screenshots'=>0,'incremental_skips'=>0,
                'table_pages_observed'=>0,'table_rows_observed'=>0,
            ),
            'capability_contract'=>array(),'created_gmt'=>gmdate('c'),'updated_gmt'=>gmdate('c'),
        );
    }

    private static function read( string $module_id, string $origin ): array {
        $raw = is_readable( self::module_path( $module_id ) ) ? (string) file_get_contents( self::module_path( $module_id ) ) : '';
        $decoded = '' !== $raw ? json_decode( $raw, true ) : array();
        $state = is_array( $decoded ) ? array_replace_recursive( self::defaults( $module_id, $origin ), $decoded ) : self::defaults( $module_id, $origin );
        $state['module_id'] = $module_id; $state['origin'] = $origin;
        return $state;
    }

    private static function atomic_write( string $path, string $contents ): bool {
        try { $suffix = bin2hex( random_bytes( 5 ) ); } catch ( Throwable $e ) { $suffix = str_replace( '.', '', uniqid( '', true ) ); }
        $tmp = dirname( $path ) . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
        if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { return false; }
        @chmod( $tmp, 0640 );
        if ( @rename( $tmp, $path ) ) { return true; }
        @unlink( $tmp ); return false;
    }

    private static function write_skill_md( array $state ): void {
        $lines = array(
            '---','name: ' . (string) $state['module_id'],
            'description: Verified reusable knowledge learned from the live site surface.',
            'version: ' . self::VERSION,'---','', '# Site module','',
            '- Mode: `' . (string)($state['mode']??'generic') . '`',
            '- Origin: `' . (string)$state['origin'] . '`',
            '- Surface hash: `' . (string)$state['surface_hash'] . '`',
            '- Revision: `' . (int)$state['revision'] . '`',
            '- Drift revalidation: `' . ( ! empty($state['drift']['revalidation_required']) ? 'required' : 'not-required' ) . '`',
            '','## Verified procedures'
        );
        foreach ( array_slice( array_values( (array)($state['procedures']??array()) ), 0, 100 ) as $procedure ) {
            $lines[] = '- **' . str_replace(array("\r","\n"),' ',(string)($procedure['label']??$procedure['id']??'procedure')) . '** — ' .
                str_replace(array("\r","\n"),' ',(string)($procedure['description']??'')) .
                ' (verified task `' . (string)($procedure['task_id']??'') . '`)';
        }
        $lines[]='';$lines[]='## Evidence';
        foreach ( array_slice( array_values( (array)($state['evidence']??array()) ), -40 ) as $row ) {
            $refs=array();
            foreach((array)($row['artifacts']??array()) as $artifact){
                if(!empty($artifact['artifact_id']))$refs[]='artifact:'.(string)$artifact['artifact_id'];
                elseif(!empty($artifact['sha256']))$refs[]='sha256:'.substr((string)$artifact['sha256'],0,16);
            }
            $lines[]='- '.(string)($row['observed_gmt']??'').' '.(string)($row['action']??'browser').' '.implode(', ',$refs);
        }
        self::atomic_write( self::skill_path( (string)$state['module_id'] ), implode("\n",$lines)."\n" );
    }

    private static function persist( array $state ): bool {
        $state['schema_version']=self::SCHEMA_VERSION;$state['version']=self::VERSION;
        $state['revision']=max(0,(int)($state['revision']??0))+1;$state['updated_gmt']=gmdate('c');
        $state=self::clean($state);
        $encoded=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(false===$encoded||strlen($encoded)>16777216)return false;
        $ok=self::atomic_write(self::module_path((string)$state['module_id']),$encoded."\n");
        if($ok)self::write_skill_md($state);return$ok;
    }

    private static function mutate( string $module_id, string $origin, callable $callback ) {
        if ( ! self::ensure_dir( $module_id ) ) { return new WP_Error('site_learning_dir_unavailable','Unable to create private site-learning directory.',array('status'=>503)); }
        $fh=@fopen(self::lock_path($module_id),'c+');
        if(!is_resource($fh)||!@flock($fh,LOCK_EX)){if(is_resource($fh))@fclose($fh);return new WP_Error('site_learning_lock_failed','Unable to lock site-learning module.',array('status'=>503));}
        try{$state=self::read($module_id,$origin);$result=$callback($state);if(is_wp_error($result))return$result;if(!self::persist($state))return new WP_Error('site_learning_write_failed','Unable to persist site-learning module.',array('status'=>503));return$result;}
        finally{@flock($fh,LOCK_UN);@fclose($fh);}
    }

    public static function capability_contract(): array {
        $rows=array();
        if(class_exists('PRSTUDIO_UC_Capability_Registry')&&method_exists('PRSTUDIO_UC_Capability_Registry','all')){
            foreach((array)PRSTUDIO_UC_Capability_Registry::all() as $row){if(!is_array($row))continue;$rows[]=array('id'=>(string)($row['id']??''),'browser_required'=>!empty($row['browser_required']),'read_only'=>!empty($row['read_only']));}
        }
        usort($rows,static fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
        return array('version'=>self::VERSION,'registry_available'=>!empty($rows),'count'=>count($rows),'excluded'=>array(),'all_capabilities_adopted'=>true,'rows'=>$rows,'hash'=>hash('sha256',(string)json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'execution_invariant'=>'Anti-Crash remains the only mutation guard; learning never authorizes execution.');
    }

    private static function risky_navigation( string $url ): bool {
        $parts=@parse_url($url);$subject=strtolower((string)($parts['path']??'').'?'.(string)($parts['query']??''));
        return 1===preg_match('/(?:^|[\/_?&=.-])(logout|log-out|signout|sign-out|delete|destroy|remove-account|unsubscribe|purchase|buy-now|checkout|payment|pay-now|publish|trash|revoke|reset-password|cancel-subscription|activate|deactivate|install|update)(?:$|[\/_?&=.-])/i',$subject);
    }

    private static function discovered_urls( $value, string $origin, int $limit ): array {
        $out=array();$queue=array($value);$cursor=0;$seen=0;
        while($cursor<count($queue)&&count($out)<$limit&&$seen<20000){
            $current=$queue[$cursor++];$seen++;
            if(is_string($current)){if(!preg_match('#^https?://#i',$current))continue;$normalized=self::normalize_url($current);if(''===$normalized||self::origin_for_url($normalized)!==$origin||self::risky_navigation($normalized))continue;$persistent=self::normalize_url($normalized,true);if(''!==$persistent)$out[$persistent]=$persistent;continue;}
            if(!is_array($current))continue;foreach(array_slice($current,0,5000,true) as $child)$queue[]=$child;
        }
        ksort($out,SORT_STRING);return array_values($out);
    }

    private static function artifact_refs( $value ): array {
        $refs=array();$queue=array($value);$cursor=0;$nodes=0;
        while($cursor<count($queue)&&$nodes<20000&&count($refs)<300){
            $current=$queue[$cursor++];$nodes++;if(!is_array($current))continue;
            $artifact=(string)($current['artifact_id']??'');$sha=(string)($current['sha256']??'');
            if(preg_match('/^[a-f0-9]{16,64}$/i',$artifact)||preg_match('/^[a-f0-9]{64}$/i',$sha)){$key=hash('sha256',$artifact.'|'.$sha);$refs[$key]=array('artifact_id'=>preg_match('/^[a-f0-9]{16,64}$/i',$artifact)?strtolower($artifact):'','sha256'=>preg_match('/^[a-f0-9]{64}$/i',$sha)?strtolower($sha):'');}
            foreach(array_slice($current,0,1500,true) as $child)if(is_array($child))$queue[]=$child;
        }
        return array_values($refs);
    }

    /**
     * What the browser flow actually handed back, described in a few hundred bytes.
     *
     * When the probe reports that WordPress admin was not observed, there are
     * three quite different causes and one message: the page really was not
     * wp-admin, the script ran and returned something unexpected, or the value
     * never survived the trip and flow_values() found nothing to read. Telling
     * them apart requires seeing the shape of the result, and the shape is
     * exactly what no log contained -- a full dump is far too large and is
     * redacted anyway, so nobody ever printed one.
     *
     * This records the skeleton only: which keys exist at each level, how many
     * step rows came back, and for each row whether it carried a value and what
     * `kind` that value declared. No page content, no attribute values, nothing
     * that could carry a token or a customer name.
     *
     * @param array $result Raw browser flow result.
     * @return array<string,mixed>
     */
    /** Scheme, host and path only -- the query is where nonces live. */
    private static function safe_location( $url ): string {
        $url = (string) $url;
        if ( '' === $url ) { return ''; }
        $parts = @parse_url( $url );
        if ( ! is_array( $parts ) ) { return substr( $url, 0, 60 ); }
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $host = (string) ( $parts['host'] ?? '' );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        return substr( $scheme . $host . $port . (string) ( $parts['path'] ?? '' ), 0, 200 );
    }

    private static function result_shape( array $result ): array {
        $rows = array();
        $candidates = array( $result );
        if ( isset( $result['result'] ) && is_array( $result['result'] ) ) { $candidates[] = $result['result']; }
        foreach ( $candidates as $candidate ) {
            if ( ! is_array( $candidate ) ) { continue; }
            foreach ( (array) ( $candidate['results'] ?? array() ) as $index => $row ) {
                if ( ! is_array( $row ) ) { $rows[] = array( 'index' => $index, 'row_type' => gettype( $row ) ); continue; }
                $inner = is_array( $row['result'] ?? null ) ? $row['result'] : array();
                $value = $inner['value'] ?? null;
                $rows[] = array(
                    'index'       => $index,
                    'row_keys'    => array_slice( array_keys( $row ), 0, 12 ),
                    'result_keys' => array_slice( array_keys( $inner ), 0, 12 ),
                    'has_value'   => array_key_exists( 'value', $inner ),
                    'value_type'  => is_array( $value ) ? 'array' : gettype( $value ),
                    'value_kind'  => is_array( $value ) ? (string) ( $value['kind'] ?? '' ) : '',
                    'value_keys'  => is_array( $value ) ? array_slice( array_keys( $value ), 0, 12 ) : array(),
                    // Where the step believed it was, with the query string
                    // removed. The probe reporting "this is not wp-admin" is
                    // only actionable next to the address it said it about --
                    // a login redirect, an about:blank that never navigated
                    // and a genuinely different site all produce the same
                    // false. The query is dropped because it is the part that
                    // carries nonces and one-time keys.
                    'where'       => self::safe_location( is_array( $value ) ? ( $value['url'] ?? '' ) : ( $inner['url'] ?? '' ) ),
                    'title'       => substr( (string) ( is_array( $value ) ? ( $value['title'] ?? '' ) : ( $inner['title'] ?? '' ) ), 0, 120 ),
                );
            }
        }
        return array(
            'top_keys'   => array_slice( array_keys( $result ), 0, 12 ),
            'flow'       => ! empty( $result['flow'] ),
            'step_count' => (int) ( $result['stepCount'] ?? 0 ),
            'row_count'  => count( $rows ),
            'rows'       => array_slice( $rows, 0, 10 ),
        );
    }

    private static function flow_values( array $result ): array {
        $out=array();$candidates=array($result);if(isset($result['result'])&&is_array($result['result']))$candidates[]=$result['result'];
        foreach($candidates as $candidate){if(!is_array($candidate))continue;foreach((array)($candidate['results']??array()) as $row){if(!is_array($row))continue;$r=is_array($row['result']??null)?$row['result']:array();if(array_key_exists('value',$r))$out[]=$r['value'];}if(array_key_exists('value',$candidate))$out[]=$candidate['value'];}
        return$out;
    }

    private static function value_of_kind( array $result, string $kind ): array {
        foreach(self::flow_values($result) as $value)if(is_array($value)&&(string)($value['kind']??'')===$kind)return$value;
        return array();
    }

    private static function wordpress_probe_script(): string {
        return <<<'JS'
(async () => {
  // Wait for the admin menu instead of sampling once.
  //
  // The probe used to read the DOM at whatever instant it happened to run and
  // report wordpress_admin:false if #adminmenu was not there yet. It reported
  // exactly that from a page whose own document.title was already
  // "Dashboard - ... - WordPress": the title lives in <head> and is set early,
  // the admin menu lives in <body> and is not, so a single sample taken
  // between the two sees a WordPress page with no WordPress in it.
  //
  // The whole study then stops -- no menu means no sections, no sections means
  // no tables -- and the module is written as studied_degraded with the reason
  // wordpress_admin_not_observed, which reads as "this is not WordPress" when
  // it means "I looked too early".
  //
  // Eight seconds is generous for a dashboard that has already fired load, and
  // it is bounded: a page that genuinely is not wp-admin still answers false,
  // just eight seconds later, once per study.
  const deadline = Date.now() + 8000;
  while (Date.now() < deadline && !(document.querySelector('#wpadminbar') && document.querySelector('#adminmenu'))) {
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  const safeHref = (href) => {
    try {
      const u = new URL(href || '', location.href);
      if (u.origin !== location.origin) return '';
      const s = (u.pathname + '?' + u.searchParams.toString()).toLowerCase();
      if (/(logout|delete|trash|activate|deactivate|install|update|publish|checkout|payment|reset-password)/.test(s)) return '';
      return u.href;
    } catch { return ''; }
  };
  const menu = [...document.querySelectorAll('#adminmenu > li')].map((li) => {
    const top = li.querySelector(':scope > a');
    const id = String(li.id || '');
    const href = safeHref(top?.href || '');
    const label = String(top?.querySelector('.wp-menu-name')?.textContent || top?.textContent || '').trim();
    const submenus = [...li.querySelectorAll('.wp-submenu a')].map((a) => ({label:String(a.textContent || '').trim(),href:safeHref(a.href || '')})).filter((x) => x.href);
    return { id, label, href, submenus };
  }).filter((x) => x.id && x.href);
  return {kind:'wordpress_probe',url:location.href,title:document.title,heading:String(document.querySelector('#wpbody-content h1')?.textContent || '').trim(),wordpress_admin:!!document.querySelector('#wpadminbar') && !!document.querySelector('#adminmenu'),menu};
})()
JS;
    }

    private static function wordpress_observation_script( string $section = '', int $expected_page = 0 ): string {
        $section_json=json_encode($section,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return '(async function(){const section='.$section_json.';const expectedPage='.max(0,$expected_page).';'.
<<<'JS'
// Wait for the list table, for the same reason the probe waits for the menu.
//
// This read the DOM once, at whatever moment it happened to run, and a
// section whose table had not been laid out yet reported zero tables. The
// study then visited all eight admin sections, clicked 78 times, took 79
// screenshots and persisted nothing -- every batch degraded, every table
// missing, and the module written as if WordPress had no lists in it.
//
// Six seconds, and only until the first list table appears. Pages that
// genuinely have none -- Settings, Tools -- pay the wait once each and still
// report zero, which is the correct answer for them.
const listDeadline = Date.now() + 6000;
while (Date.now() < listDeadline && !document.querySelector('table.wp-list-table')) {
  await new Promise((resolve) => setTimeout(resolve, 150));
}
// After a pagination click the table already exists, so waiting for one to
// appear returns instantly and reads the page that is being replaced. The
// study clicked "next page" sixteen times and recorded page 1 sixteen times:
// it knew total_pages was 2 and observed 1, because existence is not the
// state that changed. When a page is expected, wait for that page.
const readCurrentPage = () => {
  const nav = document.querySelector('.tablenav.top .tablenav-pages') || document.querySelector('.tablenav-pages');
  return Number(nav?.querySelector('.current-page')?.value || nav?.querySelector('.current-page')?.textContent || 0) || 0;
};
if (expectedPage > 0) {
  const pageDeadline = Date.now() + 8000;
  while (Date.now() < pageDeadline && readCurrentPage() !== expectedPage) {
    await new Promise((resolve) => setTimeout(resolve, 150));
  }
}
const clean=(v)=>String(v||'').replace(/\s+/g,' ').trim();
const tables=[...document.querySelectorAll('table.wp-list-table')].map((table,ti)=>{
  const headers=[...table.querySelectorAll('thead th, thead td')].map((h)=>clean(h.textContent)).filter(Boolean);
  const rows=[...table.querySelectorAll('tbody tr')].filter((tr)=>!tr.classList.contains('no-items')).map((tr)=>{
    const cells=[...tr.querySelectorAll('th,td')].map((c)=>clean(c.innerText||c.textContent));
    const checkbox=tr.querySelector('input[type=checkbox][value]');
    const primary=tr.querySelector('.column-primary strong a, .row-title, .column-title strong a, .column-name strong a');
    return {key:clean(checkbox?.value||tr.dataset.id||primary?.textContent||cells.join('|')),cells,primary:clean(primary?.textContent||'')};
  });
  const top=document.querySelector('.tablenav.top .tablenav-pages')||document.querySelector('.tablenav-pages');
  const current=Number(top?.querySelector('.current-page')?.value||top?.querySelector('.current-page')?.textContent||1)||1;
  const total=Number(clean(top?.querySelector('.total-pages')?.textContent||'1').replace(/[^0-9]/g,''))||1;
  const next=top?.querySelector('a.next-page');
  return {id:String(table.id||('wp-list-table-'+ti)),classes:String(table.className||''),headers,rows,pagination:{current,total,has_next:!!next && !next.classList.contains('disabled')}};
});
const controls=[...document.querySelectorAll('#wpbody-content a, #wpbody-content button')].slice(0,250).map((el)=>({tag:el.tagName.toLowerCase(),text:clean(el.textContent),href:el.href||'',id:el.id||'',classes:String(el.className||'')})).filter((x)=>x.text);
return {kind:'wordpress_section',section,url:location.href,title:document.title,heading:clean(document.querySelector('#wpbody-content h1')?.textContent||''),tables,controls};
})()
JS;
    }

    public static function wordpress_probe_steps( string $admin_url, string $origin ): array {
        return array(
            array('type'=>'navigate','url'=>$admin_url,'expectedOrigin'=>$origin,'expectedUrl'=>$admin_url,'waitUntil'=>'interactive','timeoutMs'=>45000),
            array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000),
            array('type'=>'javascript_exec','script'=>self::wordpress_probe_script(),'timeoutMs'=>30000),
            array('type'=>'screenshot','fullPage'=>true,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000),
            array('type'=>'click','selector'=>'#menu-posts > a','selectorType'=>'css','expectedOrigin'=>$origin,'timeoutMs'=>30000,'_prstudio_exploratory_read_only'=>true),
            array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000),
            array('type'=>'javascript_exec','script'=>self::wordpress_observation_script('posts'),'timeoutMs'=>30000),
            array('type'=>'screenshot','fullPage'=>true,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000),
        );
    }

    private static function safe_admin_section( array $row ): ?array {
        $id=(string)($row['id']??'');$href=(string)($row['href']??'');$label=trim((string)($row['label']??''));
        if(''===$id||''===$href||''===$label||!preg_match('/^[A-Za-z0-9_-]{1,120}$/',$id)||self::risky_navigation($href))return null;
        $allowed=in_array($id,array('menu-posts','menu-pages','menu-media','menu-comments','menu-users','menu-plugins','menu-tools','menu-settings'),true)||str_starts_with($id,'menu-posts-')||str_starts_with($id,'toplevel_page_');
        if(!$allowed)return null;
        return array('id'=>$id,'label'=>$label,'href'=>self::normalize_url($href),'selector'=>'#'.$id.' > a','submenus'=>array_values(array_filter((array)($row['submenus']??array()),static fn($s)=>is_array($s)&&!empty($s['href']))));
    }
    private static function section_key( array $section ): string {return preg_replace('/[^a-z0-9_-]+/','-',strtolower((string)($section['id']??'section')));}

    private static function wordpress_section_steps( array $section, string $origin ): array {
        $key=self::section_key($section);
        return array(
            array('type'=>'click','selector'=>(string)$section['selector'],'selectorType'=>'css','expectedOrigin'=>$origin,'timeoutMs'=>30000,'_prstudio_exploratory_read_only'=>true),
            array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000),
            array('type'=>'javascript_exec','script'=>self::wordpress_observation_script($key,$expected_page),'timeoutMs'=>30000),
            array('type'=>'screenshot','fullPage'=>true,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000),
        );
    }

    private static function wordpress_next_page_steps( array $section, string $origin, int $expected_page = 0 ): array {
        $key=self::section_key($section);
        return array(
            array('type'=>'click','selector'=>'.tablenav.top a.next-page, .tablenav-pages a.next-page','selectorType'=>'css','expectedOrigin'=>$origin,'timeoutMs'=>30000,'_prstudio_exploratory_read_only'=>true),
            array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000),
            array('type'=>'javascript_exec','script'=>self::wordpress_observation_script($key),'timeoutMs'=>30000),
            array('type'=>'screenshot','fullPage'=>true,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000),
        );
    }

    public static function flow_steps_for_urls( array $urls, string $origin ): array {
        $steps=array();
        foreach(array_slice(array_values($urls),0,self::MAX_BATCH_PAGES) as $url){
            $url=self::normalize_url((string)$url,true);if(''===$url||self::origin_for_url($url)!==$origin||self::risky_navigation($url))continue;
            $steps[]=array('type'=>'navigate','url'=>$url,'expectedOrigin'=>$origin,'expectedUrl'=>$url,'waitUntil'=>'interactive','timeoutMs'=>45000);
            $steps[]=array('type'=>'wait_load','state'=>'complete','timeoutMs'=>45000);
            $steps[]=array('type'=>'page_snapshot','includeInteractive'=>true);$steps[]=array('type'=>'accessibility_snapshot');
            $steps[]=array('type'=>'screenshot','fullPage'=>false,'lazyLoad'=>true,'format'=>'auto','quality'=>82,'maxPixels'=>28000000);
            $steps[]=array('type'=>'observation_bundle','includeScreenshot'=>true,'viewerOnly'=>false);$steps[]=array('type'=>'core_web_vitals');$steps[]=array('type'=>'network_report');$steps[]=array('type'=>'console_report');
        }
        return$steps;
    }

    public static function prepare_context( array $context ): array {
        $wordpress='wordpress'===(string)($context['study_target']??'')||!empty($context['wordpress_admin']);
        $url=self::normalize_url((string)($context['url']??$context['target']??''));
        if($wordpress&&''===$url&&function_exists('admin_url'))$url=self::normalize_url((string)admin_url('/'));
        if(''===$url&&function_exists('home_url'))$url=self::normalize_url((string)home_url('/'));
        $origin=self::origin_for_url($url);$module_id=self::module_id_for_url($url);
        $max_pages=self::clamp($context['max_pages']??self::DEFAULT_MAX_PAGES,1,self::MAX_PAGES,self::DEFAULT_MAX_PAGES);
        $max_depth=self::clamp($context['max_depth']??5,0,5,5);$concurrency=self::clamp($context['concurrency']??3,1,4,3);$delay_ms=self::clamp($context['delay_ms']??250,100,10000,250);$batch_pages=self::clamp($context['batch_pages']??self::DEFAULT_BATCH_PAGES,1,self::MAX_BATCH_PAGES,self::DEFAULT_BATCH_PAGES);
        $run_id='study-'.substr(hash('sha256',($origin?:'current-tab').'|'.microtime(true).'|'.random_int(0,PHP_INT_MAX)),0,20);
        $base=array('url'=>$url,'max_pages'=>$max_pages,'max_depth'=>$max_depth,'concurrency'=>$concurrency,'delay_ms'=>$delay_ms,'allow_cross_origin'=>false,'_prstudio_site_study'=>true,'_prstudio_site_module_id'=>$module_id,'_prstudio_site_origin'=>$origin,'_prstudio_site_run_id'=>$run_id,'_prstudio_site_batch_pages'=>$batch_pages,'_prstudio_site_max_pages'=>$max_pages,'_prstudio_site_read_only'=>true);
        if($wordpress){$base['_prstudio_wordpress_study']=true;$base['_prstudio_site_phase']='wordpress_probe';$base['steps']=self::wordpress_probe_steps($url,$origin);}
        if(''!==$module_id){
            self::mutate($module_id,$origin,static function(array &$state)use($run_id,$wordpress,$url){
                $contract=self::capability_contract();$previous_state=(string)($state['state']??'new');$state['previous_state_before_run']=$previous_state;$state['state']='discovering';$state['run_id']=$run_id;$state['active_task_id']='';$state['mode']=$wordpress?'wordpress_admin':'generic';$state['admin']['url']=$wordpress?$url:(string)($state['admin']['url']??'');
                $state['capability_contract']=array_intersect_key($contract,array_flip(array('version','registry_available','count','excluded','all_capabilities_adopted','hash','execution_invariant')));$state['reuse']=array('memory_reused'=>false,'incremental'=>false,'reason'=>'');
                $state['study_policy']=array('same_origin'=>true,'read_only'=>true,'safe_exploratory_clicks'=>$wordpress,'mutating_clicks'=>false,'screenshots'=>true);return array('ok'=>true);
            });
        }
        return array('url'=>$url,'origin'=>$origin,'module_id'=>$module_id,'run_id'=>$run_id,'crawler_arguments'=>$base,'initial_browser'=>array('action'=>$wordpress?'playwright_flow':'playwright_link_crawl','arguments'=>$base),'mode'=>$wordpress?'wordpress_admin':'generic');
    }

    private static function parent_waiting_for_task( array $task ): bool {
        $job_uuid=(string)($task['job_uuid']??'');if(''===$job_uuid||!class_exists('PRSTUDIO_UC_Store'))return false;$job=PRSTUDIO_UC_Store::get_job($job_uuid);if(!is_array($job)||'WAITING_FOR_BROWSER'!==(string)($job['status']??''))return false;$checkpoint=(array)($job['checkpoint']??array());$waiting=(string)($checkpoint['browser_task_id']??$checkpoint['waiting_browser_task_id']??'');return''===$waiting||hash_equals($waiting,(string)($task['task_uuid']??''));
    }

    private static function retarget_parent( array $task, array $child ): bool {
        $job_uuid=(string)($task['job_uuid']??'');$child_id=(string)($child['task_uuid']??'');if(''===$job_uuid||''===$child_id||!class_exists('PRSTUDIO_UC_Store'))return false;$job=PRSTUDIO_UC_Store::get_job($job_uuid);if(!is_array($job)||'WAITING_FOR_BROWSER'!==(string)($job['status']??''))return false;$checkpoint=(array)($job['checkpoint']??array());$checkpoint['browser_task_id']=$child_id;$checkpoint['browser_step_index']=(int)($checkpoint['browser_step_index']??$job['step_index']??0);$checkpoint['browser_deadline_gmt']=gmdate('c',time()+300);$checkpoint['site_learning_module_id']=(string)($task['arguments']['_prstudio_site_module_id']??'');return is_array(PRSTUDIO_UC_Store::set_job_state($job_uuid,'WAITING_FOR_BROWSER',array('checkpoint'=>$checkpoint)));
    }

    private static function queue_browser_flow( array $task, array &$state, string $phase, array $steps, array $extra=array() ): array {
        if(!$steps){$state['last_stop']=array('reason'=>'no_steps','phase'=>$phase,'gmt'=>gmdate('c'));return array('queued'=>false,'defer_parent'=>false);}
        // A continuation that cannot be queued leaves the study half done, and
        // it used to leave no trace of that at all: the module stayed in
        // 'studying' forever, indistinguishable from a study still running,
        // and the next reader had to guess whether to wait or to restart.
        //
        // The parent job only accepts a new child while it is
        // WAITING_FOR_BROWSER and still pointed at this task. Once the study
        // began doing real work -- waiting for menus, tables and page numbers
        // instead of sampling -- batches got slower and this guard started
        // firing, silently, mid-traversal.
        if(!self::parent_waiting_for_task($task)){
            $state['state']='interrupted';
            $state['last_stop']=array('reason'=>'parent_not_waiting_for_task','phase'=>$phase,'gmt'=>gmdate('c'),'sections_remaining'=>count((array)($state['pending_sections']??array())));
            $state['drift']['revalidation_required']=true;
            return array('queued'=>false,'defer_parent'=>false,'continuation_skipped'=>'parent_not_waiting_for_task');
        }
$module_id=(string)$state['module_id'];$origin=(string)$state['origin'];$index=(int)($state['metrics']['batch_runs']??0)+1;
        $args=array_merge(array('steps'=>$steps,'expected_origin'=>$origin,'_prstudio_site_study'=>true,'_prstudio_wordpress_study'=>true,'_prstudio_site_phase'=>$phase,'_prstudio_site_module_id'=>$module_id,'_prstudio_site_origin'=>$origin,'_prstudio_site_run_id'=>(string)$state['run_id'],'_prstudio_site_read_only'=>true,'_idempotency_key'=>'wordpress-study:'.$module_id.':'.(string)$state['run_id'].':'.$phase.':'.$index),$extra);
        $child=PRSTUDIO_UC_Job_Engine::create_browser_task('playwright_flow',$args,(string)($task['device_uuid']??'')?:null,(string)($task['job_uuid']??''));if(!is_array($child)||empty($child['task_uuid']))return array('queued'=>false,'defer_parent'=>false,'error'=>'site_learning_batch_queue_failed');if(!self::retarget_parent($task,$child))return array('queued'=>true,'defer_parent'=>false,'next_task_id'=>(string)$child['task_uuid'],'error'=>'site_learning_parent_retarget_failed');$state['active_task_id']=(string)$child['task_uuid'];$state['state']='studying';$state['metrics']['batch_runs']=$index;return array('queued'=>true,'defer_parent'=>true,'next_task_id'=>(string)$child['task_uuid'],'phase'=>$phase);
    }

    private static function queue_next_batch( array $task, string $module_id, string $origin, array &$state ): array {
        $pending=array_values(array_unique(array_filter((array)($state['pending_urls']??array()),'is_string')));if(!$pending)return array('queued'=>false,'defer_parent'=>false);if(!self::parent_waiting_for_task($task))return array('queued'=>false,'defer_parent'=>false,'continuation_skipped'=>'parent_not_waiting_for_task');$batch_pages=self::clamp($task['arguments']['_prstudio_site_batch_pages']??self::DEFAULT_BATCH_PAGES,1,self::MAX_BATCH_PAGES,self::DEFAULT_BATCH_PAGES);
        $steps=array();$batch=array();while($pending){$batch=array_slice($pending,0,$batch_pages);$steps=self::flow_steps_for_urls($batch,$origin);if($steps)break;$pending=array_values(array_diff($pending,$batch));$state['pending_urls']=$pending;}if(!$pending||empty($steps))return array('queued'=>false,'defer_parent'=>false);
        $batch_index=(int)($state['metrics']['batch_runs']??0)+1;$child_args=array('steps'=>$steps,'expected_origin'=>$origin,'_prstudio_site_study'=>true,'_prstudio_site_module_id'=>$module_id,'_prstudio_site_origin'=>$origin,'_prstudio_site_run_id'=>(string)$state['run_id'],'_prstudio_site_batch_pages'=>$batch_pages,'_prstudio_site_batch_index'=>$batch_index,'_prstudio_site_batch_urls'=>$batch,'_prstudio_site_read_only'=>true,'_idempotency_key'=>'site-study:'.$module_id.':'.(string)$state['run_id'].':batch:'.$batch_index);
        $child=PRSTUDIO_UC_Job_Engine::create_browser_task('playwright_flow',$child_args,(string)($task['device_uuid']??'')?:null,(string)($task['job_uuid']??''));if(!is_array($child)||empty($child['task_uuid']))return array('queued'=>false,'defer_parent'=>false,'error'=>'site_learning_batch_queue_failed');if(!self::retarget_parent($task,$child))return array('queued'=>true,'defer_parent'=>false,'next_task_id'=>(string)$child['task_uuid'],'error'=>'site_learning_parent_retarget_failed');$state['active_task_id']=(string)$child['task_uuid'];$state['state']='studying';$state['metrics']['batch_runs']=$batch_index;return array('queued'=>true,'defer_parent'=>true,'next_task_id'=>(string)$child['task_uuid'],'batch_urls'=>$batch);
    }

    private static function update_coverage( array &$state ): void {
        if('wordpress_admin'===(string)($state['mode']??'')){$sections=count((array)($state['admin']['sections']??array()));$done=0;foreach((array)($state['admin']['sections']??array()) as $row)if(!empty($row['visited']))$done++;$remaining=count((array)($state['pending_sections']??array()));$complete=$sections>0&&0===$remaining&&$done>=$sections;$state['coverage']=array('discovered'=>$sections,'visited'=>$done,'remaining'=>$remaining,'percent'=>$sections>0?round(min(1,$done/$sections)*100,2):0.0,'complete'=>$complete);return;}
        $discovered=count(array_unique((array)($state['discovered_urls']??array())));$visited=count(array_unique((array)($state['visited_urls']??array())));$remaining=count(array_unique((array)($state['pending_urls']??array())));$complete=$discovered>0&&0===$remaining&&$visited>=$discovered;$state['coverage']=array('discovered'=>$discovered,'visited'=>$visited,'remaining'=>$remaining,'percent'=>$discovered>0?round(min(1,$visited/$discovered)*100,2):0.0,'complete'=>$complete);
    }

    private static function evidence_row( array $task, array $result, array $verification ): array {
        return array('task_id'=>(string)($task['task_uuid']??''),'action'=>(string)($task['action']??''),'phase'=>(string)($task['arguments']['_prstudio_site_phase']??''),'verified'=>!empty($verification['ok']),'evidence_hash'=>hash('sha256',(string)json_encode(self::clean($result),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'artifacts'=>self::artifact_refs($result),'observed_gmt'=>gmdate('c'));
    }

    private static function table_key( string $section, array $table ): string {return preg_replace('/[^a-z0-9_-]+/','-',strtolower($section.'-'.(string)($table['id']??'table')));}
    private static function row_key( array $row ): string {$key=trim((string)($row['key']??''));if(''===$key)$key=(string)json_encode($row['cells']??array(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return hash('sha256',$key);}

    private static function merge_observation( array &$state, array $observation, array $task ): array {
        $section=(string)($observation['section']??self::section_key((array)($state['active_section']??array())));$url=self::normalize_url((string)($observation['url']??''));if(''!==$url)$state['navigation'][]=array('section'=>$section,'url'=>$url,'task_id'=>(string)($task['task_uuid']??''),'observed_gmt'=>gmdate('c'));$state['navigation']=array_slice($state['navigation'],-500);$table_summaries=array();
        foreach((array)($observation['tables']??array()) as $table){
            if(!is_array($table))continue;$key=self::table_key($section,$table);$existing=is_array($state['tables'][$key]??null)?$state['tables'][$key]:array('id'=>$key,'section'=>$section,'context'=>(string)($observation['heading']??$section),'headers'=>array(),'pages'=>array(),'rows'=>array(),'row_count'=>0,'page_count_observed'=>0,'total_pages'=>1,'urls'=>array(),'filters'=>array(),'evidence'=>array());
            $headers=array_values(array_unique(array_filter(array_map('strval',(array)($table['headers']??array())))));if($headers)$existing['headers']=$headers;$pagination=is_array($table['pagination']??null)?$table['pagination']:array();$page=max(1,(int)($pagination['current']??1));$total=max($page,(int)($pagination['total']??1));$page_rows=array();
            foreach((array)($table['rows']??array()) as $row){if(!is_array($row))continue;$rk=self::row_key($row);$existing['rows'][$rk]=(array)self::clean($row);$page_rows[]=$rk;if(count($existing['rows'])>=self::MAX_TABLE_ROWS)break;}
            $existing['pages'][(string)$page]=array('page'=>$page,'row_keys'=>$page_rows,'url'=>$url,'observed_gmt'=>gmdate('c'),'task_id'=>(string)($task['task_uuid']??''));if(''!==$url)$existing['urls'][$url]=$url;$existing['row_count']=count($existing['rows']);$existing['page_count_observed']=count($existing['pages']);$existing['total_pages']=$total;$existing['coverage_complete']=$existing['page_count_observed']>=$total;$state['tables'][$key]=$existing;$table_summaries[]=array('key'=>$key,'page'=>$page,'total'=>$total,'has_next'=>!empty($pagination['has_next']));
        }
        $state['metrics']['table_pages_observed']=array_sum(array_map(static fn($t)=>(int)($t['page_count_observed']??0),(array)$state['tables']));$state['metrics']['table_rows_observed']=array_sum(array_map(static fn($t)=>(int)($t['row_count']??0),(array)$state['tables']));return$table_summaries;
    }

    private static function twin_id( string $type, string $external ): string {$key=strtolower(trim($type));$key=(string)preg_replace('/[^a-z0-9._:-]+/','-',$key);$key=substr(trim($key,'-.'),0,40);return$key.':'.substr(hash('sha256',$type.'|'.$external),0,24);}

    private static function ingest_twin( array &$state, array $observation, array $task ): array {
        if(!class_exists('PRSTUDIO_UC_Operational_Twin'))return array('ok'=>false,'reason'=>'twin_unavailable');$origin=(string)$state['origin'];$section=(string)($observation['section']??'admin');$site_external=$origin;$section_external=$origin.'|admin|'.$section;
        $entities=array(array('type'=>'site','external_id'=>$site_external,'label'=>'WordPress site','url'=>$origin.'/','attributes'=>array('source'=>'browser_study')),array('type'=>'admin_section','external_id'=>$section_external,'label'=>(string)($observation['heading']??$section),'url'=>(string)($observation['url']??''),'attributes'=>array('section'=>$section)));
        $relations=array(array('from'=>self::twin_id('site',$site_external),'to'=>self::twin_id('admin_section',$section_external),'type'=>'has_admin_section'));
        foreach((array)($observation['tables']??array()) as $table){if(!is_array($table))continue;$table_external=$section_external.'|table|'.(string)($table['id']??'table');$entities[]=array('type'=>'list_table','external_id'=>$table_external,'label'=>(string)($observation['heading']??$section),'url'=>(string)($observation['url']??''),'attributes'=>array('headers'=>array_values((array)($table['headers']??array())),'total_pages'=>(int)($table['pagination']['total']??1)));$relations[]=array('from'=>self::twin_id('admin_section',$section_external),'to'=>self::twin_id('list_table',$table_external),'type'=>'contains');foreach((array)($table['headers']??array()) as $header){$header=trim((string)$header);if(''===$header)continue;$column_external=$table_external.'|column|'.$header;$entities[]=array('type'=>'column','external_id'=>$column_external,'label'=>$header,'attributes'=>array('list_table'=>(string)($table['id']??'')));$relations[]=array('from'=>self::twin_id('list_table',$table_external),'to'=>self::twin_id('column',$column_external),'type'=>'has_column');}}
        $prov=PRSTUDIO_UC_Operational_Twin::provenance('observed_live','site-study-browser-task',1.0,array('task_id'=>(string)($task['task_uuid']??''),'module_id'=>(string)$state['module_id']));$result=PRSTUDIO_UC_Operational_Twin::ingest($entities,$relations,$prov);$state['twin']=array('last_ingest'=>$result,'last_task_id'=>(string)($task['task_uuid']??''),'updated_gmt'=>gmdate('c'));return$result;
    }

    private static function remember_procedure( array &$state, array $section, array $observation, array $task ): void {
        $key=self::section_key($section);$label=(string)($section['label']??$key);$url=(string)($observation['url']??'');$procedure=array('id'=>'wordpress-'.$key,'label'=>'Open '.$label,'description'=>'From the authenticated WordPress admin, click '.$label.' using the verified admin menu selector and confirm the destination.','selector'=>(string)($section['selector']??''),'url'=>$url,'task_id'=>(string)($task['task_uuid']??''),'verified'=>true,'observed_gmt'=>gmdate('c'));$state['procedures'][$procedure['id']]=$procedure;
        if(class_exists('PRSTUDIO_UC_Memory')&&method_exists('PRSTUDIO_UC_Memory','remember')){$subject='';$lower=strtolower($label.' '.$key);foreach(array('users'=>'user','posts'=>'post','pages'=>'page','plugins'=>'plugin','comments'=>'comment','media'=>'media','settings'=>'setting','menus'=>'menu') as $s=>$needle){if(str_contains($lower,$needle)){$subject=$s;break;}}if(''!==$subject)PRSTUDIO_UC_Memory::remember('site_procedure',$subject,PRSTUDIO_UC_Memory::fingerprint($procedure),array('module_id'=>(string)$state['module_id'],'subject'=>$subject,'procedure'=>$procedure,'source'=>'verified_browser_study','memory_reused'=>false),90*86400);}
    }

    private static function surface_payload( array $probe, array $posts_observation ): array {
        $menu=array();foreach((array)($probe['menu']??array()) as $row){if(!is_array($row))continue;$safe=self::safe_admin_section($row);if(!$safe)continue;$menu[]=array('id'=>$safe['id'],'label'=>$safe['label'],'href'=>self::normalize_url((string)$safe['href'],true),'submenus'=>array_map(static fn($s)=>array('label'=>(string)($s['label']??''),'href'=>self::normalize_url((string)($s['href']??''),true)),(array)$safe['submenus']));}
        $tables=array();foreach((array)($posts_observation['tables']??array()) as $table){if(!is_array($table))continue;$row_keys=array();foreach((array)($table['rows']??array()) as $row)if(is_array($row))$row_keys[]=self::row_key($row);$tables[]=array('id'=>(string)($table['id']??''),'headers'=>array_values((array)($table['headers']??array())),'row_keys'=>$row_keys,'total_pages'=>(int)($table['pagination']['total']??1));}return array('menu'=>$menu,'posts_first_page'=>$tables);
    }

    /**
     * A short account of what each completion actually did.
     *
     * The study advances by chaining: every finished flow decides what to queue
     * next. When it stops early there is no single failure to look at -- the
     * chain simply does not continue, and every individual piece reports
     * success. Two browser tasks completed, the queue was empty, nothing was
     * dispatched, and coverage sat at 12.5% with no error anywhere.
     *
     * Twenty entries of phase, what was merged and what was queued is enough to
     * read the chain back and see which link returned nothing.
     *
     * @param array<string,mixed> $state  Module state, by reference.
     * @param string              $phase  Which flow completed.
     * @param array<string,mixed> $detail Compact facts, no page content.
     */
    private static function note( array &$state, string $phase, array $detail ): void {
        $state['study_log'] = array_slice( array_merge( (array) ( $state['study_log'] ?? array() ), array( array_merge( array( 'phase' => $phase, 'gmt' => gmdate( 'c' ) ), $detail ) ) ), -20 );
    }

    private static function queue_wordpress_next( array $task, array &$state, array $last_tables=array() ): array {
        $active=(array)($state['active_section']??array());self::note($state,'queue_next',array('tables_in'=>count($last_tables),'pending_sections'=>count((array)($state['pending_sections']??array())),'active'=>self::section_key($active)));foreach($last_tables as $summary){if(!is_array($summary))continue;if(!empty($summary['has_next'])&&(int)$summary['page']<(int)$summary['total']){$state['metrics']['wordpress_pagination_clicks']=(int)$state['metrics']['wordpress_pagination_clicks']+1;$state['metrics']['safe_clicks']=(int)$state['metrics']['safe_clicks']+1;return self::queue_browser_flow($task,$state,'wordpress_pagination',self::wordpress_next_page_steps($active,(string)$state['origin'],(int)$summary['page']+1),array('_prstudio_wordpress_section'=>$active));}}
        if($active){$key=self::section_key($active);if(isset($state['admin']['sections'][$key]))$state['admin']['sections'][$key]['visited']=true;}
        $pending=array_values((array)($state['pending_sections']??array()));if(!$pending){self::update_coverage($state);$state['state']='ready';$state['active_task_id']='';$state['drift']['revalidation_required']=false;return array('queued'=>false,'defer_parent'=>false);}
        $next=array_shift($pending);$state['pending_sections']=$pending;$state['active_section']=$next;$state['metrics']['safe_clicks']=(int)$state['metrics']['safe_clicks']+1;return self::queue_browser_flow($task,$state,'wordpress_section',self::wordpress_section_steps($next,(string)$state['origin']),array('_prstudio_wordpress_section'=>$next));
    }

    private static function handle_wordpress_completion( array $task, array $result, array $verification, array &$state ): array {
        $phase=(string)($task['arguments']['_prstudio_site_phase']??'wordpress_probe');$state['mode']='wordpress_admin';self::note($state,'completion',array('for_phase'=>$phase,'task'=>substr((string)($task['task_uuid']??''),0,8),'has_section_value'=>!empty(self::value_of_kind($result,'wordpress_section')),'has_probe_value'=>!empty(self::value_of_kind($result,'wordpress_probe'))));$state['metrics']['screenshots']=(int)$state['metrics']['screenshots']+count(self::artifact_refs($result));
        if('wordpress_probe'===$phase){
            $state['metrics']['wordpress_probe_runs']=(int)$state['metrics']['wordpress_probe_runs']+1;$state['metrics']['safe_clicks']=(int)$state['metrics']['safe_clicks']+1;$probe=self::value_of_kind($result,'wordpress_probe');$posts=self::value_of_kind($result,'wordpress_section');
            if(empty($probe['wordpress_admin'])){$state['state']='studied_degraded';$state['drift']['revalidation_required']=true;$state['last_probe_shape']=self::result_shape($result);return array('ok'=>false,'handled'=>true,'defer_parent'=>false,'reason'=>'wordpress_admin_not_observed','probe_shape'=>$state['last_probe_shape']);}
            $sections=array();foreach((array)($probe['menu']??array()) as $row){if(!is_array($row))continue;$safe=self::safe_admin_section($row);if(!$safe)continue;$key=self::section_key($safe);$safe['visited']=false;$sections[$key]=$safe;}
            $state['admin']['url']=self::normalize_url((string)($probe['url']??$state['admin']['url']));$state['admin']['menu']=array_values($sections);$state['admin']['sections']=$sections;$submenus=array();foreach($sections as $key=>$section)foreach((array)$section['submenus'] as $submenu)$submenus[]=array('parent'=>$key,'label'=>(string)($submenu['label']??''),'href'=>(string)($submenu['href']??''));$state['admin']['submenus']=$submenus;
            $surface_hash=hash('sha256',(string)json_encode(self::surface_payload($probe,$posts),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$previous=(string)($state['surface_hash']??'');$ready_before=in_array((string)($state['previous_state_before_run']??''),array('ready','studied_degraded'),true)||!empty($state['tables']);$same=''!==$previous&&hash_equals($previous,$surface_hash);$state['previous_surface_hash']=$previous;$state['surface_hash']=$surface_hash;$state['drift']=array('changed'=>''!==$previous&&!$same,'revalidation_required'=>''!==$previous&&!$same);
            $posts_section=$sections['menu-posts']??array('id'=>'menu-posts','label'=>'Posts','selector'=>'#menu-posts > a','href'=>'','submenus'=>array(),'visited'=>false);$state['active_section']=$posts_section;$table_summaries=$posts?self::merge_observation($state,$posts,$task):array();if($posts){self::ingest_twin($state,$posts,$task);self::remember_procedure($state,$posts_section,$posts,$task);if(isset($state['admin']['sections']['menu-posts']))$state['admin']['sections']['menu-posts']['visited']=true;}
            if($same&&$ready_before&&!empty($state['coverage']['complete'])){$state['state']='ready';$state['reuse']=array('memory_reused'=>true,'incremental'=>true,'reason'=>'surface_hash_unchanged');$state['metrics']['incremental_skips']=(int)$state['metrics']['incremental_skips']+1;$state['active_task_id']='';if(class_exists('PRSTUDIO_UC_Memory'))PRSTUDIO_UC_Memory::movement('site_learning.reused',array('resource'=>(string)$state['module_id'],'outcome'=>'ready','memory_reused'=>true,'reason'=>'surface_hash_unchanged'),(string)($task['job_uuid']??''));return array('ok'=>true,'handled'=>true,'defer_parent'=>false,'module_id'=>(string)$state['module_id'],'memory_reused'=>true,'incremental'=>true);}
            $pending=array();foreach($sections as $key=>$section){if('menu-posts'===$key)continue;$pending[]=$section;}$state['pending_sections']=$pending;$state['reuse']=array('memory_reused'=>false,'incremental'=>''!==$previous,'reason'=>$same?'coverage_incomplete':'surface_changed_or_first_study');self::update_coverage($state);return self::queue_wordpress_next($task,$state,$table_summaries)+array('ok'=>true,'handled'=>true,'module_id'=>(string)$state['module_id']);
        }
        $observation=self::value_of_kind($result,'wordpress_section');if(!$observation){$state['state']='studied_degraded';$state['drift']['revalidation_required']=true;$state['last_observation_shape']=self::result_shape($result);return array('ok'=>false,'handled'=>true,'defer_parent'=>false,'reason'=>'wordpress_observation_missing','observation_shape'=>$state['last_observation_shape']);}
        if('wordpress_section'===$phase)$state['metrics']['wordpress_section_runs']=(int)$state['metrics']['wordpress_section_runs']+1;$tables=self::merge_observation($state,$observation,$task);if(!$tables&&empty($state['tables']))$state['last_observation_shape']=array_merge(self::result_shape($result),array('note'=>'observation_present_but_no_tables','observation_keys'=>array_slice(array_keys($observation),0,12),'observation_table_count'=>count((array)($observation['tables']??array()))));self::ingest_twin($state,$observation,$task);$section=(array)($task['arguments']['_prstudio_wordpress_section']??$state['active_section']??array());if($section&&'wordpress_section'===$phase)self::remember_procedure($state,$section,$observation,$task);if(!empty($verification['ok']))$state['metrics']['verified_batches']=(int)$state['metrics']['verified_batches']+1;else{$state['metrics']['degraded_batches']=(int)$state['metrics']['degraded_batches']+1;$state['drift']['revalidation_required']=true;}self::update_coverage($state);$next=self::queue_wordpress_next($task,$state,$tables);if(empty($next['queued'])&&!empty($state['coverage']['complete']))$state['state']=((int)$state['metrics']['degraded_batches']>0)?'studied_degraded':'ready';return array_merge(array('ok'=>true,'handled'=>true,'module_id'=>(string)$state['module_id'],'state'=>(string)$state['state'],'coverage'=>$state['coverage'],'drift'=>$state['drift'],'memory_reused'=>!empty($state['reuse']['memory_reused'])),$next);
    }

    public static function after_browser_completion( array $task, array $result, array $verification ): array {
        $args=is_array($task['arguments']??null)?$task['arguments']:array();if(empty($args['_prstudio_site_study']))return array('handled'=>false,'defer_parent'=>false);$action=(string)($task['action']??'');$origin=(string)($args['_prstudio_site_origin']??'');if(''===$origin){$origin=(string)($result['origin']??'');if(''===$origin)$origin=self::origin_for_url((string)($result['seed_url']??$result['url']??$args['url']??''));}$origin=self::origin_for_url($origin.'/');if(''===$origin)return array('handled'=>true,'defer_parent'=>false,'reason'=>'origin_unresolved');$module_id=(string)($args['_prstudio_site_module_id']??'');if(''===$module_id)$module_id=self::module_id_for_url($origin.'/');$max_pages=self::clamp($args['_prstudio_site_max_pages']??$args['max_pages']??self::MAX_PAGES,1,self::MAX_PAGES,self::MAX_PAGES);
        $response=self::mutate($module_id,$origin,function(array &$state)use($task,$result,$verification,$action,$origin,$module_id,$max_pages,$args){$state['module_id']=$module_id;$state['origin']=$origin;if(''===(string)($state['run_id']??''))$state['run_id']=(string)($args['_prstudio_site_run_id']??'study-'.substr(hash('sha256',$origin.'|'.microtime(true)),0,20));$state['evidence']=array_slice(array_merge((array)($state['evidence']??array()),array(self::evidence_row($task,$result,$verification))),-self::MAX_EVIDENCE_ROWS);$__tid=(string)($task['task_uuid']??'');
            // One completion, delivered twice, used to end the study.
            //
            // The first delivery queues the next flow and retargets the parent
            // job at that child. A second delivery for the same finished task
            // then finds the parent pointing somewhere else, concludes it is
            // not being waited for, and marks a study that is actively
            // progressing as interrupted -- throwing away seven remaining
            // sections at 12.5% coverage. Redelivery is normal here: a
            // completion can arrive from the task path and again from a sweep.
            //
            // A task already accounted for is not new information. It keeps
            // the parent deferred, because the child it queued is still in
            // flight, and changes nothing else.
            if(''!==$__tid&&in_array($__tid,(array)($state['handled_tasks']??array()),true)){
                return array('ok'=>true,'handled'=>true,'defer_parent'=>''!==(string)($state['active_task_id']??''),'duplicate_completion'=>true,'module_id'=>(string)$state['module_id'],'state'=>(string)($state['state']??''));
            }
            if(''!==$__tid){$state['handled_tasks']=array_slice(array_merge((array)($state['handled_tasks']??array()),array($__tid)),-50);}
            if(!empty($args['_prstudio_wordpress_study']))return self::handle_wordpress_completion($task,$result,$verification,$state);
            if('playwright_link_crawl'===$action){$urls=self::discovered_urls($result,$origin,$max_pages);$seed=self::normalize_url((string)($args['url']??''),true);if(''!==$seed&&self::origin_for_url($seed)===$origin&&!self::risky_navigation($seed))array_unshift($urls,$seed);$persistent=array_values(array_unique(array_filter(array_map(static fn($u)=>self::normalize_url((string)$u,true),$urls))));sort($persistent,SORT_STRING);$surface_hash=hash('sha256',(string)json_encode($persistent,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$previous=(string)($state['surface_hash']??'');$state['previous_surface_hash']=$previous;$state['surface_hash']=$surface_hash;$state['drift']=array('changed'=>''!==$previous&&!hash_equals($previous,$surface_hash),'revalidation_required'=>''!==$previous&&!hash_equals($previous,$surface_hash));$state['discovered_urls']=$persistent;$already=array_fill_keys(array_map(static fn($u)=>self::normalize_url((string)$u,true),(array)($state['visited_urls']??array())),true);$state['pending_urls']=array_values(array_filter($persistent,static fn($u)=>!isset($already[$u])));$state['visited_urls']=array_values(array_intersect((array)($state['visited_urls']??array()),$persistent));$state['metrics']['crawl_runs']=(int)($state['metrics']['crawl_runs']??0)+1;$state['state']='studying';}
            elseif('playwright_flow'===$action){$batch=array_values(array_filter((array)($args['_prstudio_site_batch_urls']??array()),'is_string'));$visited=(array)($state['visited_urls']??array());foreach($batch as $url){$p=self::normalize_url($url,true);if(''!==$p)$visited[]=$p;}$state['visited_urls']=array_values(array_unique($visited));$done=array_fill_keys(array_map(static fn($u)=>self::normalize_url((string)$u,true),$batch),true);$state['pending_urls']=array_values(array_filter((array)($state['pending_urls']??array()),static fn($u)=>!isset($done[self::normalize_url((string)$u,true)])));if(!empty($verification['ok']))$state['metrics']['verified_batches']=(int)($state['metrics']['verified_batches']??0)+1;else{$state['metrics']['degraded_batches']=(int)($state['metrics']['degraded_batches']??0)+1;$state['drift']['revalidation_required']=true;}}
            self::update_coverage($state);$next=self::queue_next_batch($task,$module_id,$origin,$state);if(empty($next['queued'])){self::update_coverage($state);$complete=!empty($state['coverage']['complete']);$degraded=(int)($state['metrics']['degraded_batches']??0)>0;$state['state']=$complete?($degraded?'studied_degraded':'ready'):'studying';$state['active_task_id']='';if($complete&&!$degraded)$state['drift']['revalidation_required']=false;}return array_merge(array('ok'=>true,'handled'=>true,'module_id'=>$module_id,'state'=>(string)$state['state'],'coverage'=>$state['coverage'],'drift'=>$state['drift'],'memory_reused'=>!empty($state['reuse']['memory_reused'])),$next);});
        return is_wp_error($response)?array('handled'=>true,'defer_parent'=>false,'error'=>$response->get_error_code()):$response;
    }

    public static function after_browser_failure( array $task, array $error ): array {
        $args=is_array($task['arguments']??null)?$task['arguments']:array();if(empty($args['_prstudio_site_study']))return array('handled'=>false);$origin=(string)($args['_prstudio_site_origin']??'');if(''===$origin)$origin=self::origin_for_url((string)($args['url']??''));$module_id=(string)($args['_prstudio_site_module_id']??'');if(''===$module_id&&''!==$origin)$module_id=self::module_id_for_url($origin.'/');if(''===$module_id)return array('handled'=>true,'recorded'=>false);$r=self::mutate($module_id,$origin,static function(array &$state)use($task,$error){$state['state']='studied_degraded';$state['active_task_id']='';$state['drift']['revalidation_required']=true;$row=array('task_id'=>(string)($task['task_uuid']??''),'action'=>(string)($task['action']??''),'phase'=>(string)($task['arguments']['_prstudio_site_phase']??''),'code'=>(string)($error['code']??'browser_failure'),'observed_gmt'=>gmdate('c'));$state['failures']=array_slice(array_merge((array)($state['failures']??array()),array($row)),-self::MAX_FAILURES);return array('ok'=>true,'handled'=>true,'recorded'=>true);});return is_wp_error($r)?array('handled'=>true,'recorded'=>false):$r;
    }

    public static function status( string $url ): array {
        $origin=self::origin_for_url($url);$module_id=self::module_id_for_url($url);if(''===$module_id)return array('ok'=>false,'reason'=>'invalid_url');$state=self::read($module_id,$origin);$table_summary=array();foreach((array)$state['tables'] as $key=>$table)$table_summary[$key]=array('context'=>$table['context']??'','headers'=>$table['headers']??array(),'row_count'=>(int)($table['row_count']??0),'page_count_observed'=>(int)($table['page_count_observed']??0),'total_pages'=>(int)($table['total_pages']??1),'coverage_complete'=>!empty($table['coverage_complete']));return array('ok'=>true,'module_id'=>$module_id,'origin'=>$origin,'mode'=>$state['mode'],'state'=>$state['state'],'revision'=>$state['revision'],'surface_hash'=>$state['surface_hash'],'coverage'=>$state['coverage'],'drift'=>$state['drift'],'metrics'=>$state['metrics'],'tables'=>$table_summary,'procedures'=>array_values((array)$state['procedures']),'twin'=>$state['twin'],'reuse'=>$state['reuse'],'updated_gmt'=>$state['updated_gmt'],'capability_contract'=>$state['capability_contract']);
    }
}
