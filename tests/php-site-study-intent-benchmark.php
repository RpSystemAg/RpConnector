<?php

define( 'PRSTUDIO_UC_TESTING', true );
define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
    public function __construct( public string $code, public string $message, public array $data = array() ) {}
    public function get_error_code(): string { return $this->code; }
}
function remove_accents( $value ) { return strtr( (string)$value, array( 'à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u' ) ); }
function fail_site_study_benchmark( string $message ): void { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }

require_once dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-do.php';

$target = 'https://example.test/catalog/';
$positive = array(
    'study this site','study this website','learn this site','learn this website','please study this site','study every section of this site','study all sections of this website','learn how this site works','learn how this website works','explore and learn this site',
    'studia questo sito','studia il sito','studia questo website','per favore studia questo sito','studia tutte le sezioni del sito','impara questo sito','impara il sito','impara questo website','apprendi questo sito','apprendi come funziona questo sito','esplora e impara questo sito','esplora e apprendi questo sito',
);
$wordpress = array( 'studia wordpress', 'study wordpress' );
$negative = array( 'screenshot','crawl','open','navigate','read page','replace text','gsc performance','status','backlog','accessibility','compare baseline','product audit' );

$correct = 0;
$total = count( $positive ) + count( $wordpress ) + count( $negative );
$failures = array();
foreach ( $positive as $prompt ) {
    $resolved = PRSTUDIO_UC_Do::resolve( array('intent'=>$prompt,'target'=>$target,'params'=>array('max_pages'=>321,'max_depth'=>4,'concurrency'=>3,'batch_pages'=>20)) );
    $ok = is_array($resolved) && 'agency_submit'===(string)($resolved['tool']??'') && 'site_study'===(string)($resolved['arguments']['playbook']??'') && $target===(string)($resolved['arguments']['context']['url']??'') && 321===(int)($resolved['arguments']['context']['max_pages']??0) && 4===(int)($resolved['arguments']['context']['max_depth']??-1) && 3===(int)($resolved['arguments']['context']['concurrency']??0) && 20===(int)($resolved['arguments']['context']['batch_pages']??0);
    if($ok)$correct++;else$failures[]=array('prompt'=>$prompt,'expected'=>'site_study','actual'=>$resolved);
}
$wordpress_routes=array();
foreach($wordpress as $prompt){
    $resolved=PRSTUDIO_UC_Do::resolve(array('intent'=>$prompt));$wordpress_routes[]=$resolved;
    $ok=is_array($resolved)&&'agency_submit'===(string)($resolved['tool']??'')&&'site_study'===(string)($resolved['arguments']['playbook']??'')&&'wordpress'===(string)($resolved['arguments']['context']['study_target']??'')&&'study_wordpress'===(string)($resolved['routing']['matched']??'')&&'shared_lexicon'===(string)($resolved['routing']['confidence']??'');
    if($ok)$correct++;else$failures[]=array('prompt'=>$prompt,'expected'=>'wordpress_site_study','actual'=>$resolved);
}
if(json_encode($wordpress_routes[0]['arguments']??array())!==json_encode($wordpress_routes[1]['arguments']??array()))fail_site_study_benchmark('Italian and English WordPress study did not resolve to the same semantic arguments.');

foreach ( $negative as $prompt ) {
    $resolved = PRSTUDIO_UC_Do::resolve( array( 'intent'=>$prompt, 'target'=>$target ) );
    $is_site_study = is_array( $resolved ) && 'agency_submit' === (string)($resolved['tool']??'') && 'site_study' === (string)($resolved['arguments']['playbook']??'');
    if ( ! $is_site_study ) { $correct++; } else { $failures[] = array( 'prompt'=>$prompt, 'expected'=>'not_site_study', 'actual'=>$resolved ); }
}

$accuracy = $total > 0 ? $correct / $total : 0.0;
if ( $accuracy < 0.65 ) fail_site_study_benchmark( 'routing precision below 65% floor: ' . round( $accuracy * 100, 2 ) . '%' );
if ( $accuracy <= 0.80 ) fail_site_study_benchmark( 'routing precision did not exceed 80% target: ' . round( $accuracy * 100, 2 ) . '%; failures=' . json_encode( $failures ) );

$iterations = 3000;$started = hrtime( true );
for ( $i = 0; $i < $iterations; $i++ ) PRSTUDIO_UC_Do::resolve( array( 'intent'=>$positive[$i % count($positive)], 'target'=>$target, 'params'=>array('max_pages'=>120) ) );
$mean_ms=((hrtime(true)-$started)/1000000)/$iterations;if($mean_ms>10.0)fail_site_study_benchmark('mean routing latency exceeds 10 ms: '.round($mean_ms,3).' ms');

$catalogue=PRSTUDIO_UC_Do::catalogue();
if(!in_array('study_site',(array)($catalogue['intents']??array()),true))fail_site_study_benchmark('canonical study_site intent missing.');
if(in_array('studia_sito',(array)($catalogue['intents']??array()),true)||'shared_lexicon'!==(string)($catalogue['site_study_semantics']??''))fail_site_study_benchmark('site-study still depends on a private bilingual alias dictionary.');

$memory_it=PRSTUDIO_UC_Do::resolve(array('intent'=>'come arrivo alla gestione utenti in questo WordPress?'));
$memory_en=PRSTUDIO_UC_Do::resolve(array('intent'=>'how do I get to user management in this WordPress?'));
foreach(array($memory_it,$memory_en) as $route)if('prstudio_memory_search'!==(string)($route['tool']??'')||'users'!==(string)($route['arguments']['query']??'')||'site_procedure'!==(string)($route['arguments']['type']??''))fail_site_study_benchmark('WordPress learned-navigation question did not route to site procedure memory.');

fwrite( STDOUT, sprintf("PASS site-study intent benchmark: accuracy=%.2f%% (%d/%d), WordPress IT/EN semantic parity, shared lexicon, memory reuse route, mean_route_ms=%.4f, iterations=%d\n",$accuracy*100,$correct,$total,$mean_ms,$iterations) );
