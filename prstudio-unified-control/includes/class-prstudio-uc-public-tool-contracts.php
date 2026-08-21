<?php
// phpcs:ignore missing_direct_file_access_protection -- testable direct-access guard.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Model-facing refinements for the public ChatGPT/MCP surface.
 *
 * Runtime handlers remain authoritative. This layer makes their stable contracts
 * explicit with JSON Schema 2020-12 vocabulary so clients can select and call
 * tools with fewer ambiguous free-form arguments.
 */
final class PRSTUDIO_UC_Public_Tool_Contracts {
    /** @var string[] */
    private const TARGETS = array(
        'agency_status',
        'browser_adopt_tabs',
        'browser_launch',
        'browser_open',
        'browser_screenshot',
        'browser_snapshot',
        'browser_status',
        'browser_task_control',
        'engineering_repo_map',
        'engineering_status',
        'procedural_skill_get',
        'procedural_skill_invalidate',
        'procedural_skill_search',
        'procedural_skill_status',
        'prstudio_backlog',
        'prstudio_capability_describe',
        'prstudio_capability_search',
        'prstudio_context_close',
        'prstudio_context_open',
        'prstudio_context_status',
        'prstudio_do',
        'prstudio_execute',
        'prstudio_flow',
        'prstudio_health',
        'prstudio_intervention_record',
        'prstudio_job_control',
        'prstudio_job_get',
        'prstudio_observe',
        'prstudio_seo_autopilot_status',
        'prstudio_tool_manual',
        'sentinel_scan',
        'sequential_thinking_session',
        'sequential_thinking_status',
        'social_metrics_ingest',
        'twin_query',
        'wordpress_content_transaction',
    );

