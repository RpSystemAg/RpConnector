<?php
// phpcs:ignore missing_direct_file_access_protection -- direct-access guard IS present on the line below; it uses `&& ! defined('PRSTUDIO_UC_TESTING')` for testability and Plugin Check's static pattern doesn't recognize that compound form.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Motion (motion.dev) animation delivery for the live WordPress front end.
 *
 * WHY THIS IS A THIN LAYER
 * ------------------------
 * The obvious-looking approach -- proxy motion.dev's own MCP server from this
 * plugin -- does not fit. Their hosted server authenticates through the calling
 * agent's Motion+ sign-in, which a WordPress site cannot perform on the user's
 * behalf; the good community server is a local stdio process, unreachable from a
 * web host; and putting a third-party network call inside tools/list would make
 * every connection to this server depend on their availability.
 *
 * More to the point, that server returns animation *knowledge* -- docs and
 * generated snippets -- which the connected model largely has. What it cannot do
 * is put animation on this site. That was the actual gap, and the delivery lane
 * for it already existed here: managed front-end scripts render in wp_footer,
 * and Additional CSS is written through WordPress core with a read-back check.
 *
 * So this class owns the small part that was genuinely missing: pinning the
 * Motion runtime, keeping a registry of selector-to-animation bindings, and
 * emitting one idempotent script that applies them. The model brings the
 * creative decision; this makes it land on the page and stay there.
 *
 * PRIVACY AND FAILURE POSTURE
 * The runtime is loaded from a public CDN, which means visitors' browsers
 * request it from a third party. That is stated in status() rather than buried,
 * because it is a real consequence of enabling this. If the CDN is unreachable
 * the page renders unanimated -- elements are never hidden by this script until
 * the library is confirmed loaded, so a failed load degrades to a normal page
 * instead of a blank one.
 */
final class PRSTUDIO_UC_Motion {

    public const VERSION = '1.0.0';

    /** Managed front-end script id; one entry, rewritten in place. */
    private const SCRIPT_ID = 'prstudio_motion';
    /** Where the selector -> animation bindings live. */
    private const OPTION = 'prstudio_uc_motion_v1';
    /** Pinned so a CDN "latest" cannot change behaviour under a live site. */
    private const MOTION_VERSION = '11.11.17';
    private const MAX_BINDINGS = 60;

    /**
     * Animation presets.
     *
     * Deliberately a closed set. An open "run this JavaScript" surface would put
     * caller-supplied script on the front end of a production site, which is the
     * same exposure the suite removed elsewhere; a named preset with bounded
     * numeric options gives the same visual result without that.
     */
    private static function presets(): array {
        return array(
            'fade_in'       => array( 'label' => 'Fade in on scroll',        'scroll' => true ),
            'slide_up'      => array( 'label' => 'Slide up on scroll',       'scroll' => true ),
            'slide_left'    => array( 'label' => 'Slide in from left',       'scroll' => true ),
            'slide_right'   => array( 'label' => 'Slide in from right',      'scroll' => true ),
            'scale_in'      => array( 'label' => 'Scale in on scroll',       'scroll' => true ),
            'blur_in'       => array( 'label' => 'Blur to sharp on scroll',  'scroll' => true ),
            'stagger_children' => array( 'label' => 'Stagger direct children on scroll', 'scroll' => true ),
            'parallax'      => array( 'label' => 'Parallax drift while scrolling', 'scroll' => false ),
            'hover_lift'    => array( 'label' => 'Lift on hover',            'scroll' => false ),
        );
    }

    private static function state(): array {
        $v = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        if ( ! is_array( $v ) ) { $v = array(); }
        $v['bindings'] = isset( $v['bindings'] ) && is_array( $v['bindings'] ) ? $v['bindings'] : array();
        return $v;
    }

    private static function save( array $state ): bool {
        $state['updated_gmt'] = gmdate( 'c' );
        return function_exists( 'update_option' ) ? (bool) update_option( self::OPTION, $state, false ) : false;
    }

    /** Reject a selector that could not plausibly be one, before it reaches the page. */
    private static function clean_selector( string $selector ): string {
        $selector = trim( $selector );
        if ( '' === $selector || strlen( $selector ) > 200 ) { return ''; }
        if ( preg_match( '/[<>{}();]|javascript:|<\/?script/i', $selector ) ) { return ''; }
        return $selector;
    }

