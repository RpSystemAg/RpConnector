<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPAIB_Site {
	public static function status(): array {
		global $wpdb;
		$theme = wp_get_theme();
		return array(
			'bridge_version' => WPAIB_VERSION,
			'product_name' => 'PR STUDIO AI BRIDGE',
			'site_name' => get_bloginfo( 'name' ),
			'home_url' => home_url( '/' ),
			'site_url' => site_url( '/' ),
			'wordpress' => get_bloginfo( 'version' ),
			'php' => PHP_VERSION,
			'multisite' => is_multisite(),
			'active_theme' => array( 'name' => $theme->get( 'Name' ), 'stylesheet' => $theme->get_stylesheet(), 'version' => $theme->get( 'Version' ) ),
			'database' => array( 'server' => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '', 'charset' => $wpdb->charset ),
			'root' => wp_normalize_path( ABSPATH ),
			'backup_root' => wp_normalize_path( WPAIB_Files::backup_root() ),
			'capabilities' => array(
				'file_write' => true,
				'plugin_actions' => true,
				'theme_switch' => true,
				'content_write' => true,
				'enterprise_execution' => true,
			),
			'mcp_url' => WPAIB_Auth::mcp_url(),
			'market_profile' => array( 'country' => 'IT', 'region' => 'Sicilia', 'province' => 'Agrigento' ),
		);
	}

	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$plugins = get_plugins(); $active = (array) get_option( 'active_plugins', array() ); $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array(); $items = array();
		foreach ( $plugins as $file => $data ) {
			$dir = dirname( $file );
			$slug = '.' === $dir ? pathinfo( $file, PATHINFO_FILENAME ) : $dir;
			$items[] = array( 'plugin' => $file, 'slug' => $slug, 'name' => $data['Name'] ?? $file, 'version' => $data['Version'] ?? '', 'author' => wp_strip_all_tags( $data['Author'] ?? '' ), 'active' => in_array( $file, $active, true ) || in_array( $file, $network, true ), 'network_active' => in_array( $file, $network, true ), 'requires_php' => $data['RequiresPHP'] ?? '', 'requires_wp' => $data['RequiresWP'] ?? '' );
		}
		return array( 'items' => $items, 'count' => count( $items ) );
	}

	public static function set_plugin_state( string $plugin, string $action ) {
		if ( ! function_exists( 'activate_plugin' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$plugin = plugin_basename( sanitize_text_field( $plugin ) );
		if ( ! isset( get_plugins()[ $plugin ] ) ) { return new WP_Error( 'wpaib_plugin_missing', 'Plugin non trovato.', array( 'status' => 404 ) ); }
		if ( 'deactivate' === $action && $plugin === WPAIB_BASENAME ) { return new WP_Error( 'wpaib_anti_crash_self_deactivation', 'Il bridge non può disattivare sé stesso.', array( 'status' => 409 ) ); }
		$before = is_plugin_active( $plugin );
		if ( 'activate' === $action ) { $result = activate_plugin( $plugin, '', is_multisite() && is_network_only_plugin( $plugin ), false ); if ( is_wp_error( $result ) ) { return $result; } }
		elseif ( 'deactivate' === $action ) { deactivate_plugins( $plugin, false, is_multisite() && is_plugin_active_for_network( $plugin ) ); }
		else { return new WP_Error( 'wpaib_plugin_action_invalid', 'Azione plugin non valida.', array( 'status' => 400 ) ); }
		$after = is_plugin_active( $plugin );
		WPAIB_Audit::log( 'site.plugin.' . $action, 'success', $plugin, array( 'before' => $before, 'after' => $after ) );
		PRSTUDIO_Report::record_change( 'Plugin', $plugin, array( 'active' => $before ), array( 'active' => $after ) );
		return array( 'plugin' => $plugin, 'action' => $action, 'before_active' => $before, 'after_active' => $after );
	}

	public static function themes(): array {
		$themes = wp_get_themes(); $active = get_stylesheet(); $items = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$errors = $theme->errors();
			$items[] = array( 'stylesheet' => $stylesheet, 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'active' => $stylesheet === $active, 'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null, 'errors' => is_wp_error( $errors ) ? $errors->get_error_messages() : array() );
		}
		return array( 'items' => $items, 'count' => count( $items ), 'active' => $active );
	}

	public static function switch_theme( string $stylesheet ) {
		$stylesheet = sanitize_text_field( $stylesheet ); $theme = wp_get_theme( $stylesheet ); if ( ! $theme->exists() || $theme->errors() ) { return new WP_Error( 'wpaib_theme_invalid', 'Tema non valido.', array( 'status' => 400 ) ); }
		$before = get_stylesheet(); switch_theme( $stylesheet ); $after = get_stylesheet();
		WPAIB_Audit::log( 'site.theme.switch', 'success', $stylesheet, array( 'before' => $before, 'after' => $after ) );
		PRSTUDIO_Report::record_change( 'Tema', $stylesheet, array( 'stylesheet' => $before ), array( 'stylesheet' => $after ) );
		return array( 'before' => $before, 'after' => $after );
	}

	private static function content_item( WP_Post $post ): array {
		return array( 'id' => $post->ID, 'post_type' => $post->post_type, 'status' => $post->post_status, 'title' => get_the_title( $post ), 'slug' => $post->post_name, 'excerpt' => $post->post_excerpt, 'content' => $post->post_content, 'author_id' => (int) $post->post_author, 'parent_id' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order, 'comment_status' => $post->comment_status, 'ping_status' => $post->ping_status, 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt, 'template' => (string) get_post_meta( $post->ID, '_wp_page_template', true ), 'permalink' => get_permalink( $post ), 'modified_gmt' => get_post_modified_time( DATE_ATOM, true, $post ) );
	}

	public static function list_content( array $args ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) ); $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) ); $post_type = sanitize_key( (string) ( $args['post_type'] ?? 'page' ) );
		if ( ! post_type_exists( $post_type ) ) { return array( 'items' => array(), 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0 ); }
		$query = new WP_Query( array( 'post_type' => $post_type, 'post_status' => sanitize_text_field( (string) ( $args['status'] ?? 'any' ) ), 'paged' => $page, 'posts_per_page' => $per_page, 's' => sanitize_text_field( (string) ( $args['search'] ?? '' ) ), 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => false ) );
		return array( 'items' => array_map( array( self::class, 'content_item' ), $query->posts ), 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages );
	}

	public static function get_content( int $id ) {
		$post = get_post( $id ); return $post ? self::content_item( $post ) : new WP_Error( 'wpaib_content_missing', 'Contenuto non trovato.', array( 'status' => 404 ) );
	}

	public static function update_content( array $args ) {
		$id = absint( $args['id'] ?? 0 ); $before = $id ? self::get_content( $id ) : null;
		if ( $id && is_wp_error( $before ) ) { return $before; }
		if ( $id && ! empty( $args['expected_modified_gmt'] ) && (string) $args['expected_modified_gmt'] !== (string) $before['modified_gmt'] ) { return new WP_Error( 'wpaib_content_conflict', 'Il contenuto è stato modificato nel frattempo.', array( 'status' => 409, 'current' => $before ) ); }
		$data = array(); if ( $id ) { $data['ID'] = $id; } else { $data['post_type'] = sanitize_key( (string) ( $args['post_type'] ?? 'page' ) ); }
		$map = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'status' => 'post_status', 'comment_status' => 'comment_status', 'ping_status' => 'ping_status', 'date' => 'post_date', 'date_gmt' => 'post_date_gmt' );
		foreach ( $map as $input => $field ) { if ( array_key_exists( $input, $args ) ) { $data[ $field ] = in_array( $input, array( 'content', 'excerpt' ), true ) ? wp_kses_post( (string) $args[ $input ] ) : sanitize_text_field( (string) $args[ $input ] ); } }
		foreach ( array( 'parent_id' => 'post_parent', 'menu_order' => 'menu_order', 'author_id' => 'post_author' ) as $input => $field ) { if ( array_key_exists( $input, $args ) ) { $data[ $field ] = absint( $args[ $input ] ); } }
		$result = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true ); if ( is_wp_error( $result ) ) { return $result; }
		if ( array_key_exists( 'template', $args ) ) { update_post_meta( (int) $result, '_wp_page_template', sanitize_text_field( (string) $args['template'] ) ); }
		$after = self::get_content( (int) $result );
		WPAIB_Audit::log( $id ? 'site.content.update' : 'site.content.create', 'success', (string) $result, array( 'before' => $before, 'after' => $after ) );
		PRSTUDIO_Report::record_change( 'Contenuto', (string) $result, $before, $after, array( 'url' => $after['permalink'] ?? '' ) );
		return array( 'before' => $before, 'after' => $after );
	}

	public static function fetch_page( string $url_or_path ) {
		$home = wp_parse_url( home_url( '/' ) ); $url = 0 === strpos( $url_or_path, 'http' ) ? esc_url_raw( $url_or_path ) : home_url( '/' . ltrim( $url_or_path, '/' ) ); $parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) { return new WP_Error( 'wpaib_external_url_forbidden', 'È consentito solo il dominio del sito.', array( 'status' => 403 ) ); }
		$response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 5, 'user-agent' => 'PR-STUDIO-AI-BRIDGE/' . WPAIB_VERSION ) ); if ( is_wp_error( $response ) ) { return $response; }
		$body = wp_remote_retrieve_body( $response ); $max = 2 * 1024 * 1024; if ( strlen( $body ) > $max ) { $body = substr( $body, 0, $max ); }
		return array( 'url' => $url, 'status' => wp_remote_retrieve_response_code( $response ), 'headers' => array( 'content-type' => wp_remote_retrieve_header( $response, 'content-type' ), 'cache-control' => wp_remote_retrieve_header( $response, 'cache-control' ) ), 'bytes' => strlen( $body ), 'html' => $body );
	}
}
