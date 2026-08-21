<?php
/**
 * A rendered panel must be a panel that can be filled.
 *
 * WHAT WENT WRONG
 * ---------------
 * The controlled-session viewer was declared on browser_snapshot through
 * `openai/outputTemplate`. A host that honours that key renders the template
 * for EVERY result of the tool, not only for results that carry something to
 * show -- and browser_snapshot returns structure, not pixels. That separation
 * is deliberate: browser_screenshot exists precisely because a snapshot is not
 * a picture.
 *
 * So the panel opened on every snapshot and every single time reported
 * "frame unavailable / No frame in this tool result", waiting for a data URL
 * the tool it was attached to has no way to produce. An operator saw a black
 * rectangle labelled PR STUDIO CONTROLLED SESSION and reasonably concluded the
 * live view was broken. It was not broken; it was impossible.
 *
 * An empty panel is worse than an absent one. Absent says nothing; empty says
 * "this feature exists and has failed", and it says it on every call.
 *
 * WHAT THIS ASSERTS
 * -----------------
 * A tool may declare the viewer template only if that tool can actually emit a
 * frame. Today none can, so none declares it -- pixels reach the client through
 * the native MCP image content block that browser_screenshot already appends,
 * which hosts render without any custom widget.
 *
 * The check is deliberately about the pairing rather than about one tool name.
 * Re-attaching the panel to another pixel-less tool is the same defect wearing
 * a different label, and it would pass a test that only remembered
 * browser_snapshot.
 *
 * The viewer resource itself stays registered and served. Nothing here forbids
 * a future tool that genuinely streams frames from pointing at it; the rule is
 * only that declaring the panel and being able to fill it must travel together.
 *
 * Runs bare: reads the emitted tool surface, no database and no network.
 */

declare(strict_types=1);

define('PRSTUDIO_UC_TESTING', true);

require dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

$passed = 0;
$failed = 0;

function viewer_check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) { ++$passed; fwrite(STDOUT, "PASS {$message}\n"); return; }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
}

/**
 * Tools that can put a picture in a panel.
 *
 * Kept as a list rather than inferred, because "can this tool produce pixels"
 * is a fact about the Browser Agent that no amount of reading the PHP will
 * reveal. If a tool joins this list it must actually return image bytes.
 */
const PIXEL_PRODUCING_TOOLS = array('browser_screenshot', 'browser_screenshot_element');

$tools = PRSTUDIO_UC_MCP_V5::tools();
viewer_check(count($tools) > 0, 'the tool surface is readable at all (' . count($tools) . ' tools)');

$declaring = array();
foreach ($tools as $tool) {
    $name = (string)($tool['name'] ?? '');
    $meta = (array)($tool['_meta'] ?? array());
    $template = (string)($meta['openai/outputTemplate'] ?? '');
    $resource = (string)(((array)($meta['ui'] ?? array()))['resourceUri'] ?? '');
    if ('' !== $template || '' !== $resource) {
        $declaring[$name] = $template ?: $resource;
    }
}

foreach ($declaring as $name => $uri) {
    viewer_check(
        in_array($name, PIXEL_PRODUCING_TOOLS, true),
        sprintf(
            '%s declares the viewer panel (%s) but cannot produce a frame, so the panel would render empty on every call',
            $name,
            $uri
        )
    );
}

if (array() === $declaring) {
    ++$passed;
    fwrite(STDOUT, "PASS no tool renders a panel it cannot fill\n");
}

// The resource stays available. Removing it would be the other way to make the
// empty panel disappear, and it would also remove the only thing a future
// frame-producing tool could point at.
$source = file_get_contents(dirname(__DIR__) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php');
viewer_check(
    is_string($source) && str_contains($source, 'ui://prstudio/browser-viewer-v2.html'),
    'the viewer resource is still registered for a future tool that can stream frames'
);

// The path that genuinely carries pixels is untouched.
viewer_check(
    is_string($source) && str_contains($source, "'type'=>'image'"),
    'browser_screenshot still returns a native MCP image block, which is how the operator sees the page'
);

if ($failed > 0) {
    fwrite(STDERR, "\nviewer widget contract: {$failed} failed, {$passed} passed\n");
    exit(1);
}
fwrite(STDOUT, "SUMMARY {$passed} passed, 0 failed\n");