    /**
     * Refine one advertised public contract without changing handler semantics.
     *
     * @return array{0:string,1:array,2:array}
     */
    public static function refine( string $name, string $description, array $input, array $annotations ): array {
        if ( ! in_array( $name, self::TARGETS, true ) ) {
            return array( $description, $input, $annotations );
        }

        $lead = self::lead( $name );
        if ( '' !== $lead && 0 !== strpos( trim( $description ), $lead ) ) {
            $description = $lead . ' ' . trim( $description );
        }

        // Stable identifiers: reject empty strings and advertise realistic bounds.
        foreach ( array(
            'lane_handle' => 512,
            'device_id' => 256,
            'task_id' => 256,
            'campaign_id' => 128,
            'session_id' => 128,
            'work_id' => 128,
            'mission_id' => 128,
            'request_id' => 128,
            'idempotency_key' => 256,
            'write_token' => 8192,
        ) as $key => $max ) {
            self::merge_property( $input, $key, array( 'minLength' => 1, 'maxLength' => $max ) );
        }

        self::merge_property( $input, 'reason', array( 'minLength' => 1, 'maxLength' => 2000 ) );
        self::merge_property( $input, 'query', array( 'minLength' => 1, 'maxLength' => 2000 ) );
        self::merge_property( $input, 'capability', array( 'minLength' => 1, 'maxLength' => 256 ) );

        switch ( $name ) {
            case 'agency_status':
            case 'engineering_status':
            case 'procedural_skill_status':
            case 'prstudio_context_status':
            case 'sequential_thinking_status':
                $input['maxProperties'] = 0;
                break;

            case 'browser_adopt_tabs':
                // This tool is deliberately compact. It is the only safe route
                // for taking over an already-open user tab, so it must remain
                // cheap enough to survive the hard tools/list budget instead of
                // disappearing behind generic capability discovery.
                $description = 'Adopt explicitly selected already-open HTTP(S) tabs into the current execution lane.';
                $input = self::obj( array(
                    'lane_handle' => self::str( 'Lane handle.', array( 'minLength' => 1, 'maxLength' => 512 ) ),
                    'tab_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'maxItems' => 12, 'uniqueItems' => true ),
                    'origin' => self::str( 'Exact HTTP(S) origin.', array( 'minLength' => 1, 'maxLength' => 2048 ) ),
                    'url_contains' => self::str( 'URL substring.', array( 'minLength' => 1, 'maxLength' => 2048 ) ),
                    'title_contains' => self::str( 'Title substring.', array( 'minLength' => 1, 'maxLength' => 512 ) ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
                    'device_id' => self::str( 'Optional device ID.', array( 'minLength' => 1, 'maxLength' => 256 ) ),
                    'sync_wait_seconds' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 20 ),
                ) );
                break;

            case 'browser_launch':
                self::merge_property( $input, 'device_id', array(
                    'description' => 'Optional paired Browser Agent device ID. Omit to use the active paired device.',
                ) );
                break;

            case 'browser_open':
                self::merge_property( $input, 'url', array(
                    'minLength' => 1,
                    'maxLength' => 4096,
                    'description' => 'HTTP(S) URL or bare web host; missing scheme is normalized to HTTPS by the Browser Agent.',
                ) );
                self::merge_property( $input, 'wait_until', array(
                    'enum' => array( 'complete', 'interactive', 'none' ),
                    'description' => 'Navigation readiness target: complete, interactive, or none.',
                ) );
                break;

            case 'browser_screenshot':
                self::merge_property( $input, 'format', array(
                    'enum' => array( 'auto', 'png', 'jpeg' ),
                    'default' => 'auto',
                    'description' => 'Image encoding. auto chooses a safe format; quality applies only when JPEG is used.',
                ) );
                self::merge_property( $input, 'full_page', array( 'default' => false ) );
                self::merge_property( $input, 'ocr', array(
                    'default' => false,
                    'description' => 'Run OCR only when pixel text is needed and DOM/AX text is insufficient.',
                ) );
                self::merge_property( $input, 'ocr_language', array( 'minLength' => 2, 'maxLength' => 64 ) );
                break;

            case 'browser_snapshot':
                self::merge_property( $input, 'target_ref', array( 'minLength' => 1, 'maxLength' => 512 ) );
                self::merge_property( $input, 'selector', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'selector_type', array(
                    'enum' => array( 'auto', 'css', 'text', 'role', 'label', 'xpath' ),
                    'description' => 'Locator strategy. Prefer semantic role/label/text or target_ref; use CSS/XPath only as fallback.',
                ) );
                self::merge_property( $input, 'text', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'role', array( 'minLength' => 1, 'maxLength' => 128 ) );
                self::merge_property( $input, 'name', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'label', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'xpath', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'viewer_only', array( 'default' => false ) );
                break;

            case 'browser_status':
                self::merge_property( $input, 'device_status', array(
                    'minLength' => 1,
                    'maxLength' => 32,
                    'examples' => array( 'active', 'revoked', 'online', 'offline', 'stale' ),
                    'description' => 'Optional device lifecycle or connection-status filter.',
                ) );
                self::merge_property( $input, 'include_history', array( 'default' => false ) );
                break;

            case 'browser_task_control':
                self::merge_property( $input, 'action', array( 'enum' => array( 'cancel', 'requeue' ) ) );
                break;

            case 'engineering_repo_map':
                self::merge_property( $input, 'path', array(
                    'maxLength' => 1024,
                    'description' => 'Path relative to the PR STUDIO plugin root; omit for the root. Never pass an absolute path.',
                ) );
                break;

            case 'procedural_skill_get':
            case 'procedural_skill_invalidate':
                self::merge_property( $input, 'id', array( 'minLength' => 1, 'maxLength' => 100 ) );
                break;

            case 'procedural_skill_search':
                self::merge_property( $input, 'kind', array(
                    'minLength' => 1,
                    'maxLength' => 32,
                    'examples' => array( 'capability', 'browser' ),
                    'description' => 'Optional procedure kind; current learned kinds include capability and browser.',
                ) );
                break;

            case 'prstudio_backlog':
                self::merge_property( $input, 'entity_key', array(
                    'minLength' => 1,
                    'maxLength' => 190,
                    'examples' => array( 'post:', 'url:example.com' ),
                    'description' => 'Optional intervention-ledger entity-key prefix.',
                ) );
                break;

            case 'prstudio_capability_describe':
                self::merge_property( $input, 'capability', array(
                    'description' => 'Exact capability ID returned by prstudio_capability_search.',
                ) );
                break;

            case 'prstudio_capability_search':
                self::merge_property( $input, 'domain', array(
                    'minLength' => 1,
                    'maxLength' => 64,
                    'examples' => array( 'wordpress', 'woocommerce', 'database', 'seo' ),
                ) );
                self::merge_property( $input, 'include_legacy', array( 'default' => false ) );
                break;

            case 'prstudio_context_open':
                self::merge_property( $input, 'label', array(
                    'minLength' => 1,
                    'maxLength' => 300,
                    'description' => 'Short human-readable objective for this ChatGPT workstream.',
                ) );
                self::merge_property( $input, 'chat_key', array(
                    'minLength' => 1,
                    'maxLength' => 256,
                    'description' => 'Stable caller-generated per-chat key for idempotent recovery after an ambiguous timeout.',
                ) );
                break;

            case 'prstudio_context_close':
                self::merge_property( $input, 'lane_handle', array(
                    'description' => 'Public lane handle returned by prstudio_context_open.',
                ) );
                break;

            case 'prstudio_do':
                self::merge_property( $input, 'intent', array(
                    'minLength' => 1,
                    'maxLength' => 128,
                    'examples' => array( 'screenshot', 'navigate', 'replace_text', 'backlog' ),
                    'description' => 'High-level intent to route without choosing a concrete tool name.',
                ) );
                self::merge_property( $input, 'target', array(
                    'minLength' => 1,
                    'maxLength' => 4096,
                    'description' => 'Primary object for the intent, such as a URL, entity ID, selector, or target reference.',
                ) );
                self::merge_property( $input, 'params', array(
                    'description' => 'Intent-specific arguments passed to the resolved tool. Dynamic by design; prefer typed direct tools when one exists.',
                ) );
                break;

            case 'prstudio_execute':
                self::merge_property( $input, 'arguments', array(
                    'description' => 'Exact capability arguments returned by prstudio_capability_describe. Dynamic by design because each capability has its own schema.',
                ) );
                self::merge_property( $input, 'budget', array(
                    'description' => 'Optional execution-budget object understood by the execution gateway; omit unknown keys.',
                ) );
                break;

            case 'prstudio_flow':
                $step = self::obj( array(
                    'tool' => self::str( 'Exact typed MCP tool name to execute.', array(
                        'minLength' => 1,
                        'maxLength' => 64,
                        'pattern' => '^[A-Za-z0-9_-]+$',
                    ) ),
                    'capability' => self::str( 'Exact PR STUDIO capability ID to execute.', array(
                        'minLength' => 1,
                        'maxLength' => 256,
                    ) ),
                    'arguments' => self::any_object( 'Arguments for the selected tool or capability.' ),
                    'save_as' => self::str( 'Optional stable key used to retain this step result for later flow steps.', array(
                        'minLength' => 1,
                        'maxLength' => 64,
                        'pattern' => '^[a-z0-9_]+$',
                    ) ),
                ) );
                $step['oneOf'] = array(
                    array( 'required' => array( 'tool' ), 'not' => array( 'required' => array( 'capability' ) ) ),
                    array( 'required' => array( 'capability' ), 'not' => array( 'required' => array( 'tool' ) ) ),
                );
                $input = self::obj( array(
                    'steps' => array(
                        'type' => 'array',
                        'description' => 'Ordered deterministic steps. Each step selects exactly one typed tool or one capability.',
                        'minItems' => 1,
                        'maxItems' => 100,
                        'items' => $step,
                    ),
                    'stop_on_error' => self::boolean( 'Stop at the first failed step.', array( 'default' => true ) ),
                    'lane_handle' => self::str( 'Lane handle reused by every step.', array( 'minLength' => 1, 'maxLength' => 512 ) ),
                    'work_id' => self::str( 'Existing work session for anti-crash attestation reuse.', array( 'minLength' => 1, 'maxLength' => 128 ) ),
                ), array( 'steps' ) );
                // A flow can contain writes or destructive calls, so advertise conservative hints.
                $annotations['readOnlyHint'] = false;
                $annotations['destructiveHint'] = true;
                $annotations['idempotentHint'] = false;
                $annotations['openWorldHint'] = true;
                break;

            case 'prstudio_intervention_record':
                self::merge_property( $input, 'entity_type', array(
                    'minLength' => 1,
                    'maxLength' => 32,
                    'examples' => array( 'post', 'page', 'url', 'product', 'option', 'term', 'site' ),
                    'description' => 'Stable entity type used to build the intervention-ledger entity key.',
                ) );
                self::merge_property( $input, 'entity_id', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'intervention_key', array(
                    'minLength' => 1,
                    'maxLength' => 190,
                    'pattern' => '^[A-Za-z0-9_-]+$',
                    'description' => 'Stable change slug, for example meta_description or image_alt_text.',
                ) );
                self::merge_property( $input, 'summary', array( 'maxLength' => 255 ) );
                self::merge_property( $input, 'evidence_ref', array( 'maxLength' => 190 ) );
                break;

            case 'prstudio_job_control':
            case 'prstudio_job_get':
                self::merge_property( $input, 'job_id', array(
                    'minLength' => 36,
                    'maxLength' => 36,
                    'format' => 'uuid',
                    'description' => 'Durable job UUID.',
                ) );
                break;

            case 'prstudio_observe':
                self::merge_property( $input, 'url', array( 'minLength' => 1, 'maxLength' => 4096 ) );
                self::merge_property( $input, 'name', array( 'minLength' => 1, 'maxLength' => 191 ) );
                if ( isset( $input['properties']['anchors'] ) && is_array( $input['properties']['anchors'] ) ) {
                    $input['properties']['anchors']['uniqueItems'] = true;
                    if ( isset( $input['properties']['anchors']['items'] ) && is_array( $input['properties']['anchors']['items'] ) ) {
                        $input['properties']['anchors']['items']['minLength'] = 1;
                        $input['properties']['anchors']['items']['maxLength'] = 4096;
                    }
                }
                $input['allOf'] = array(
                    array(
                        'if' => array( 'required' => array( 'target' ), 'properties' => array( 'target' => array( 'enum' => array( 'post', 'page', 'product' ) ) ) ),
                        'then' => array( 'anyOf' => array( array( 'required' => array( 'id' ) ), array( 'required' => array( 'url' ) ) ) ),
                    ),
                    array(
                        'if' => array( 'required' => array( 'target' ), 'properties' => array( 'target' => array( 'const' => 'url' ) ) ),
                        'then' => array( 'required' => array( 'url' ) ),
                    ),
                    array(
                        'if' => array( 'required' => array( 'target' ), 'properties' => array( 'target' => array( 'const' => 'option' ) ) ),
                        'then' => array( 'anyOf' => array( array( 'required' => array( 'name' ) ), array( 'required' => array( 'id' ) ) ) ),
                    ),
                    array(
                        'if' => array( 'required' => array( 'target' ), 'properties' => array( 'target' => array( 'const' => 'term' ) ) ),
                        'then' => array( 'required' => array( 'id' ) ),
                    ),
                );
                break;

            case 'prstudio_seo_autopilot_status':
                self::merge_property( $input, 'campaign_id', array(
                    'description' => 'Optional campaign ID; omit to inspect the active campaign.',
                ) );
                break;

            case 'prstudio_tool_manual':
                self::merge_property( $input, 'tool', array(
                    'minLength' => 1,
                    'maxLength' => 64,
                    'pattern' => '^[A-Za-z0-9_-]+$',
                    'description' => 'Exact MCP tool name. Omit to return the manual index.',
                ) );
                break;

            case 'sentinel_scan':
                if ( isset( $input['properties']['scope'] ) && is_array( $input['properties']['scope'] ) ) {
                    $input['properties']['scope']['minItems'] = 1;
                    $input['properties']['scope']['uniqueItems'] = true;
                    $input['properties']['scope']['items'] = self::str(
                        'Scan dimension.',
                        array( 'enum' => array( 'health', 'queue', 'content' ) )
                    );
                }
                break;

            case 'sequential_thinking_session':
                self::merge_property( $input, 'session_id', array(
                    'description' => 'Exact structured Sequential Thinking session ID.',
                ) );
                break;

            case 'social_metrics_ingest':
                $metric_value = array(
                    'type' => 'number',
                    'minimum' => -1000000000000,
                    'maximum' => 1000000000000,
                    'description' => 'Finite aggregate metric value.',
                );
                $content_item = self::obj( array(
                    'id' => self::str( 'Provider content ID.', array( 'minLength' => 1, 'maxLength' => 190 ) ),
                    'url' => self::str( 'Canonical content URL when known.', array( 'minLength' => 1, 'maxLength' => 4096 ) ),
                    'type' => self::str( 'Provider content type.', array( 'minLength' => 1, 'maxLength' => 64 ) ),
                    'published_gmt' => self::str( 'Provider publication timestamp in UTC/RFC3339 form when known.', array( 'minLength' => 1, 'maxLength' => 64 ) ),
                    'caption_excerpt' => self::str( 'Bounded caption excerpt.', array( 'maxLength' => 500 ) ),
                    'caption' => self::str( 'Caption; the ingest normalizer stores a bounded excerpt.', array( 'maxLength' => 5000 ) ),
                    'metrics' => array(
                        'type' => 'object',
                        'description' => 'Per-content numeric metrics.',
                        'maxProperties' => 120,
                        'additionalProperties' => $metric_value,
                    ),
                ) );
                $input = self::obj( array(
                    'platform' => self::str( 'Social platform.', array(
                        'enum' => array( 'instagram', 'facebook', 'tiktok', 'youtube', 'linkedin', 'x', 'threads', 'pinterest', 'snapchat', 'other' ),
                        'default' => 'other',
                    ) ),
                    'account' => self::str( 'Account/profile identifier for this snapshot.', array( 'minLength' => 1, 'maxLength' => 190 ) ),
                    'source' => self::str( 'How the aggregate metrics were obtained.', array(
                        'enum' => array( 'manual', 'browser_live', 'api', 'webhook', 'import' ),
                        'default' => 'manual',
                    ) ),
                    'observed_gmt' => self::str( 'Observation timestamp; omit to use server time.', array( 'minLength' => 1, 'maxLength' => 64 ) ),
                    'period_start' => self::str( 'Optional reporting-period start.', array( 'minLength' => 1, 'maxLength' => 64 ) ),
                    'period_end' => self::str( 'Optional reporting-period end.', array( 'minLength' => 1, 'maxLength' => 64 ) ),
                    'metrics' => array(
                        'type' => 'object',
                        'description' => 'Aggregate numeric account metrics. Up to 120 provider-neutral keys.',
                        'maxProperties' => 120,
                        'additionalProperties' => $metric_value,
                    ),
                    'content' => array(
                        'type' => 'array',
                        'description' => 'Optional bounded per-content aggregates.',
                        'maxItems' => 100,
                        'items' => $content_item,
                    ),
                ), array( 'account' ) );
                $annotations['readOnlyHint'] = false;
                $annotations['destructiveHint'] = false;
                $annotations['idempotentHint'] = false;
                $annotations['openWorldHint'] = true;
                break;

            case 'twin_query':
                self::add_required( $input, 'query' );
                self::merge_property( $input, 'type', array(
                    'minLength' => 1,
                    'maxLength' => 64,
                    'examples' => array( 'site', 'post', 'page', 'product', 'url' ),
                    'description' => 'Optional operational-twin entity-type filter.',
                ) );
                break;

            case 'wordpress_content_transaction':
                self::merge_property( $input, 'search', array(
                    'minLength' => 1,
                    'description' => 'Exact anchor. Required for replace_exact, insert_before, and insert_after.',
                ) );
                self::merge_property( $input, 'expected_before_sha256', array(
                    'minLength' => 64,
                    'maxLength' => 64,
                    'pattern' => '^[a-fA-F0-9]{64}$',
                    'description' => 'Optional SHA-256 optimistic-concurrency precondition.',
                ) );
                self::merge_property( $input, 'idempotency_marker', array( 'minLength' => 1, 'maxLength' => 512 ) );
                $input['allOf'] = array(
                    array(
                        'if' => array(
                            'required' => array( 'operation' ),
                            'properties' => array(
                                'operation' => array( 'enum' => array( 'replace_exact', 'insert_before', 'insert_after' ) ),
                            ),
                        ),
                        'then' => array( 'required' => array( 'search' ) ),
                    ),
                );
                break;
        }

        return array( $description, $input, $annotations );
    }

