<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class PRSTUDIO_Domain_Browser extends PRSTUDIO_UC_Domain_Abstract {
	public function id(): string { return 'browser'; }
	public function label(): string { return 'Browser, pagine live, screenshot, crawler e metriche strutturate'; }
	public function routes(): array { return array( '/frontend-manage' ); }
	public function keywords(): array { return array( 'browser','chrome','pagina','scheda','tab','apri','naviga','screenshot','schermata','clic','click','compila','ocr','console','rete','dom','accessibilita','playwright','crawler','crawl','metriche','google trends','search console','merchant center','instagram' ); }
	public function score( string $objective, array $catalog ): int {
		$text = PRSTUDIO_UC_Orchestrator::normalize( $objective );
		$score = parent::score( $objective, $catalog ) + 1;
		if ( preg_match( '/https?:\/\//i', $objective ) || preg_match( '/\b(apri|aprire|open|nuova scheda|nuove schede|new tab|naviga|goto|screenshot|schermata|browser|chrome|crawler|crawl|instagram)\b/u', $text ) ) { $score += 24; }
		foreach ( array( 'google trends', 'google search console', 'search console', 'google merchant', 'merchant center' ) as $marker ) {
			if ( false !== strpos( $text, $marker ) ) { $score += 12; }
		}
		return $score;
	}

	public function workflow( string $objective, array $arguments, array $catalog ): array {
		$text = PRSTUDIO_UC_Orchestrator::normalize( $objective );
		$steps = array();
		$urls = $this->urls( $objective, $arguments, $text );
		$url = $urls[0] ?? '';
		$tab_id = isset( $arguments['tab_id'] ) ? (int) $arguments['tab_id'] : ( isset( $arguments['tabId'] ) ? (int) $arguments['tabId'] : 0 );

		/* Search Console is a browser-owned composite that prepares its own tab and
		 * returns normalized rows. Keep the same five public MCP tool names. */
		if ( false !== strpos( $text, 'search console' ) || false !== strpos( $text, 'google search console' ) ) {
			$direct = '';
			if ( preg_match( '/\b(query|queries|pagina|page|click|clic|impression|ctr|position|posizione|performance|metriche|analytics)\b/u', $text ) ) { $direct = 'search_console_search_analytics'; }
			elseif ( preg_match( '/\b(sitemap|sitemaps)\b/u', $text ) ) { $direct = 'search_console_sitemaps'; }
			elseif ( preg_match( '/\b(ispeziona url|ispezione url|url inspection|indicizz|index)\b/u', $text ) ) { $direct = 'search_console_url_inspection'; }
			elseif ( preg_match( '/\b(siti|sites|proprieta|property|properties)\b/u', $text ) ) { $direct = 'search_console_sites'; }
			elseif ( preg_match( '/\b(stato|status|collegat|conness)\b/u', $text ) ) { $direct = 'search_console_status'; }
			if ( '' !== $direct ) {
				$step = $this->direct_step( $catalog, $direct, $arguments, 'Esegue Search Console nello stesso profilo Chrome e restituisce dati strutturati verificati.' );
				if ( $step ) { return array( $step ); }
			}
		}

		$needs_open = (bool) preg_match( '/\b(apri|aprire|open|nuova scheda|nuove schede|new tab|new page)\b/u', $text );
		$needs_navigate = (bool) preg_match( '/\b(naviga|vai a|goto|carica url)\b/u', $text );
		$needs_shot = (bool) preg_match( '/\b(screenshot|schermata|cattura pagina|capture)\b/u', $text );
		$needs_ocr = (bool) preg_match( '/\b(ocr|leggi testo immagine|estrai testo immagine)\b/u', $text );
		$needs_link_crawl = (bool) preg_match( '/\b(link crawl|crawl link|crawler|scansiona link|scansione link|esplora sito|crawl sito)\b/u', $text );
		$needs_sitemap_crawl = (bool) preg_match( '/\b(sitemap crawl|crawl sitemap|scansiona sitemap)\b/u', $text );
		$needs_content = (bool) preg_match( '/\b(contenuto|content|testo pagina|estrai testo|leggi pagina)\b/u', $text );
		$needs_inspect = (bool) preg_match( '/\b(inspect|ispeziona|ispezione|analizza pagina|acquisisci contenuto|profilo|visuale|lettura)\b/u', $text );
		$needs_click = (bool) preg_match( '/\b(click|clicca|premi pulsante)\b/u', $text );
		$needs_fill = (bool) preg_match( '/\b(compila|riempi|fill|scrivi nel campo)\b/u', $text );
		$page_action = $needs_shot || $needs_ocr || $needs_content || $needs_inspect || $needs_click || $needs_fill;

		/* Crawlers are autonomous in 0.3.9: with an explicit URL they do not need
		 * a preparatory tab. This makes ownership valid before navigation by construction. */
		if ( $needs_sitemap_crawl ) {
			$args = $arguments;
			if ( '' !== $url ) { $args['url'] = $url; }
			$steps[] = $this->step( $catalog, 'playwright_sitemap_crawl', $args, 'Crawler sitemap autonomo bounded e ricorsivo.' );
			return array_values( array_filter( $steps ) );
		}
		if ( $needs_link_crawl ) {
			$args = $arguments;
			if ( '' !== $url ) { $args['url'] = $url; }
			$steps[] = $this->step( $catalog, 'playwright_link_crawl', $args, 'Crawler autonomo multi-scheda, same-origin e governato.' );
			return array_values( array_filter( $steps ) );
		}

		/* Deterministic page preparation. Any operation that needs a document and
		 * has a URL gets a navigation dependency automatically. */
		$prepared = false;
		if ( '' !== $url && ( $page_action || $needs_navigate ) ) {
			if ( $tab_id > 0 ) {
				$steps[] = $this->step( $catalog, 'playwright_goto', array( 'url'=>$url, 'tab_id'=>$tab_id ), 'Prepara deterministicamente la scheda agente indicata.' );
			} else {
				$steps[] = $this->step( $catalog, 'playwright_new_page', array( 'url'=>$url ), 'Prepara automaticamente una scheda agente per l’azione successiva.' );
			}
			$prepared = true;
		} elseif ( $needs_open && $urls ) {
			foreach ( $urls as $open_url ) {
				$steps[] = $this->step( $catalog, 'playwright_new_page', array( 'url'=>$open_url ), 'Apre una nuova scheda nella finestra agente dedicata.' );
			}
			$prepared = ! empty( $steps );
		} elseif ( $needs_navigate && '' !== $url ) {
			$steps[] = $this->step( $catalog, 'playwright_goto', array_filter( array( 'url'=>$url, 'tab_id'=>$tab_id ?: null ) ), 'Naviga la scheda agente.' );
			$prepared = true;
		}

		$handoff = static function ( array $args ) use ( $prepared, $tab_id ): array {
			if ( $prepared ) { $args['tab_from_previous'] = true; }
			elseif ( $tab_id > 0 ) { $args['tab_id'] = $tab_id; }
			return $args;
		};
		if ( $needs_click ) {
			$steps[] = $this->step( $catalog, 'playwright_click', $handoff( array_filter( array( 'selector'=>$arguments['selector'] ?? null, 'text'=>$arguments['text'] ?? null ) ) ), 'Interagisce con l’elemento richiesto sulla scheda preparata.' );
		}
		if ( $needs_fill ) {
			$steps[] = $this->step( $catalog, 'playwright_fill', $handoff( array_filter( array( 'selector'=>$arguments['selector'] ?? null, 'value'=>$arguments['value'] ?? ( $arguments['text'] ?? null ) ) ) ), 'Compila il campo sulla scheda preparata.' );
		}
		if ( $needs_content ) {
			$steps[] = $this->step( $catalog, 'playwright_content', $handoff( array_filter( array( 'selector'=>$arguments['selector'] ?? null ) ) ), 'Legge il contenuto con fallback metadata pubblico verificabile.' );
		} elseif ( $needs_inspect && ! $needs_shot && ! $needs_ocr ) {
			$steps[] = $this->step( $catalog, 'inspect', $handoff( array() ), 'Ispeziona la pagina riutilizzando deterministicamente la scheda agente.' );
		}
		if ( $needs_shot ) {
			$steps[] = $this->step( $catalog, 'playwright_screenshot_page', $handoff( array( 'full_page'=>! empty( $arguments['full_page'] ) ) ), 'Cattura la pagina con fallback CDP compatibile.' );
		}
		if ( $needs_ocr ) {
			$steps[] = $this->step( $catalog, 'playwright_screenshot_page', $handoff( array( 'ocr'=>true, 'ocr_language'=>$arguments['ocr_language'] ?? 'ita+eng' ) ), 'Esegue OCR con fallback server/browser.' );
		}

		if ( ! $steps ) { return parent::workflow( $objective, $arguments, $catalog ); }
		return array_values( array_filter( $steps ) );
	}

	private function urls( string $objective, array $arguments, string $text ): array {
		$urls = array();
		foreach ( (array) ( $arguments['urls'] ?? array() ) as $candidate ) { if ( '' !== trim( (string) $candidate ) ) { $urls[] = trim( (string) $candidate ); } }
		if ( '' !== trim( (string) ( $arguments['url'] ?? '' ) ) ) { $urls[] = trim( (string) $arguments['url'] ); }
		if ( preg_match_all( '#https?://[^\s<>()\[\]"\']+#iu', $objective, $matches ) ) {
			foreach ( $matches[0] as $candidate ) { $urls[] = rtrim( (string) $candidate, '.,;:!?' ); }
		}
		$known = array(
			'google trends'=>'https://trends.google.com/trends/',
			'google search console'=>'https://search.google.com/search-console/',
			'search console'=>'https://search.google.com/search-console/',
			'google merchant'=>'https://merchants.google.com/',
			'merchant center'=>'https://merchants.google.com/',
			'instagram'=>'https://www.instagram.com/',
		);
		foreach ( $known as $marker=>$known_url ) { if ( false !== strpos( $text, $marker ) ) { $urls[] = $known_url; } }
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function direct_step( array $catalog, string $tool_name, array $arguments, string $reason ): array {
		$meta = class_exists( 'PRSTUDIO_UC_Action_Index' ) ? PRSTUDIO_UC_Action_Index::by_tool( $tool_name ) : null;
		if ( ! is_array( $meta ) ) {
			foreach ( $catalog as $candidate ) { if ( $tool_name === (string) ( $candidate['tool_name'] ?? '' ) ) { $meta = $candidate; break; } }
		}
		if ( ! is_array( $meta ) && 0 === strpos( $tool_name, 'search_console_' ) ) {
			$meta = array( 'route'=>'', 'action'=>$tool_name, 'read_only'=>true, 'destructive'=>false );
		}
		if ( ! is_array( $meta ) ) { return array(); }
		return array(
			'tool_name'=>$tool_name, 'route'=>(string) ( $meta['route'] ?? '' ), 'action'=>(string) ( $meta['action'] ?? $tool_name ),
			'arguments'=>$arguments, 'reason'=>$reason, 'read_only'=>! empty( $meta['read_only'] ), 'destructive'=>! empty( $meta['destructive'] ),
		);
	}

	private function step( array $catalog, string $action, array $arguments, string $reason ): array {
		if ( class_exists( 'PRSTUDIO_UC_Action_Index' ) ) {
			$meta = PRSTUDIO_UC_Action_Index::by_action( '/frontend-manage', $action );
			if ( is_array( $meta ) ) {
				return array(
					'tool_name'=>(string) ( $meta['tool_name'] ?? '' ), 'route'=>'/frontend-manage', 'action'=>$action,
					'arguments'=>$arguments, 'reason'=>$reason, 'read_only'=>! empty( $meta['read_only'] ), 'destructive'=>! empty( $meta['destructive'] ),
				);
			}
		}
		foreach ( $catalog as $meta ) {
			if ( '/frontend-manage' === (string) ( $meta['route'] ?? '' ) && $action === (string) ( $meta['action'] ?? '' ) ) {
				return array(
					'tool_name'=>(string) ( $meta['tool_name'] ?? '' ), 'route'=>'/frontend-manage', 'action'=>$action,
					'arguments'=>$arguments, 'reason'=>$reason, 'read_only'=>! empty( $meta['read_only'] ), 'destructive'=>! empty( $meta['destructive'] ),
				);
			}
		}
		return array();
	}
}
