<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
final class WP_Error {
    public function __construct(private string $code='', private string $message='', private array $data=[]){ }
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function sanitize_text_field(string $v): string { return trim((string)preg_replace('/[\x00-\x1F\x7F]/u','',$v)); }
require dirname(__DIR__) . '/prstudio-unified-control/includes/class-wpaib-mcp.php';
function ok(bool $c,string $m): void { if(!$c){fwrite(STDERR,"FAIL {$m}\n");exit(1);} fwrite(STDOUT,"PASS {$m}\n"); }
$protocols=(new ReflectionMethod(WPAIB_MCP::class,'supported_protocols'))->invoke(null);
ok($protocols===['2025-11-25','2025-06-18','2025-03-26'],'legacy MCP advertises only implemented 2025 protocol generations');
$_SERVER['HTTP_MCP_PROTOCOL_VERSION']='2026-07-28';
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok(is_wp_error($r)&&$r->get_error_code()==='wpaib_mcp_protocol_unsupported','legacy initialize rejects final 2026 protocol header');
$_SERVER['HTTP_MCP_PROTOCOL_VERSION']='2025-11-25';
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok($r===true,'legacy initialize retains 2025-11-25 compatibility');
unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']);
$r=(new ReflectionMethod(WPAIB_MCP::class,'validate_protocol_header'))->invoke(null,'initialize');
ok($r===true,'legacy initialize retains headerless 2025-03-26 compatibility');
fwrite(STDOUT,"OK legacy MCP protocol surface smoke\n");
