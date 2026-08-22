<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }
require_once __DIR__ . '/class-prstudio-uc-action-lexicon.php';
require_once __DIR__ . '/class-prstudio-uc-site-study-lexicon.php';

/**
 * prstudio_do — one verb in front of the whole surface.
 *
 * The router is deliberately literal. It never guesses at a mutation. Site
 * study uses the shared semantic lexicon so Italian and English collapse to the
 * same canonical mission before routing (LAW 15).
 */
final class PRSTUDIO_UC_Do {
    public const VERSION = '1.3.0';

    private static function intent_map(): array {
        $study = array(
            'tool' => 'agency_submit',
            'defaults' => array(
                'playbook' => 'site_study',
                'context' => array(),
                'objective' => 'Study the requested site through the live Browser Agent, persist verified site knowledge, update the Operational Twin and retain reusable procedures.',
            ),
            'params_into_context' => true,
            'target_context_arg' => 'url',
        );
        return array(
            'open'=>array('tool'=>'browser_open','target_arg'=>'url'),'open_tab'=>array('tool'=>'browser_open','target_arg'=>'url'),'navigate'=>array('tool'=>'browser_navigate','target_arg'=>'url'),'goto'=>array('tool'=>'browser_navigate','target_arg'=>'url'),'go_to'=>array('tool'=>'browser_navigate','target_arg'=>'url'),'visit'=>array('tool'=>'browser_navigate','target_arg'=>'url'),'reload'=>array('tool'=>'browser_reload'),'back'=>array('tool'=>'browser_back'),'forward'=>array('tool'=>'browser_forward'),'close_tab'=>array('tool'=>'browser_close'),'click'=>array('tool'=>'browser_click','target_arg'=>'selector'),'double_click'=>array('tool'=>'browser_double_click','target_arg'=>'selector'),'hover'=>array('tool'=>'browser_hover','target_arg'=>'selector'),'focus'=>array('tool'=>'browser_focus','target_arg'=>'selector'),'fill'=>array('tool'=>'browser_fill','target_arg'=>'selector'),'type'=>array('tool'=>'browser_type','target_arg'=>'selector'),'press'=>array('tool'=>'browser_press','target_arg'=>'selector'),'select'=>array('tool'=>'browser_select','target_arg'=>'selector'),'check'=>array('tool'=>'browser_check','target_arg'=>'selector'),'scroll'=>array('tool'=>'browser_scroll'),'screenshot'=>array('tool'=>'browser_screenshot'),'capture_page'=>array('tool'=>'browser_screenshot'),'snapshot'=>array('tool'=>'browser_snapshot'),'extract'=>array('tool'=>'browser_extract','target_arg'=>'selector'),'read_page'=>array('tool'=>'browser_extract','target_arg'=>'selector'),'extract_text'=>array('tool'=>'browser_extract','target_arg'=>'selector'),'wait'=>array('tool'=>'browser_wait'),'tabs'=>array('tool'=>'browser_tabs'),'adopt_tabs'=>array('tool'=>'browser_adopt_tabs'),'console'=>array('tool'=>'browser_console'),'network'=>array('tool'=>'browser_network'),'page_errors'=>array('tool'=>'browser_page_errors'),'audit_page'=>array('tool'=>'browser_lighthouse'),'vitals'=>array('tool'=>'browser_core_web_vitals'),'accessibility'=>array('tool'=>'browser_accessibility_scan'),'pdf'=>array('tool'=>'browser_pdf'),'crawl'=>array('tool'=>'browser_link_crawl','target_arg'=>'url'),'crawl_sitemap'=>array('tool'=>'browser_sitemap_crawl','target_arg'=>'url'),'responsive'=>array('tool'=>'browser_responsive_matrix'),'baseline'=>array('tool'=>'browser_capture_baseline'),'compare_baseline'=>array('tool'=>'browser_compare_baseline'),
            'study_site'=>$study,'study_website'=>$study,'learn_site'=>$study,'learn_website'=>$study,
            'read'=>array('tool'=>'prstudio_observe'),'observe'=>array('tool'=>'prstudio_observe'),'inspect'=>array('tool'=>'prstudio_observe'),'analyze_page'=>array('tool'=>'prstudio_observe'),'edit_content'=>array('tool'=>'wordpress_content_transaction'),'replace_text'=>array('tool'=>'wordpress_content_transaction','defaults'=>array('operation'=>'replace_exact')),'append_text'=>array('tool'=>'wordpress_content_transaction','defaults'=>array('operation'=>'append_once')),'insert_before'=>array('tool'=>'wordpress_content_transaction','defaults'=>array('operation'=>'insert_before')),'insert_after'=>array('tool'=>'wordpress_content_transaction','defaults'=>array('operation'=>'insert_after')),
            'gsc_performance'=>array('tool'=>'gsc_search_analytics'),'gsc_inspect'=>array('tool'=>'gsc_url_inspection','target_arg'=>'inspection_url'),'request_indexing'=>array('tool'=>'gsc_request_indexing','target_arg'=>'inspection_url'),'gsc_sitemaps'=>array('tool'=>'gsc_sitemaps'),'product_audit'=>array('tool'=>'commerce_product_audit'),
            'backlog'=>array('tool'=>'prstudio_backlog'),'todo'=>array('tool'=>'prstudio_backlog'),'health'=>array('tool'=>'prstudio_health'),'status'=>array('tool'=>'prstudio_health'),'job'=>array('tool'=>'prstudio_job_get'),'memory'=>array('tool'=>'prstudio_memory_search'),'repo_map'=>array('tool'=>'engineering_repo_map'),'validate_code'=>array('tool'=>'engineering_validate'),'mission'=>array('tool'=>'agency_submit'),'run_playbook'=>array('tool'=>'agency_submit'),'execute_playbook'=>array('tool'=>'agency_submit'),'esegui_playbook'=>array('tool'=>'agency_submit'),'avvia_playbook'=>array('tool'=>'agency_submit'),
        );
    }

