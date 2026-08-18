<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PRSTUDIO_UC_OCR {
	private const MAX_BYTES = 12582912;
	private const TIMEOUT_SECONDS = 45;

	public static function status(): array {
		$binary=self::binary();$languages=$binary?self::languages($binary):array();
		$devices=class_exists('PRSTUDIO_UC_Store')?PRSTUDIO_UC_Store::list_devices():array();
		$browser_native=false;$browser_page_text=false;
		foreach($devices as $device){
			if(empty($device['online'])){continue;}
			if(!empty($device['capabilities']['ocrBrowserNative'])){$browser_native=true;}
			if(!empty($device['capabilities']['ocrDomAccessibility'])){$browser_page_text=true;}
		}
		return array(
			'available'=>''!==$binary||$browser_native||$browser_page_text,
			'optical_available'=>''!==$binary||$browser_native,
			'server_available'=>''!==$binary,
			'browser_native_available'=>$browser_native,
			'browser_page_text_available'=>$browser_page_text,
			'provider'=>''!==$binary?'tesseract-cli':($browser_native?'chrome-text-detector':($browser_page_text?'browser-page-text':'none')),
			'binary'=>''!==$binary?$binary:'',
			'languages'=>$languages,
			'max_bytes'=>self::MAX_BYTES,
			'process_runner'=>self::runner(),
			'diagnostics'=>array('configured_path'=>(string)get_option('prstudio_uc_tesseract_path',''),'fixed_candidates'=>self::candidates(),'exec_disabled'=>self::disabled('exec'),'proc_open_disabled'=>self::disabled('proc_open')),
		);
	}

	public static function run( string $data_url, string $language='ita+eng' ) {
		if(!preg_match('#^data:image/(png|jpeg|webp);base64,(.+)$#s',$data_url,$m)){return new WP_Error('prstudio_uc_ocr_input','Immagine OCR non valida.',array('status'=>400));}
		$raw=base64_decode($m[2],true);if(false===$raw||0===strlen($raw)||strlen($raw)>self::MAX_BYTES){return new WP_Error('prstudio_uc_ocr_size','Immagine OCR troppo grande o non valida.',array('status'=>413));}
		if(false===@getimagesizefromstring($raw)){return new WP_Error('prstudio_uc_ocr_decode','Formato immagine OCR non leggibile.',array('status'=>400));}
		$binary=self::binary();if(''===$binary){return new WP_Error('prstudio_uc_ocr_unavailable','Tesseract non disponibile sul server; usare il fallback OCR del Browser Agent.',array('status'=>503,'diagnostics'=>self::status()));}
		$available=self::languages($binary);$requested=array_values(array_filter(explode('+',preg_replace('/[^a-z+]/','',strtolower($language)))));$selected=array_values(array_intersect($requested,$available));
		if(!$selected&&in_array('eng',$available,true)){$selected=array('eng');}if(!$selected){return new WP_Error('prstudio_uc_ocr_language','Nessuna lingua OCR richiesta è installata.',array('status'=>422,'requested'=>$requested,'available'=>$available));}
		if(!function_exists('wp_tempnam')){require_once ABSPATH.'wp-admin/includes/file.php';}$input=wp_tempnam('prstudio-ocr.png');if(!$input){return new WP_Error('prstudio_uc_ocr_temp','Impossibile creare file temporaneo OCR.',array('status'=>500));}
		file_put_contents($input,$raw);$cmd=array($binary,$input,'stdout','-l',implode('+',$selected),'--psm','6');$run=self::run_process($cmd,self::TIMEOUT_SECONDS);@unlink($input);
		if(!$run['ok']){return new WP_Error('prstudio_uc_ocr_failed','OCR non riuscito.',array('status'=>500,'exit_code'=>$run['exit_code'],'timed_out'=>$run['timed_out'],'details'=>substr($run['stderr'],0,4000)));}
		return array('text'=>trim($run['stdout']),'language'=>implode('+',$selected),'engine'=>'tesseract-cli','binary'=>basename($binary),'duration_ms'=>$run['duration_ms']);
	}

	private static function candidates(): array {
		return array_values(array_unique(array_filter(array(
			defined('PRSTUDIO_UC_TESSERACT_BIN')?(string)PRSTUDIO_UC_TESSERACT_BIN:'',
			(string)get_option('prstudio_uc_tesseract_path',''),
			(string)getenv('PRSTUDIO_TESSERACT_BIN'),
			'/usr/bin/tesseract','/usr/local/bin/tesseract','/opt/homebrew/bin/tesseract','C:/Program Files/Tesseract-OCR/tesseract.exe'
		))));
	}
	private static function binary(): string {
		foreach(self::candidates() as $candidate){if(is_file($candidate)&&is_executable($candidate)){return $candidate;}}
		return '';
	}
	private static function languages( string $binary ): array {
		$run=self::run_process(array($binary,'--list-langs'),10);if(!$run['ok']){return array();}$lines=preg_split('/\R/',trim($run['stdout']));$langs=array();foreach((array)$lines as $line){$line=trim($line);if($line&&false===strpos($line,'List of available languages')&&preg_match('/^[a-zA-Z0-9_]+$/',$line)){$langs[]=$line;}}sort($langs);return array_values(array_unique($langs));
	}
	private static function runner(): string { return function_exists('proc_open')&&!self::disabled('proc_open')?'proc_open_argv':'none'; }
	private static function disabled( string $fn ): bool { return in_array($fn,array_map('trim',explode(',',(string)ini_get('disable_functions'))),true); }
	private static function run_process( array $command, int $timeout ): array {
		$start=microtime(true);
		if(!function_exists('proc_open')||self::disabled('proc_open')){return array('ok'=>false,'exit_code'=>127,'stdout'=>'','stderr'=>'Nessun process runner argv disponibile','timed_out'=>false,'duration_ms'=>0);}
		if(empty($command)||!is_string($command[0])||!is_file($command[0])||!is_executable($command[0])){return array('ok'=>false,'exit_code'=>127,'stdout'=>'','stderr'=>'Comando OCR non valido','timed_out'=>false,'duration_ms'=>0);}
		foreach($command as $argument){if(!is_string($argument)||false!==strpos($argument,"\0")){return array('ok'=>false,'exit_code'=>127,'stdout'=>'','stderr'=>'Argomento OCR non valido','timed_out'=>false,'duration_ms'=>0);}}
		$proc=@proc_open($command,array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true,'suppress_errors'=>true));if(!is_resource($proc)){return array('ok'=>false,'exit_code'=>127,'stdout'=>'','stderr'=>'proc_open failed','timed_out'=>false,'duration_ms'=>0);}
		stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$stdout='';$stderr='';$timed=false;$status=array('exitcode'=>-1);
		do{$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$status=proc_get_status($proc);if(!$status['running'])break;if(microtime(true)-$start>$timeout){$timed=true;proc_terminate($proc,9);break;}usleep(50000);}while(true);
		$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit<0&&!$timed&&isset($status['exitcode']))$exit=(int)$status['exitcode'];return array('ok'=>!$timed&&0===$exit,'exit_code'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>$timed,'duration_ms'=>(int)round((microtime(true)-$start)*1000));
	}
}
