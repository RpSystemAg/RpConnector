<?php

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);
define('ABSPATH', sys_get_temp_dir() . '/prstudio-op-root/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('DAY_IN_SECONDS', 86400);
@mkdir(WP_CONTENT_DIR, 0777, true);

final class WP_Error {
    public function __construct(private string $code, private string $message='', private array $data=[]) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_key($v): string { return trim((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$v)), '-_'); }
function sanitize_text_field($v): string { return trim(strip_tags((string)$v)); }
function sanitize_textarea_field($v): string { return trim(strip_tags((string)$v)); }
function sanitize_email($v): string { return filter_var((string)$v, FILTER_VALIDATE_EMAIL) ? (string)$v : ''; }
function esc_url_raw($v): string { return filter_var((string)$v, FILTER_VALIDATE_URL) ? (string)$v : ''; }
function esc_html($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($v): string { return (string)$v; }
function wp_strip_all_tags($v): string { return strip_tags((string)$v); }
function absint($v): int { return abs((int)$v); }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
function wp_rand($a,$b): int { return $a; }
function trailingslashit($v): string { return rtrim((string)$v,'/\\').'/'; }
function wp_json_encode($v,$flags=0): string { return (string)json_encode($v,$flags); }
function wp_upload_dir(): array { $base=ABSPATH.'uploads'; @mkdir($base,0777,true); return ['basedir'=>$base,'baseurl'=>'https://example.test/uploads','error'=>false]; }
function wp_mkdir_p($path): bool { return is_dir($path) || mkdir($path,0777,true); }
function sanitize_file_name($v): string { return preg_replace('/[^A-Za-z0-9._-]/','-',(string)$v); }
function get_permalink($id=0): string { return 'https://example.test/?p='.(int)$id; }
function home_url($path='/'): string { return 'https://example.test'.('/'===substr($path,0,1)?$path:'/'.$path); }
function wp_parse_url($url,$component=-1) { return parse_url($url,$component); }
function get_privacy_policy_url(): string { return 'https://example.test/privacy'; }
function is_ssl(): bool { return true; }
function is_admin(): bool { return false; }
function wp_unslash($v) { return $v; }
function add_query_arg($key,$value,$url): string { $sep=str_contains((string)$url,'?')?'&':'?'; return (string)$url.$sep.rawurlencode((string)$key).'='.rawurlencode((string)$value); }
function wp_safe_redirect($url,$status=302,$by='WordPress'): bool { $GLOBALS['redirects'][]=compact('url','status','by'); throw new RuntimeException('redirect:'.$url); }
function current_user_can($cap): bool { return true; }
function is_user_logged_in(): bool { return true; }
function wp_get_current_user(): object { return (object)['roles'=>['wholesale_customer']]; }
function get_option($key,$default=false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key,$value,$autoload=null): bool { $GLOBALS['opts'][$key]=$value; return true; }
function get_post_meta($id,$key,$single=false) { return $GLOBALS['meta'][$id][$key] ?? ($single ? '' : []); }
function update_post_meta($id,$key,$value): int { $GLOBALS['meta'][$id][$key]=$value; return 1; }
function get_user_meta($id,$key,$single=false) { return $GLOBALS['user_meta'][$id][$key] ?? ($single ? '' : []); }
function update_user_meta($id,$key,$value): int { $GLOBALS['user_meta'][$id][$key]=$value; return 1; }
function get_user_by($field,$value) { return (object)['ID'=>(int)$value,'user_email'=>'buyer@example.test','display_name'=>'Buyer','roles'=>['customer']]; }
function add_role($name,$label,$caps) { $GLOBALS['roles'][$name]=(object)['name'=>$name]; return $GLOBALS['roles'][$name]; }
function get_role($name) { return $GLOBALS['roles'][$name] ?? null; }
function add_action(...$args): void {}
function add_filter(...$args): void {}
function has_filter($hook): bool { return !empty($GLOBALS['filters'][$hook]); }
function apply_filters($hook,$value,...$args) { return isset($GLOBALS['filters'][$hook]) ? $GLOBALS['filters'][$hook]($value,...$args) : $value; }
function wp_mail($to,$subject,$message): bool { $GLOBALS['mail'][]=compact('to','subject','message'); return true; }
function wp_schedule_single_event($when,$hook,$args=[],$wp_error=false) { $GLOBALS['scheduled'][]=compact('when','hook','args'); return true; }
function wp_next_scheduled($hook,$args=[]) { foreach (($GLOBALS['scheduled']??[]) as $e) if ($e['hook']===$hook && $e['args']===$args) return $e['when']; return false; }
function wc_get_price_decimals(): int { return 2; }
function get_woocommerce_currency(): string { return 'EUR'; }
function wc_price($v,$args=[]): string { return '€'.number_format((float)$v,2,'.',''); }
function wc_add_notice($message,$type='success'): void { $GLOBALS['notices'][]=[$type,$message]; }
function wc_get_order_statuses(): array { return ['wc-completed'=>'Completed']; }
function wc_get_orders($args=[]): array { return []; }
function wc_get_products($args=[]): array { return [1]; }
function wp_insert_post($data,$wp_error=false) { $GLOBALS['insert_post_calls']=($GLOBALS['insert_post_calls']??0)+1; return 777; }
function get_post($id) { return (object)['ID'=>$id,'post_content'=>'','post_status'=>'draft']; }
function wp_slash($v) { return $v; }
function maybe_serialize($v): string { return serialize($v); }
function wp_safe_remote_get($url,$args=[]) { return new WP_Error('network_disabled','network disabled'); }
function wp_safe_remote_head($url,$args=[]) { return new WP_Error('network_disabled','network disabled'); }
function wp_remote_retrieve_response_code($r): int { return 0; }
function wp_remote_retrieve_body($r): string { return ''; }

final class FakeProduct {
    public function __construct(private int $id) {}
    public function get_id(): int { return $this->id; }
    public function get_sku(): string { return 'SKU'.$this->id; }
    public function get_name(): string { return 'Product '.$this->id; }
    public function get_price(): string { return '100'; }
    public function get_stock_status(): string { return 'instock'; }
}
final class FakeOrder {
    public array $notes=[];
    public function __construct(private int $id) {}
    public function get_id(): int { return $this->id; }
    public function get_billing_email(): string { return 'buyer@example.test'; }
    public function get_order_number(): string { return (string)$this->id; }
    public function get_meta($key,$single=true) { return ''; }
    public function add_order_note($note): void { $this->notes[]=$note; }
}
function wc_get_product($id) { return (int)$id===1 ? new FakeProduct(1) : false; }
$GLOBALS['orders']=[55=>new FakeOrder(55)];
function wc_get_order($id) { return $GLOBALS['orders'][(int)$id] ?? false; }

final class FakeCart { public function get_subtotal(): float { return 50.0; } }
final class FakeWC { public FakeCart $cart; public function __construct(){ $this->cart=new FakeCart(); } }
function WC(): FakeWC { static $wc; return $wc ??= new FakeWC(); }

require_once dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-agency-action-executor.php';

function ok(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }

$GLOBALS['opts']=[];$GLOBALS['meta']=[];$GLOBALS['roles']=[];$GLOBALS['filters']=[];$GLOBALS['mail']=[];$GLOBALS['scheduled']=[];$GLOBALS['notices']=[];$GLOBALS['redirects']=[];$GLOBALS['insert_post_calls']=0;

// 1) Multi-currency must affect the runtime WooCommerce price, not only store metadata.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('multi_currency_price_set',['product_id'=>1,'prices'=>['EUR'=>80,'USD'=>90]]);
ok(!is_wp_error($r) && !empty($r['verified']), 'multi-currency write verified');
ok(80.0===PRSTUDIO_UC_Agency_Action_Executor::filter_commerce_price('100',new FakeProduct(1)), 'multi-currency runtime price applied');

// 2) Wholesale configuration must alter runtime pricing and enforce minimum order.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('b2b_wholesale_portal_manage',['enabled'=>true,'discount_percent'=>10,'minimum_order'=>75]);
ok(!is_wp_error($r) && !empty($r['verified']), 'wholesale configuration verified');
ok(72.0===PRSTUDIO_UC_Agency_Action_Executor::filter_commerce_price('100',new FakeProduct(1)), 'wholesale discount composes after currency price');
PRSTUDIO_UC_Agency_Action_Executor::enforce_wholesale_minimum_order();
ok(count($GLOBALS['notices'])===1 && $GLOBALS['notices'][0][0]==='error', 'wholesale minimum order enforced');

// 3) Marketplace/data warehouse sync must never fake external synchronization.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('marketplace_catalog_sync',[]);
ok(is_wp_error($r) && $r->get_error_code()==='prstudio_marketplace_provider_required', 'marketplace sync requires real provider rather than export-as-success');
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('data_warehouse_sync',[]);
ok(is_wp_error($r) && $r->get_error_code()==='prstudio_warehouse_provider_required', 'warehouse sync requires real provider rather than export-as-success');

// 4) Press distribution missing a provider must not leave a hidden partial post mutation.
$before=$GLOBALS['insert_post_calls'];
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('press_release_distribute',['title'=>'Release','content'=>'Body']);
ok(is_wp_error($r) && $r->get_error_code()==='prstudio_press_distribution_provider_required', 'press distribution provider precondition');
ok($GLOBALS['insert_post_calls']===$before, 'press distribution does not create a draft before provider check');

// 5) Review automation supports durable scheduling, not only immediate mail.
$future=time()+3600;
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('review_request_automate',['order_id'=>55,'send_at'=>$future]);
ok(!is_wp_error($r) && !empty($r['verified']) && ($r['next_run']??0)===$future, 'review request schedules a verified cron event');

// 6) Local live-chat handoff has a real delivery path when no third-party provider exists.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('live_chat_handoff',['customer_id'=>4,'context'=>'Need help','notify_email'=>'admin@example.test']);
ok(!is_wp_error($r) && !empty($r['verified']), 'local chat handoff state+mail verified');
ok(count($GLOBALS['mail'])===1, 'local chat handoff sends a notification');

// 7) A/B test creation must configure an actual same-origin runtime router and persist exposure evidence.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('ab_test_create',['experiment_id'=>'hero','status'=>'active','target_path'=>'/landing','variants'=>[['key'=>'a','url'=>'https://example.test/landing-a','weight'=>1],['key'=>'b','url'=>'https://example.test/landing-b','weight'=>1]]]);
ok(!is_wp_error($r) && !empty($r['verified']) && !empty($r['experiment']['runtime_router_active']), 'A/B active experiment has verified runtime router state');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/landing';$_COOKIE=[];$_GET=[];
try { PRSTUDIO_UC_Agency_Action_Executor::apply_ab_tests(); } catch (RuntimeException $e) { /* redirect stub intentionally interrupts before exit */ }
ok(count($GLOBALS['redirects'])===1 && str_starts_with($GLOBALS['redirects'][0]['url'],'https://example.test/landing-a'), 'A/B runtime routes request to a concrete variant');
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('ab_test_status',['experiment_id'=>'hero']);
ok(!is_wp_error($r) && (($r['variant_metrics']['a']['exposures']??0)===1), 'A/B runtime records exposure evidence');
ok((float)($r['variant_metrics']['a']['conversion_rate']??-1)===0.0, 'A/B status computes variant metrics');

// 8) Manage/track/onboarding state families expose a real lifecycle rather than write-only JSON.
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('marketplace_onboarding',['operation'=>'upsert','marketplace'=>'amazon-it','status'=>'planning','owner'=>'commerce']);
ok(!is_wp_error($r) && !empty($r['verified']) && ($r['record']['status']??'')==='planning', 'marketplace onboarding lifecycle upsert verified');
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('marketplace_onboarding',['operation'=>'transition','marketplace'=>'amazon-it','status'=>'ready','note'=>'Checks complete']);
ok(!is_wp_error($r) && ($r['record']['status']??'')==='ready' && count($r['record']['history']??[])===1, 'marketplace onboarding lifecycle transition verified');
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('marketplace_onboarding',['operation'=>'list']);
ok(!is_wp_error($r) && ($r['count']??0)===1, 'marketplace onboarding lifecycle list is operational');
$r=PRSTUDIO_UC_Agency_Action_Executor::execute('marketplace_onboarding',['operation'=>'delete','marketplace'=>'amazon-it']);
ok(!is_wp_error($r) && !empty($r['verified']) && !empty($r['deleted']), 'marketplace onboarding lifecycle delete verified');

fwrite(STDOUT,"PHP operational completeness smoke: 20 assertions passed\n");