    /** Legacy aliases for pre-existing fast paths. Site-study aliases are NOT here. */
    private static function italian_aliases(): array {
        return array(
            'apri'=>'open','apri_scheda'=>'open_tab','nuova_scheda'=>'open_tab','naviga'=>'navigate','vai'=>'goto','vai_a'=>'navigate','visita'=>'visit','ricarica'=>'reload','indietro'=>'back','avanti'=>'forward','chiudi_scheda'=>'close_tab','clicca'=>'click','doppio_clic'=>'double_click','passa_sopra'=>'hover','metti_a_fuoco'=>'focus','compila'=>'fill','riempi'=>'fill','scrivi_nel_campo'=>'fill','digita'=>'type','premi'=>'press','seleziona'=>'select','spunta'=>'check','scorri'=>'scroll','schermata'=>'screenshot','cattura_pagina'=>'screenshot','istantanea'=>'snapshot','estrai'=>'extract','leggi_pagina'=>'extract','estrai_testo'=>'extract','attendi'=>'wait','schede'=>'tabs','adotta_schede'=>'adopt_tabs','rete'=>'network','errori_pagina'=>'page_errors','controlla_pagina'=>'audit_page','metriche_vitali'=>'vitals','accessibilita'=>'accessibility','scansiona_sito'=>'crawl','scansiona_link'=>'crawl','esplora_sito'=>'crawl','scansiona_sitemap'=>'crawl_sitemap','riferimento'=>'baseline','confronta_riferimento'=>'compare_baseline',
            'leggi'=>'read','osserva'=>'observe','ispeziona'=>'inspect','analizza_pagina'=>'inspect','modifica_contenuto'=>'edit_content','sostituisci_testo'=>'replace_text','aggiungi_testo'=>'append_text','inserisci_prima'=>'insert_before','inserisci_dopo'=>'insert_after','prestazioni_gsc'=>'gsc_performance','ispeziona_gsc'=>'gsc_inspect','richiedi_indicizzazione'=>'request_indexing','sitemap_gsc'=>'gsc_sitemaps','analizza_prodotto'=>'product_audit','attivita'=>'backlog','cose_da_fare'=>'todo','salute'=>'health','stato'=>'status','lavoro'=>'job','memoria'=>'memory','mappa_repo'=>'repo_map','valida_codice'=>'validate_code','missione'=>'mission',
        );
    }

