<?php
/**
 * Emit the canonical legacy MCP tool catalog for deterministic artifact builds.
 *
 * This intentionally loads only the self-contained WPAIB catalog. Enterprise
 * tools are generated from their own registry and must not be duplicated here.
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'PRSTUDIO_UC_PATH', dirname( __DIR__ ) . '/prstudio-unified-control/' );
define( 'PRSTUDIO_UC_DIR', PRSTUDIO_UC_PATH );
define( 'WPAIB_DIR', PRSTUDIO_UC_PATH );

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WPAIB_Audit' ) ) {
	final class WPAIB_Audit {
		public static function log( ...$arguments ): void {}
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string {
		$value = strtolower( (string) $value );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ): array {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ): string { return rtrim( (string) $value, "/\\" ) . '/'; }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}

require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-agency.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/class-prstudio-uc-domain-abstract.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-browser.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-content-seo.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-catalog-commerce.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-orders-customers.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-media-stories.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-experience-ui.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-extensions-themes.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-data-storage.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-security-identity.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/domains/class-prstudio-domain-operations.php';
require_once PRSTUDIO_UC_DIR . 'includes/orchestrator/class-prstudio-uc-orchestrator.php';
require_once PRSTUDIO_UC_DIR . 'includes/class-prstudio-uc-anti-crash.php';

require_once dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-wpaib-mcp.php';

echo "---JSON---\n";
echo json_encode(
	WPAIB_MCP::all_tools(),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
echo "\n";
