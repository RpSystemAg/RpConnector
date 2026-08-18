<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Data_Storage extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'data_storage'; }
    public function label(): string { return 'File, database e backup'; }
    public function routes(): array { return array( '/files-manage', '/database-manage', '/backup-manage' ); }
    public function keywords(): array { return array( 'file', 'filesystem', 'database', 'query', 'tabella', 'backup', 'ripristino', 'sql', 'cartella', 'wp-cli', 'wpdb' ); }

    /** Map SQL/WP-CLI database intent to the controlled in-process backend. */
    public function workflow( string $objective, array $arguments, array $catalog ): array {
        $text = PRSTUDIO_UC_Orchestrator::normalize( $objective );
        if ( preg_match( '/\b(sql|database|wpdb|wp-cli|query)\b/u', $text ) ) {
            $raw_sql = $arguments['sql'] ?? $arguments['query'] ?? '';
            $sql = is_string( $raw_sql ) ? trim( $raw_sql ) : '';
            $action = preg_match( '/^\s*(insert|update|delete|replace)\b/i', $sql ) ? 'execute' : ( preg_match( '/^\s*(select|show|describe|desc|explain)\b/i', $sql ) ? 'query' : '' );
            if ( '' !== $action ) {
                $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_action( '/database-manage', $action ) : PRSTUDIO_UC_Contract::by_action( '/database-manage', $action );
                if ( is_array( $meta ) ) {
                    return array( array( 'tool_name'=>(string)$meta['tool_name'], 'route'=>'/database-manage', 'action'=>$action, 'arguments'=>$arguments, 'reason'=>'Fast path database nativo 2.0.0: esecuzione WordPress/wpdb controllata, senza shell o handoff.', 'read_only'=>!empty($meta['read_only']), 'destructive'=>!empty($meta['destructive']) ) );
                }
            }
        }
        return parent::workflow( $objective, $arguments, $catalog );
    }
}
