<?php
define('PRSTUDIO_UC_TESTING', true);
$root=dirname(__DIR__);
$mcp=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
$rest=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-rest.php');
$autoload=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-autoload.php');
foreach (array('browser_live_attach','browser_live_signal','browser_live_stop','browser_live_status','BROWSER_LIVE_URI','PRSTUDIO_UC_Browser_Live') as $needle) {
    if (strpos($mcp,$needle)!==false) { fwrite(STDERR,"FAIL MCP still contains $needle\n"); exit(1); }
}
if (strpos($rest,'/stream/session')!==false || strpos($rest,'PRSTUDIO_UC_Browser_Live')!==false) { fwrite(STDERR,"FAIL REST streaming surface remains\n"); exit(1); }
if (strpos($autoload,'PRSTUDIO_UC_Browser_Live')!==false) { fwrite(STDERR,"FAIL autoload mapping remains\n"); exit(1); }
if (is_file($root.'/prstudio-unified-control/includes/class-prstudio-uc-browser-live.php')) { fwrite(STDERR,"FAIL Browser LIVE server class remains\n"); exit(1); }
fwrite(STDOUT,"OK Browser LIVE server surface removed\n");