    /** Model-facing first sentence; full legacy guidance remains appended. */
    private static function lead( string $name ): string {
        $map = array(
            'agency_status' => 'Inspect durable agency queues, schedules, dead letters, Browser availability, and truthful H24 runner health.',
            'browser_adopt_tabs' => 'Adopt explicitly selected already-open HTTP(S) tabs into the current execution lane.',
            'browser_launch' => 'Establish the paired Browser Agent human-work context for an execution lane before controlled browsing.',
            'browser_open' => 'Open one HTTP(S) URL or bare web host in the controlled Browser Agent and take ownership of the resulting tab.',
            'browser_screenshot' => 'Capture bounded visual evidence from a controlled Browser Agent tab, with optional OCR only when needed.',
            'browser_snapshot' => 'Observe a controlled tab with a bounded screenshot, DOM/AX targets, and explicit screenshot-to-CDP coordinate context.',
            'browser_status' => 'Inspect Browser Agent devices or wait for one browser task to settle by task_id.',
            'browser_task_control' => 'Cancel or requeue one Browser Agent task from the server side.',
            'engineering_repo_map' => 'Build a bounded symbol/hash map of files under the PR STUDIO plugin root for context-efficient engineering work.',
            'engineering_status' => 'Inspect the bounded no-arbitrary-shell engineering workbench and its anti-crash operation catalogue.',
            'procedural_skill_get' => 'Read one verified procedural skill together with its progressive-disclosure SKILL.md.',
            'procedural_skill_invalidate' => 'Invalidate a procedural skill after environment or application behavior changes while preserving its history.',
            'procedural_skill_search' => 'Search verified procedural skills for a known task before inventing or retrying a procedure.',
            'procedural_skill_status' => 'Inspect counts of learned, reused, stale, and invalidated procedural skills.',
            'prstudio_backlog' => 'List genuinely outstanding site work from the interventions ledger, excluding work already settled.',
            'prstudio_capability_describe' => 'Resolve one capability ID to its exact argument contract, prerequisites, and risk before execution.',
            'prstudio_capability_search' => 'Search server-side WordPress, WooCommerce, database, and SEO capabilities when no direct typed tool fits.',
            'prstudio_context_close' => 'Release every entity and tab lease held by one ChatGPT execution lane.',
            'prstudio_context_open' => 'Open or recover one collision-isolated ChatGPT workstream before mutations.',
            'prstudio_context_status' => 'Inspect execution lanes owned by the current MCP OAuth client.',
            'prstudio_do' => 'Route a high-level intent to the appropriate PR STUDIO operation without making the caller choose a tool name.',
            'prstudio_execute' => 'Execute one server-side capability using the exact argument contract returned by prstudio_capability_describe.',
            'prstudio_flow' => 'Execute an ordered deterministic sequence of typed tools and/or capability IDs in one MCP turn.',
            'prstudio_health' => 'Inspect Control, Browser, registry, memory, OAuth, and recovery health at compact or full detail.',
            'prstudio_intervention_record' => 'Persist one interventions-ledger state transition so settled work is not proposed again.',
            'prstudio_job_control' => 'Cancel, interrupt, recover, or retry one durable PR STUDIO job.',
            'prstudio_job_get' => 'Read one durable PR STUDIO job and optionally wait for it to settle.',
            'prstudio_observe' => 'Observe one site entity and return content, mutation preconditions, and a signed write_token when writable.',
            'prstudio_seo_autopilot_status' => 'Read SEO autopilot state counters for the active or a named campaign without mutating it.',
            'prstudio_tool_manual' => 'Read complete operating guidance, safety annotations, and the exact input schema for one tool.',
            'sentinel_scan' => 'Scan bounded durable health, queue, and content-backlog dimensions and persist Sentinel findings.',
            'sequential_thinking_session' => 'Read one explicit structured Sequential Thinking session by session ID.',
            'sequential_thinking_status' => 'Inspect Sequential Thinking native/sidecar readiness and bounded session metrics.',
            'social_metrics_ingest' => 'Persist one normalized aggregate social-metrics snapshot with explicit platform, account, source, and observation time.',
            'twin_query' => 'Search compact operational-twin site, content, and commerce facts with explicit provenance.',
            'wordpress_content_transaction' => 'Apply one deterministic existing-post/page content transaction with optimistic concurrency and optional public verification.',
        );
        return isset( $map[ $name ] ) ? $map[ $name ] : '';
    }

