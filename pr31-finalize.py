from pathlib import Path
import runpy

p=Path('prstudio-unified-control/includes/class-prstudio-uc-public-tool-contracts.php')
s=p.read_text(encoding='utf-8')
old="            'browser_live_status' => 'Inspect private WebRTC signaling readiness and the latest Browser Agent diagnostic evidence for this lane.',\n"
if old not in s:
    raise SystemExit('Expected Browser LIVE lead contract not found')
p.write_text(s.replace(old,'',1), encoding='utf-8')
runpy.run_path('pr31-finalize-core.py', run_name='__main__')