    private static function bilingual_intent_map(): array {
        $map=self::intent_map();foreach(self::italian_aliases() as $alias=>$canonical)if(isset($map[$canonical]))$map[$alias]=$map[$canonical];return$map;
    }

    private static function noise(): array {
        return array('the','a','an','to','on','in','of','for','this','that','please','now','how','works','work','every','all','section','sections','il','lo','la','i','gli','le','un','una','di','da','per','su','con','del','della','mi','ti','si','e','ed','o','che','al','allo','alla','questo','questa','tutte','tutti','sezione','sezioni','favore','come','funziona');
    }

    private static function normalize( string $value ): string {
        return class_exists('PRSTUDIO_UC_Action_Lexicon') ? PRSTUDIO_UC_Action_Lexicon::normalize_text($value) : strtolower(trim($value));
    }

    private static function shared_study_match( string $raw_intent, array $map ): ?array {
        $semantic=PRSTUDIO_UC_Site_Study_Lexicon::classify($raw_intent);
        if(empty($semantic['study'])||empty($semantic['site']))return null;
        $spec=$map['study_site'];
        if(!empty($semantic['wordpress'])){
            if(!isset($spec['defaults']['context'])||!is_array($spec['defaults']['context']))$spec['defaults']['context']=array();
            $spec['defaults']['context']['study_target']='wordpress';
        }
        return array('key'=>(string)$semantic['semantic_intent'],'spec'=>$spec,'confidence'=>'shared_lexicon');
    }

    /** Resolve WordPress knowledge questions directly against verified site memory. */
    private static function site_memory_route( string $raw_intent ): ?array {
        $semantic=PRSTUDIO_UC_Site_Study_Lexicon::classify($raw_intent);
        if(empty($semantic['wordpress'])||!empty($semantic['study']))return null;
        $subject=PRSTUDIO_UC_Site_Study_Lexicon::memory_subject($raw_intent);
        if(''===$subject)return null;
        return array(
            'tool'=>'prstudio_memory_search',
            'arguments'=>array('query'=>$subject,'type'=>'site_procedure','limit'=>5),
            'routing'=>array('intent'=>$raw_intent,'matched'=>'wordpress_site_memory','subject'=>$subject,'confidence'=>'shared_lexicon','memory_lookup'=>true),
        );
    }