    /**
     * Apply, list or remove an animation binding.
     *
     * @return array|WP_Error
     */
    public static function control( array $args ) {
        $action = sanitize_key( (string) ( $args['action'] ?? 'apply' ) );

        if ( 'list' === $action ) { return self::status(); }

        if ( 'remove' === $action ) {
            $selector = self::clean_selector( (string) ( $args['selector'] ?? '' ) );
            $state = self::state();
            if ( '' === $selector ) {
                $state['bindings'] = array();
            } else {
                $state['bindings'] = array_values( array_filter(
                    $state['bindings'],
                    static fn( $b ) => (string) ( $b['selector'] ?? '' ) !== $selector
                ) );
            }
            self::save( $state );
            self::render();
            return array_merge( self::status(), array( 'removed' => '' === $selector ? 'all' : $selector ) );
        }

        if ( 'apply' !== $action ) {
            return new WP_Error( 'prstudio_motion_action_invalid', 'Use apply, list or remove.', array( 'status' => 400 ) );
        }

        $selector = self::clean_selector( (string) ( $args['selector'] ?? '' ) );
        if ( '' === $selector ) {
            return new WP_Error( 'prstudio_motion_selector_required', 'A CSS selector is required, e.g. ".hero h1" or "#pricing .card".', array( 'status' => 400 ) );
        }
        $preset = sanitize_key( (string) ( $args['preset'] ?? '' ) );
        $presets = self::presets();
        if ( ! isset( $presets[ $preset ] ) ) {
            return new WP_Error(
                'prstudio_motion_preset_invalid',
                'Unknown preset.',
                array( 'status' => 400, 'available' => array_keys( $presets ) )
            );
        }

        $binding = array(
            'selector' => $selector,
            'preset'   => $preset,
            'duration' => max( 0.1, min( 4.0, (float) ( $args['duration'] ?? 0.6 ) ) ),
            'delay'    => max( 0.0, min( 4.0, (float) ( $args['delay'] ?? 0.0 ) ) ),
            'distance' => max( 0, min( 400, (int) ( $args['distance'] ?? 24 ) ) ),
            'once'     => ! isset( $args['once'] ) || ! empty( $args['once'] ),
            'updated_gmt' => gmdate( 'c' ),
        );

        $state = self::state();
        // One binding per selector: re-applying updates in place rather than
        // stacking a second animation on the same elements.
        $state['bindings'] = array_values( array_filter(
            $state['bindings'],
            static fn( $b ) => (string) ( $b['selector'] ?? '' ) !== $selector
        ) );
        $state['bindings'][] = $binding;
        if ( count( $state['bindings'] ) > self::MAX_BINDINGS ) {
            $state['bindings'] = array_slice( $state['bindings'], -self::MAX_BINDINGS );
        }
        if ( ! self::save( $state ) ) {
            return new WP_Error( 'prstudio_motion_persist_failed', 'Could not persist the animation registry.', array( 'status' => 503, 'retryable' => true ) );
        }

        $render = self::render();
        if ( is_wp_error( $render ) ) { return $render; }

        return array_merge(
            self::status(),
            array(
                'applied' => $binding,
                'verify_with' => 'browser_open on a page matching this selector, then browser_screenshot; scroll-triggered presets need the element scrolled into view.',
            )
        );
    }

    /**
     * Write the managed front-end script from the current registry.
     *
     * Goes through the same managed-script store the rest of the suite uses, so
     * removal and inspection work the same way as for anything else on the page.
     *
     * @return true|WP_Error
     */
    private static function render() {
        $state = self::state();
        $bindings = array_values( $state['bindings'] );

        if ( ! $bindings ) {
            if ( class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) ) {
                PRSTUDIO_UC_Complete_Action_Executor::execute_action( '/frontend-manage', 'remove_injected_script', array( 'id' => self::SCRIPT_ID ) );
            }
            return true;
        }

        $config = wp_json_encode( array(
            'v' => self::VERSION,
            'src' => 'https://cdn.jsdelivr.net/npm/motion@' . self::MOTION_VERSION . '/+esm',
            'bindings' => $bindings,
        ) );
        if ( false === $config ) { return new WP_Error( 'prstudio_motion_encode_failed', 'Could not encode the animation registry.', array( 'status' => 500 ) ); }

