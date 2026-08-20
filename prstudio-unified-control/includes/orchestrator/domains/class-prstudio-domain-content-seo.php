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
        $text = PRSTUDIO_UC_Orchestrator::normalize( $objective );
        $action = '';
        if ( preg_match( '/\b(keyword map|mappa keyword|mappa parole chiave)\b/u', $text ) ) { $action = 'build_keyword_map'; }
        elseif ( preg_match( '/\b(audit (?:seo )?prodott|product seo audit|seo prodotti)\b/u', $text ) ) { $action = 'audit_product_seo'; }
        elseif ( preg_match( '/\b(orphan|orfan[aei]|pagine senza link)\b/u', $text ) ) { $action = 'audit_orphan_pages'; }
        elseif ( preg_match( '/\b(grafo(?: dei)? link|link graph|internal link graph)\b/u', $text ) ) { $action = 'build_internal_link_graph'; }
        elseif ( preg_match( '/\b(http status|stati http|status http)\b/u', $text ) ) { $action = 'audit_http_statuses'; }
        elseif ( preg_match( '/\b(link interni rotti|broken internal links?)\b/u', $text ) ) { $action = 'audit_broken_internal_links'; }
        elseif ( false !== strpos( $text, 'sitemap' ) && preg_match( '/\b(coverage|copertura|audit|verifica|inventario)\b/u', $text ) ) { $action = 'audit_sitemap_coverage'; }
        elseif ( preg_match( '/\b(canonical|canonica)\b/u', $text ) && preg_match( '/\b(set|imposta|aggiorna|modifica)\b/u', $text ) ) { $action = 'set_canonical'; }
        elseif ( preg_match( '/\b(description|descrizione|meta description)\b/u', $text ) && preg_match( '/\b(rank math|seo|meta|set|imposta|aggiorna|modifica|svuota|rimuovi)\b/u', $text ) ) { $action = 'set_description'; }
        elseif ( preg_match( '/\b(meta title|titolo seo|seo title)\b/u', $text ) && preg_match( '/\b(set|imposta|aggiorna|modifica)\b/u', $text ) ) { $action = 'set_title'; }
        if ( '' !== $action ) {
            $meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_action( '/seo-manage', $action ) : PRSTUDIO_UC_Contract::by_action( '/seo-manage', $action );
            if ( is_array( $meta ) ) {
                return $this->with_operating_policy( array( array( 'tool_name'=>(string)$meta['tool_name'], 'route'=>'/seo-manage', 'action'=>$action, 'arguments'=>$arguments, 'reason'=>'Fast path SEO nativo 2.0.0: azione autorevole già nota al control-plane.', 'read_only'=>('build_keyword_map'===$action)||!empty($meta['read_only']), 'destructive'=>!empty($meta['destructive']) ) ), $objective );
            }
        }
        return $this->with_operating_policy( parent::workflow( $objective, $arguments, $catalog ), $objective );
    }
}
