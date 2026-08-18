<?php
const PRSTUDIO_UC_TESTING = true;
const ABSPATH = __DIR__ . '/';
const OBJECT = 'OBJECT';
const PHP_URL_HOST_CONST = PHP_URL_HOST;

class WP_Error {
    private string $code; private string $message; private $data;
    public function __construct($code='',$message='',$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
class WP_Post {
    public int $ID=0; public string $post_type='post'; public string $post_status='draft'; public string $post_title=''; public string $post_name=''; public string $post_content=''; public string $post_excerpt='';
}
$GLOBALS['posts']=[]; $GLOBALS['meta']=[]; $GLOBALS['next_id']=100; $GLOBALS['public_ok']=true;
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_strip_all_tags($s){return strip_tags($s);} function sanitize_title($s){return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$s),'-'));}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));} function sanitize_text_field($s){return trim(strip_tags($s));} function sanitize_textarea_field($s){return trim(strip_tags($s));}
function esc_url_raw($s){return $s;} function esc_url($s){return $s;} function absint($x){return abs((int)$x);} function wp_kses_post($s){return $s;} function wp_slash($x){return $x;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);} function home_url($p='/'){return 'https://example.test'.('/'===substr($p,0,1)?$p:'/'.$p);} function get_current_user_id(){return 1;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function post_type_exists($t){return in_array($t,['post','page'],true);} function taxonomy_exists($t){return true;} function wp_attachment_is_image($id){return $id>0;} function set_post_thumbnail($id,$img){return true;}
function get_posts($a){$out=[];foreach($GLOBALS['posts'] as $p){if(isset($a['meta_key']) && (($GLOBALS['meta'][$p->ID][$a['meta_key']]??null)!==($a['meta_value']??null)))continue;$out[]=$p;}return array_slice($out,0,(int)($a['posts_per_page']??10));}
function get_page_by_path($slug,$output,$type){foreach($GLOBALS['posts'] as $p) if($p->post_name===$slug && $p->post_type===$type)return $p; return null;}
function wp_insert_post($a,$error=false){$p=new WP_Post();$p->ID=$GLOBALS['next_id']++;$p->post_type=$a['post_type']??'post';$p->post_status=$a['post_status']??'draft';$p->post_title=$a['post_title']??'';$p->post_name=$a['post_name']??'';$p->post_content=$a['post_content']??'';$p->post_excerpt=$a['post_excerpt']??'';$GLOBALS['posts'][$p->ID]=$p;return $p->ID;}
function get_post($id){return $GLOBALS['posts'][(int)$id]??null;} function update_post_meta($id,$k,$v){$GLOBALS['meta'][$id][$k]=$v;return true;} function wp_set_post_terms(){return true;} function clean_post_cache(){}
function wp_update_post($a){$p=get_post((int)$a['ID']);if(!$p)return 0; foreach(['post_status','post_title','post_content'] as $k) if(isset($a[$k]))$p->$k=$a[$k];return $p->ID;}
function get_permalink($p){$id=$p instanceof WP_Post?$p->ID:(int)$p;$post=get_post($id);return 'https://example.test/'.($post?($post->post_name?:$id):$id).'/';}
function wp_remote_get($url,$args=[]){
    if(str_contains($url,'wp-sitemap-posts-')){ if(str_ends_with($url,'-1.xml')) return ['code'=>200,'body'=>'<urlset><loc>https://example.test/editorial-test/</loc></urlset>']; return ['code'=>404,'body'=>'']; }
    $body=$GLOBALS['public_ok']?'<html><head><link rel="canonical" href="https://example.test/editorial-test/"><meta name="robots" content="index,follow"></head><body>Editorial Test</body></html>':'<html><body>missing marker</body></html>';
    return ['code'=>200,'body'=>$body];
}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}

final class PRSTUDIO_UC_Execution_Lanes { public static function guard($token,$client,$resource,$mutate){ return ($token==='lane'&&$client==='client')?['ok'=>true]:new WP_Error('lane','bad lane'); } }
final class PRSTUDIO_UC_Editorial_Autonomy {
    public static array $watch=[];
    public static function claim_ledger($a){return ['verified'=>true];}
    public static function post_publish_watcher($a){self::$watch[]=$a;return ['ok'=>true];}
}
require __DIR__.'/../prstudio-unified-control/includes/class-prstudio-uc-publish-transaction.php';

function ok($cond,$msg){echo ($cond?'PASS ':'FAIL ').$msg."\n";if(!$cond)$GLOBALS['failed']=true;}
$args=['lane_token'=>'lane','_client_id'=>'client','title'=>'Editorial Test','slug'=>'editorial-test','content'=>'<p>Editorial Test</p>','primary_keyword'=>'vino','campaign_id'=>'vino','idempotency_key'=>'pub-1'];
$r=PRSTUDIO_UC_Publish_Transaction::create_publish($args);
ok(!is_wp_error($r) && !empty($r['verified']), 'publish transaction completes only after DB + public render');
ok(!empty($r['receipt']['sitemap_verified']), 'publish receipt verifies sitemap presence');
ok(count(PRSTUDIO_UC_Editorial_Autonomy::$watch)===1, 'successful publish creates post-publish watcher');
$r2=PRSTUDIO_UC_Publish_Transaction::create_publish($args);
ok(!is_wp_error($r2) && !empty($r2['idempotent_reuse']) && $r2['post_id']===$r['post_id'], 'publish transaction is idempotent');
$GLOBALS['public_ok']=false;
$bad=$args;$bad['slug']='editorial-fail';$bad['title']='Editorial Fail';$bad['content']='<p>Editorial Fail</p>';$bad['idempotency_key']='pub-2';
$r3=PRSTUDIO_UC_Publish_Transaction::create_publish($bad);
ok(!is_wp_error($r3) && ($r3['state']??'')==='PUBLIC_PERSISTED_UNVERIFIED' && !empty($r3['degraded']) && empty($r3['blocking']), 'public verification absence is evidence-degraded, not a mutation veto');
$badPost=end($GLOBALS['posts']);
ok($badPost->post_status==='publish', 'persisted public publish is never rolled back solely because public observation was unavailable');
exit(!empty($GLOBALS['failed'])?1:0);
