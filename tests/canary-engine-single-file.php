<?php
$root=dirname(__DIR__);
$file=$root.'/prstudio-unified-control/includes/class-prstudio-uc-canary-engine.php';
if(file_exists($file)){fwrite(STDERR,"FAIL canary gate implementation still exists\n");exit(1);}
$autoload=file_get_contents($root.'/prstudio-unified-control/includes/class-prstudio-uc-autoload.php');
foreach(array('PRSTUDIO_UC_Canary_Engine','class-prstudio-uc-canary-engine.php','verify_between_stages','stop_rollout') as $token){
    if(strpos($autoload,$token)!==false){fwrite(STDERR,"FAIL legacy canary token remains in autoload: $token\n");exit(1);}
}
echo "PASS canary-engine-absent\n";