    /** Resolves an intent to a concrete { tool, arguments } call. */
    public static function resolve( array $args ) {
        $raw_intent=(string)($args['intent']??'');
        if(''===trim($raw_intent)){
            $map=self::bilingual_intent_map();$intents=array();foreach($map as $key=>$spec)$intents[(string)$key]=(string)($spec['tool']??'');ksort($intents);
            return array('ok'=>true,'listing'=>'intents','count'=>count($intents),'intents'=>$intents,'usage'=>'Call again with intent="<one of the keys above>" plus that tool\'s arguments.','note'=>'These are fast paths to a single typed tool. For an operation not listed here use prstudio_capability_search.','version'=>self::VERSION,'component'=>'prstudio_do','suite_version'=>defined('PRSTUDIO_UC_VERSION')?PRSTUDIO_UC_VERSION:'');
        }

        $memory=self::site_memory_route($raw_intent);if(is_array($memory))return$memory;
        $map=self::bilingual_intent_map();$match=self::shared_study_match($raw_intent,$map);$normalized=self::normalize($raw_intent);$underscored=str_replace(' ','_',$normalized);
        if(!$match&&isset($map[$underscored]))$match=array('key'=>$underscored,'spec'=>$map[$underscored],'confidence'=>'exact');
        if(!$match){
            $tokens=array_values(array_diff(explode(' ',$normalized),self::noise()));$scored=array();
            foreach($map as $key=>$spec){$key_tokens=explode('_',$key);$overlap=count(array_intersect($key_tokens,$tokens));if($overlap<1)continue;$scored[$key]=($overlap*10)+($overlap===count($key_tokens)?5:0)-count($key_tokens);}
            arsort($scored,SORT_NUMERIC);$best=array_key_first($scored);
            if(null!==$best){$top_score=$scored[$best];$tied=array_keys(array_filter($scored,static fn($s):bool=>$s===$top_score));if(count($tied)>1)return self::ambiguous($raw_intent,$tied,$map);$match=array('key'=>$best,'spec'=>$map[$best],'confidence'=>'inferred');}
        }
        if(!$match)return array('tool'=>'prstudio_capability_search','arguments'=>array('query'=>$raw_intent,'limit'=>10,'include_legacy'=>true),'routing'=>array('confidence'=>'fallback','note'=>'No direct intent match. Searching the capability registry; run prstudio_execute with the chosen capability.'));

        $spec=$match['spec'];$params=is_array($args['params']??null)?$args['params']:array();
        if(!empty($spec['params_into_context'])){$call_args=is_array($spec['defaults']??null)?$spec['defaults']:array();$call_args['context']=array_merge(is_array($call_args['context']??null)?$call_args['context']:array(),$params);}
        else{$call_args=$params;if(isset($spec['defaults'])&&is_array($spec['defaults']))$call_args=array_merge($spec['defaults'],$call_args);}
        $target=$args['target']??null;
        if(null!==$target&&''!==$target){$target_context_arg=(string)($spec['target_context_arg']??'');$target_arg=(string)($spec['target_arg']??'');if(''!==$target_context_arg){if(!isset($call_args['context'])||!is_array($call_args['context']))$call_args['context']=array();if(!isset($call_args['context'][$target_context_arg]))$call_args['context'][$target_context_arg]=$target;}elseif(''!==$target_arg&&!isset($call_args[$target_arg]))$call_args[$target_arg]=$target;elseif(''===$target_arg&&!isset($call_args['id'])&&is_numeric($target))$call_args['id']=(int)$target;}
        foreach(array('lane_handle','lane_token','write_token','dry_run','tab_id','device_id') as $passthrough)if(isset($args[$passthrough])&&!isset($call_args[$passthrough]))$call_args[$passthrough]=$args[$passthrough];
        return array('tool'=>(string)$spec['tool'],'arguments'=>$call_args,'routing'=>array('intent'=>$raw_intent,'matched'=>$match['key'],'confidence'=>$match['confidence']));
    }

    private static function ambiguous( string $intent, array $candidates, array $map ) {
        $options=array();foreach(array_slice($candidates,0,6) as $key)$options[]=array('intent'=>$key,'tool'=>(string)($map[$key]['tool']??''));
        return new WP_Error('prstudio_do_intent_ambiguous','That intent matches several operations equally well. Pick one rather than letting the router guess at a mutation.',array('status'=>409,'intent'=>$intent,'candidates'=>$options,'remedy'=>'Repeat prstudio_do with one of the listed intent values, or call the tool directly by name.'));
    }

    public static function catalogue(): array {
        $map=self::bilingual_intent_map();$by_tool=array();foreach($map as $intent=>$spec){$tool=(string)$spec['tool'];if(!isset($by_tool[$tool]))$by_tool[$tool]=array();$by_tool[$tool][]=$intent;}
        return array('intent_count'=>count($map),'intents'=>array_keys($map),'by_tool'=>$by_tool,'site_study_semantics'=>'shared_lexicon','note'=>'Every typed tool remains directly callable by name. prstudio_do is an additional route, not a replacement.');
    }
}