        $script = self::runtime( $config );
        if ( ! class_exists( 'PRSTUDIO_UC_Complete_Action_Executor' ) ) {
            return new WP_Error( 'prstudio_motion_executor_missing', 'Front-end script delivery is unavailable on this install.', array( 'status' => 503 ) );
        }
        $result = PRSTUDIO_UC_Complete_Action_Executor::execute_action(
            '/frontend-manage',
            'inject_script',
            array( 'id' => self::SCRIPT_ID, 'script' => $script )
        );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * The front-end runtime.
     *
     * Two properties matter more than brevity here. Elements are only hidden
     * once the library has actually loaded, so a CDN failure leaves a normal
     * readable page rather than invisible content. And prefers-reduced-motion is
     * honoured by skipping animation entirely instead of speeding it up, because
     * for a visitor who set that preference a fast animation is still motion.
     */
    private static function runtime( string $config_json ): string {
        return <<<JS
/* PR STUDIO Motion delivery. Generated from the animation registry; edits here are overwritten. */
(function () {
  var CFG = {$config_json};
  if (!CFG || !CFG.bindings || !CFG.bindings.length) { return; }

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) { return; }

  function nodes(sel) {
    try { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
    catch (e) { return []; }
  }

  import(CFG.src).then(function (M) {
    var animate = M.animate, inView = M.inView, scroll = M.scroll, stagger = M.stagger;
    if (typeof animate !== 'function') { return; }

    CFG.bindings.forEach(function (b) {
      var els = nodes(b.selector);
      if (!els.length) { return; }
      var d = b.distance || 24;
      var opts = { duration: b.duration || 0.6, delay: b.delay || 0, ease: [0.22, 1, 0.36, 1] };

      if (b.preset === 'parallax') {
        if (typeof scroll !== 'function') { return; }
        els.forEach(function (el) {
          scroll(animate(el, { y: [d, -d] }, { ease: 'linear' }), { target: el, offset: ['start end', 'end start'] });
        });
        return;
      }

      if (b.preset === 'hover_lift') {
        els.forEach(function (el) {
          el.addEventListener('mouseenter', function () { animate(el, { y: -Math.min(d, 12), scale: 1.02 }, opts); });
          el.addEventListener('mouseleave', function () { animate(el, { y: 0, scale: 1 }, opts); });
        });
        return;
      }

      var from = { opacity: [0, 1] };
      if (b.preset === 'slide_up')    { from.y = [d, 0]; }
      if (b.preset === 'slide_left')  { from.x = [-d, 0]; }
      if (b.preset === 'slide_right') { from.x = [d, 0]; }
      if (b.preset === 'scale_in')    { from.scale = [0.92, 1]; }
      if (b.preset === 'blur_in')     { from.filter = ['blur(10px)', 'blur(0px)']; }

      if (b.preset === 'stagger_children') {
        els.forEach(function (parent) {
          var kids = Array.prototype.slice.call(parent.children);
          if (!kids.length) { return; }
          kids.forEach(function (k) { k.style.opacity = '0'; });
          if (typeof inView !== 'function') { animate(kids, { opacity: [0, 1], y: [d, 0] }, opts); return; }
          inView(parent, function () {
            animate(kids, { opacity: [0, 1], y: [d, 0] },
              Object.assign({}, opts, { delay: typeof stagger === 'function' ? stagger(0.08) : opts.delay }));
            return b.once === false ? undefined : false;
          });
        });
        return;
      }

      // Hide only now that the library is present, so a failed CDN load can
      // never leave the page blank.
      els.forEach(function (el) { el.style.opacity = '0'; });
      if (typeof inView !== 'function') { animate(els, from, opts); return; }
      els.forEach(function (el) {
        inView(el, function () {
          animate(el, from, opts);
          return b.once === false ? undefined : false;
        });
      });
    });
  }).catch(function () { /* CDN unreachable: page stays readable and unanimated. */ });
})();
JS;
    }

    /** Current registry plus the facts a caller needs to reason about it. */
    public static function status(): array {
        $state = self::state();
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'component' => 'motion',
            'library' => 'motion.dev',
            'library_version' => self::MOTION_VERSION,
            'delivery' => 'managed front-end script rendered in wp_footer',
            'runtime_source' => 'jsdelivr CDN — visitors request the library from a third party',
            'respects_reduced_motion' => true,
            'bindings' => array_values( $state['bindings'] ),
            'binding_count' => count( $state['bindings'] ),
            'max_bindings' => self::MAX_BINDINGS,
            'presets' => self::presets(),
            'suite_version' => defined( 'PRSTUDIO_UC_VERSION' ) ? PRSTUDIO_UC_VERSION : '',
        );
    }
}