    private static function merge_property( array &$schema, string $key, array $extra ): void {
        if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) { return; }
        if ( ! isset( $schema['properties'][ $key ] ) || ! is_array( $schema['properties'][ $key ] ) ) { return; }
        $schema['properties'][ $key ] = array_merge( $schema['properties'][ $key ], $extra );
    }

    private static function add_required( array &$schema, string $key ): void {
        $required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
        if ( ! in_array( $key, $required, true ) ) { $required[] = $key; }
        $schema['required'] = array_values( $required );
    }

    private static function obj( array $properties = array(), array $required = array() ): array {
        $schema = array(
            'type' => 'object',
            'properties' => empty( $properties ) ? new stdClass() : $properties,
            'additionalProperties' => false,
        );
        if ( $required ) { $schema['required'] = array_values( $required ); }
        return $schema;
    }

    private static function str( string $description, array $extra = array() ): array {
        return array_merge( array( 'type' => 'string', 'description' => $description ), $extra );
    }

    private static function boolean( string $description, array $extra = array() ): array {
        return array_merge( array( 'type' => 'boolean', 'description' => $description ), $extra );
    }

    private static function any_object( string $description ): array {
        return array(
            'type' => 'object',
            'description' => $description,
            'properties' => new stdClass(),
            'additionalProperties' => true,
        );
    }
}
