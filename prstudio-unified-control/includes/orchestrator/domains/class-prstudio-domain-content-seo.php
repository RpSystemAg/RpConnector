<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( dirname( __DIR__ ) ) . '/class-prstudio-uc-seo-operating-policy.php';
final class PRSTUDIO_Domain_Content_SEO extends PRSTUDIO_UC_Domain_Abstract {
    public function id(): string { return 'content_seo'; }
    public function label(): string { return 'Contenuti, SEO, ricerca e commenti'; }
    public function routes(): array { return array( '/content-manage', '/seo-manage', '/global-search', '/comments-manage' ); }
    public function keywords(): array { return array( 'contenuto', 'pagina', 'articolo', 'seo', 'meta title', 'description', 'redirect', 'search', 'ricerca', 'commento', 'rank math', 'indicizzazione', 'schema', 'canonical', 'sitemap', 'orphan', 'search console', 'gsc', 'ahrefs', 'semrush', 'screaming frog', 'serp', 'keyword', 'search intent', 'organic traffic', 'organic search', 'internal linking', 'backlink', 'cannibalization', 'content gap', 'indexability', 'crawlability' ); }

    /** Attach the global method plus only the current site's memory pointer. */
    private function with_operating_policy( array $steps, string $objective ): array {
        if ( ! PRSTUDIO_UC_SEO_Operating_Policy::applies_to( $objective ) ) { return $steps; }
        $context = PRSTUDIO_UC_SEO_Operating_Policy::runtime_context( $objective );
        foreach ( $steps as &$step ) {
            if ( is_array( $step ) ) { $step['operating_policy'] = $context; }
        }
        unset( $step );
        return $steps;
    }

    /** Prefer exact native SEO executors before a generic semantic scan. */
    public function workflow( string $objective, array $arguments, array $catalog ): array {
        $allowed = array_fill_keys( array(
            'build_keyword_map', 'audit_product_seo', 'audit_orphan_pages', 'build_internal_link_graph',
            'audit_http_statuses', 'audit_broken_internal_links', 'audit_sitemap_coverage',
            'set_canonical', 'set_description', 'set_title',
        ), true );
        if ( class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
            $ranked = PRSTUDIO_UC_Action_Index::search_detailed( $objective, 50, $this->id(), '/seo-manage' );
            foreach ( (array) ( $ranked['items'] ?? array() ) as $meta ) {
                $action = (string) ( $meta['action'] ?? '' );
                if ( ! isset( $allowed[ $action ] ) ) { continue; }
                return $this->with_operating_policy( array( array( 'tool_name'=>(string)$meta['tool_name'], 'route'=>'/seo-manage', 'action'=>$action, 'arguments'=>$arguments, 'reason'=>'Fast path SEO nativo 2.0.0: azione autorevole già nota al control-plane.', 'read_only'=>('build_keyword_map'===$action)||!empty($meta['read_only']), 'destructive'=>!empty($meta['destructive']) ) ), $objective );
            }
        }
        return $this->with_operating_policy( parent::workflow( $objective, $arguments, $catalog ), $objective );
    }
}
