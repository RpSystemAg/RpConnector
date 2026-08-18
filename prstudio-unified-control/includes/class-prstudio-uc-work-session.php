<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_UC_Work_Session {
	private const ACTIVE_OPTION = 'prstudio_uc_active_work';
	private const HISTORY_OPTION = 'prstudio_uc_work_history';

	public static function root(): string { return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-unified-private/work'; }
	public static function backup_root(): string { return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-unified-private/backups'; }
	public static function cache_root(): string { return trailingslashit( WP_CONTENT_DIR ) . 'prstudio-unified-private/cache'; }

	private static function ensure_dirs(): void {
		foreach ( array( dirname( self::root() ), self::root(), self::backup_root(), self::cache_root() ) as $dir ) {
			if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
			if ( is_dir( $dir ) ) {
				@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n" );
				@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
			}
		}
	}
	private static function id(): string { return 'work_' . gmdate( 'Ymd_His' ) . '_' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 ); }
	public static function active(): array {
		$value = get_option( self::ACTIVE_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}
	public static function get( string $work_id ): array {
		$work_id = sanitize_file_name( $work_id );
		if ( '' === $work_id ) { return array(); }
		$active = self::active();
		if ( $active && hash_equals( (string) ( $active['work_id'] ?? '' ), $work_id ) ) { return $active; }
		$manifest = self::work_dir( $work_id ) . '/manifest.json';
		if ( ! is_readable( $manifest ) ) { return array(); }
		$decoded = json_decode( (string) file_get_contents( $manifest ), true );
		return is_array( $decoded ) && hash_equals( (string) ( $decoded['work_id'] ?? '' ), $work_id ) ? $decoded : array();
	}
	public static function resolve( string $work_id = '' ): array {
		return '' !== trim( $work_id ) ? self::get( $work_id ) : self::active();
	}
	public static function activate( string $work_id ): array {
		$work = self::get( $work_id );
		if ( ! $work || 'active' !== (string) ( $work['status'] ?? '' ) ) { return array(); }
		update_option( self::ACTIVE_OPTION, $work, false );
		return $work;
	}
	public static function begin( array $args ): array {
		$current = self::active();
		if ( $current && 'active' === ( $current['status'] ?? '' ) && empty( $args['force'] ) ) { return $current; }
		self::ensure_dirs();
		$id = self::id();
		$scope = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $args['scope'] ?? array( 'wordpress' ) ) ) ) ) );
		$work = array(
			'work_id' => $id,
			'description' => sanitize_text_field( (string) ( $args['description'] ?? 'Lavoro MCP' ) ),
			'scope' => $scope ?: array( 'wordpress' ),
			'target_sha256' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $args['target_sha256'] ?? '' ) ) ),
			'status' => 'active', 'started_gmt' => gmdate( 'c' ), 'updated_gmt' => gmdate( 'c' ),
			'captured_files' => array(), 'changes' => array(), 'anti_crash' => array( 'status' => 'pending', 'evidence' => array() ),
		);
		wp_mkdir_p( self::work_dir( $id ) . '/originals' );
		self::save( $work );
		return $work;
	}
	public static function work_dir( string $id ): string { return trailingslashit( self::root() ) . sanitize_file_name( $id ); }
	private static function save( array $work ): void {
		$work['updated_gmt'] = gmdate( 'c' );
		update_option( self::ACTIVE_OPTION, $work, false );
		$dir = self::work_dir( (string) $work['work_id'] );
		if ( is_dir( $dir ) ) { @file_put_contents( $dir . '/manifest.json', wp_json_encode( $work, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
	}
	public static function set_anti_crash( array $anti_crash ): array {
		return self::set_anti_crash_for( (string) ( self::active()['work_id'] ?? '' ), $anti_crash );
	}
	public static function set_anti_crash_for( string $work_id, array $anti_crash ): array {
		$work = self::resolve( $work_id );
		if ( ! $work ) { return array(); }
		$work['anti_crash'] = $anti_crash;
		$work['updated_gmt'] = gmdate( 'c' );
		$dir = self::work_dir( (string) $work['work_id'] );
		if ( is_dir( $dir ) ) { @file_put_contents( $dir . '/manifest.json', wp_json_encode( $work, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
		$active = self::active();
		if ( $active && hash_equals( (string) ( $active['work_id'] ?? '' ), (string) $work['work_id'] ) ) { update_option( self::ACTIVE_OPTION, $work, false ); }
		return $work;
	}
	public static function capture_original( string $relative, string $absolute ): array {
		$work = self::active();
		if ( ! $work ) { $work = self::begin( array( 'description' => 'Sessione implicita per modifica controllata', 'scope' => array( 'wordpress' ) ) ); }
		$key = hash( 'sha256', $relative );
		if ( isset( $work['captured_files'][ $key ] ) ) {
			return array( 'backup_id' => 'pending:' . $work['work_id'] . ':' . $key, 'work_id' => $work['work_id'], 'captured' => false );
		}
		$entry_dir = self::work_dir( (string) $work['work_id'] ) . '/originals/' . $key;
		wp_mkdir_p( $entry_dir );
		$exists = is_file( $absolute );
		$original = $exists ? (string) file_get_contents( $absolute ) : '';
		@file_put_contents( $entry_dir . '/data.bin', $original );
		$meta = array( 'path' => $relative, 'existed' => $exists, 'sha256' => $exists ? hash( 'sha256', $original ) : null, 'size' => strlen( $original ), 'captured_gmt' => gmdate( 'c' ) );
		@file_put_contents( $entry_dir . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$work['captured_files'][ $key ] = $meta;
		self::save( $work );
		return array( 'backup_id' => 'pending:' . $work['work_id'] . ':' . $key, 'work_id' => $work['work_id'], 'captured' => true );
	}
	public static function record_change( string $type, string $resource, array $details = array() ): void {
		$work = self::active();
		if ( ! $work ) { return; }
		$work['changes'][] = array( 'type' => sanitize_key( $type ), 'resource' => sanitize_text_field( $resource ), 'details' => $details, 'gmt' => gmdate( 'c' ) );
		$work['changes'] = array_slice( $work['changes'], -1000 );
		self::save( $work );
	}
	public static function restore_pending( string $backup_id, ?string $expected_current_sha256 = null ) {
		if ( ! preg_match( '/^pending:([a-zA-Z0-9_-]+):([a-f0-9]{64})$/', $backup_id, $m ) ) { return new WP_Error( 'prstudio_pending_backup_invalid', 'Riferimento journal non valido.' ); }
		$dir = self::work_dir( $m[1] ) . '/originals/' . $m[2];
		$meta = is_readable( $dir . '/meta.json' ) ? json_decode( (string) file_get_contents( $dir . '/meta.json' ), true ) : null;
		if ( ! is_array( $meta ) ) { return new WP_Error( 'prstudio_pending_backup_missing', 'Originale di lavoro non trovato.' ); }
		$absolute = wp_normalize_path( trailingslashit( ABSPATH ) . ltrim( (string) $meta['path'], '/' ) );
		if ( null !== $expected_current_sha256 && is_file( $absolute ) && ! hash_equals( $expected_current_sha256, hash_file( 'sha256', $absolute ) ) ) { return new WP_Error( 'wpaib_file_conflict', 'Il file è cambiato dopo la modifica.', array( 'status' => 409 ) ); }
		if ( ! empty( $meta['existed'] ) ) {
			$data = file_get_contents( $dir . '/data.bin' );
			wp_mkdir_p( dirname( $absolute ) );
			if ( false === file_put_contents( $absolute, $data ) ) { return new WP_Error( 'prstudio_pending_restore_failed', 'Ripristino journal non riuscito.' ); }
		} elseif ( is_file( $absolute ) ) { @unlink( $absolute ); }
		return array( 'restored' => true, 'path' => $meta['path'], 'source' => 'work_journal', 'work_id' => $m[1] );
	}

	private static function remove_tree( string $path ): bool {
		if ( ! is_dir( $path ) ) { return true; }
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) { $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() ); }
		return @rmdir( $path );
	}

	private static function purge_old_backups( string $keep_archive ): array {
		$deleted = 0; $failed = array();
		foreach ( glob( trailingslashit( self::backup_root() ) . '*.zip' ) ?: array() as $path ) {
			if ( wp_normalize_path( $path ) === wp_normalize_path( $keep_archive ) ) { continue; }
			if ( is_file( $path ) ) {
				if ( @unlink( $path ) ) { $deleted++; } else { $failed[] = basename( $path ); }
			}
		}
		return array( 'deleted'=>$deleted, 'failed'=>$failed, 'complete'=>empty($failed) );
	}

	private static function reset_private_cache(): array {
		$root = self::cache_root();
		$removed = is_dir( $root ) ? self::remove_tree( $root ) : true;
		wp_mkdir_p( $root );
		if ( is_dir( $root ) ) {
			@file_put_contents( trailingslashit( $root ) . 'index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents( trailingslashit( $root ) . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
			@file_put_contents( trailingslashit( $root ) . 'web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n" );
		}
		$action_index_refreshed = false;
		if ( class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
			PRSTUDIO_UC_Action_Index::invalidate();
			PRSTUDIO_UC_Action_Index::warm( true );
			$action_index_refreshed = true;
		}
		return array( 'old_private_cache_removed'=>$removed, 'new_private_cache_created'=>is_dir($root), 'action_index_refreshed'=>$action_index_refreshed );
	}

	public static function finalize( array $args = array() ) {
		$work = self::active();
		if ( ! $work ) { return new WP_Error( 'prstudio_work_missing', 'Nessun lavoro attivo.' ); }
		if ( 'passed' !== (string) ( $work['anti_crash']['status'] ?? '' ) ) { return new WP_Error( 'prstudio_anti_crash_not_passed', 'Impossibile finalizzare: test anti crash pre-modifica non verificato.', array( 'question' => 'Hai eseguito il test anti crash pre-modifica?' ) ); }
		self::ensure_dirs();
		$source = self::work_dir( (string) $work['work_id'] );
		$work['status'] = 'completing';
		$work['completed_gmt'] = gmdate( 'c' );
		@file_put_contents( $source . '/completion.json', wp_json_encode( array(
			'work_id' => $work['work_id'], 'description' => $work['description'], 'scope' => $work['scope'],
			'completed_gmt' => $work['completed_gmt'], 'captured_files' => $work['captured_files'],
			'changes' => $work['changes'], 'anti_crash' => $work['anti_crash'],
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$archive = trailingslashit( self::backup_root() ) . $work['work_id'] . '.zip';
		$created = false;
		$provider = 'none';
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file ) {
					if ( $file->isFile() ) { $zip->addFile( $file->getPathname(), ltrim( str_replace( $source, '', $file->getPathname() ), DIRECTORY_SEPARATOR ) ); }
				}
				$zip->close();
				$created = is_file( $archive ) && filesize( $archive ) > 0;
				$provider = 'ziparchive';
			}
		}
		if ( ! $created ) {
			if ( ! class_exists( 'PclZip' ) && is_file( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) { require_once ABSPATH . 'wp-admin/includes/class-pclzip.php'; }
			if ( class_exists( 'PclZip' ) ) {
				@unlink( $archive );
				$pcl = new PclZip( $archive );
				$result = $pcl->create( $source, PCLZIP_OPT_REMOVE_PATH, $source );
				$created = is_array( $result ) && ! empty( $result ) && is_file( $archive ) && filesize( $archive ) > 0;
				$provider = 'pclzip';
			}
		}
		if ( ! $created ) {
			return new WP_Error( 'prstudio_backup_archive_failed', 'Impossibile creare il backup consolidato ZIP. ZipArchive e PclZip non sono disponibili o hanno fallito.', array( 'work_id' => $work['work_id'], 'source' => $source ) );
		}

		$work['status'] = 'completed';
		$work['backup'] = array(
			'backup_id' => 'work:' . $work['work_id'], 'path' => $archive,
			'sha256' => hash_file( 'sha256', $archive ), 'bytes' => filesize( $archive ),
			'provider' => $provider, 'single_consolidated_backup' => true,
		);
		$backup_retention = self::purge_old_backups( $archive );
		$retention = array(
			'old_backups_deleted' => (int) $backup_retention['deleted'],
			'backups' => $backup_retention,
			'cache' => self::reset_private_cache(),
			'old_audit_rows_deleted' => 0,
			'logs' => array( 'rotated'=>false ),
		);
		if ( class_exists( 'WPAIB_Audit' ) && method_exists( 'WPAIB_Audit', 'purge_older_than' ) ) {
			$retention['old_audit_rows_deleted'] = WPAIB_Audit::purge_older_than( (string) ( $work['started_gmt'] ?? gmdate('c') ) );
		}
		if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) {
			$retention['logs'] = PRSTUDIO_UC_Log_Orchestrator::rotate_generation( 'work_finalize', (string) $work['work_id'] );
		}
		$retention_complete = ! empty( $retention['backups']['complete'] ) && ! empty( $retention['cache']['old_private_cache_removed'] );
		if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) { $retention_complete = $retention_complete && ! empty( $retention['logs']['rotated'] ); }
		$retention['complete'] = $retention_complete;
		if ( ! $retention_complete ) {
			if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) { PRSTUDIO_UC_Log_Orchestrator::log( 'plugin', 'work.retention_failed', array( 'work_id'=>$work['work_id'], 'retention'=>$retention ), 'error', __FILE__ ); }
			return new WP_Error( 'prstudio_retention_failed', 'Il nuovo backup è stato creato, ma la pulizia atomica di backup/cache/log precedenti non è completa. Il lavoro resta aperto per consentire un nuovo tentativo.', array( 'work_id'=>$work['work_id'], 'backup'=>$work['backup'], 'retention'=>$retention ) );
		}
		if ( class_exists( 'PRSTUDIO_UC_Log_Orchestrator' ) ) { PRSTUDIO_UC_Log_Orchestrator::log( 'plugin', 'work.finalized', array( 'work_id'=>$work['work_id'], 'backup_sha256'=>$work['backup']['sha256'], 'retention'=>$retention ), 'info', __FILE__ ); }
		$work['retention'] = $retention;
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) { $history = array(); }
		$history[] = $work;
		update_option( self::HISTORY_OPTION, array_slice( $history, -50 ), false );
		delete_option( self::ACTIVE_OPTION );
		return $work;
	}

	public static function abort( array $args = array() ): array {
		$work = self::active();
		if ( ! $work ) { return array( 'aborted' => false, 'reason' => 'no_active_work' ); }
		$work['status'] = 'aborted'; $work['aborted_gmt'] = gmdate( 'c' ); $work['reason'] = sanitize_text_field( (string) ( $args['reason'] ?? '' ) );
		$history = get_option( self::HISTORY_OPTION, array() ); if ( ! is_array( $history ) ) { $history = array(); } $history[] = $work; update_option( self::HISTORY_OPTION, array_slice( $history, -50 ), false ); delete_option( self::ACTIVE_OPTION );
		return array( 'aborted' => true, 'work_id' => $work['work_id'] );
	}
}
