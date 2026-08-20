# The WordPress Abilities API and this suite

**Study, 20 August 2026. Nothing here is implemented — this is a proposal and its costs.**

WordPress 7.1 shipped on 19 August 2026. Among the dev notes is a set of Abilities
API changes that matter to this product more than anything else in the release,
because WordPress core has now built the thing this suite built for itself: a
registry of named, schema-described, permission-checked actions with its own
discovery and filtering.

The question this document answers is not "should we adopt it" in the abstract.
It is: what does registering 1,295 capabilities as abilities actually cost, what
does it actually buy, and what breaks.

---

## What core now provides

Registration, from the 6.9 dev note, on the `wp_abilities_api_init` action:

```php
wp_register_ability(
    'namespace/ability-name',
    array(
        'label'               => __( 'Display Name', 'text-domain' ),
        'description'         => __( 'What this ability does', 'text-domain' ),
        'category'            => 'category-slug',
        'input_schema'        => array( /* JSON Schema */ ),
        'output_schema'       => array( /* JSON Schema */ ),
        'execute_callback'    => 'callback_function_name',
        'permission_callback' => function () { /* permission logic */ },
        'meta'                => array( 'show_in_rest' => true ),
    )
);
```

Names take lowercase alphanumerics, dashes and forward slashes only.

Discovery, extended in 7.1, is `wp_get_abilities( array $args = array() )`:

| Key | Effect |
|---|---|
| `category` | filter to one category slug |
| `namespace` | filter to one namespace |
| `meta` | match metadata key/value pairs, nested arrays supported |
| `item_include_callback` | per-ability predicate receiving a `WP_Ability` |
| `result_callback` | process the whole matched array — sort, slice |

with `wp_get_abilities_item_include` and `wp_get_abilities_result` as global
filters over the same pipeline.

Lifecycle, new in 7.1:

- `wp_ability_invoked` — fires at the top of `WP_Ability::execute()`, **before**
  validation and permission checks. The dev note names auditing, telemetry and
  invocation accounting as the intended uses.
- `wp_ability_validate_input` / `wp_ability_validate_output` — supplement
  validation, returning `true` or a `WP_Error`.
- `fields` on input — request a subset of the response.
- a `public` meta flag exposing the ability at
  `/wp-json/wp-abilities/v1/abilities`.

---

## Where this suite already lines up

The mapping is closer than it has any right to be, because both designs were
solving the same problem.

| This suite | Abilities API |
|---|---|
| capability id `legacy.catalog-commerce.products-manage.create` | `prstudio/legacy-catalog-commerce-products-manage-create` |
| `domain` (15 distinct) | `category` |
| `executor` (`Class::method`) | `execute_callback` |
| `read_only`, `destructive`, `idempotent`, `risk_level` | `meta` |
| `description` | `description` |
| `bridge_permission` | `permission_callback` |
| `prstudio_capability_search` | `wp_get_abilities( $args )` |

Measured against `capability-search-index.json` on 20 August 2026:

- **1,295** capabilities, **all** with a declared executor
- **20** native, **1,076** legacy actions, **199** legacy direct tools
- **448** read-only, so **847** that mutate something
- **15** domains
- **0** ids that fail the ability naming rule once dots become dashes

That last line is the pleasant surprise: the id scheme converts cleanly and
deterministically. There is no naming problem to solve.

---

## The thing worth doing, which is not the obvious one

The obvious move is to publish our capabilities so other clients can find them.
That is worth something.

The larger prize is the other direction. `wp_get_abilities()` returns **every**
ability registered on the site, not only ours. A WordPress install running thirty
plugins that register abilities is thirty integrations this suite could drive
without anyone writing an integration — discovered at runtime, with schemas
attached, permission-checked by the plugin that owns the action.

`prstudio_capability_search` today searches a catalogue we generate. If it also
searched `wp_get_abilities()`, the answer to "how do I do X on this site" would
include things nobody here has ever heard of. That is a change in kind, not in
degree, and it costs far less than registering 1,295 abilities.

**Recommendation: do the consuming side first.** It is smaller, it is reversible,
it has no registration cost, and it is where the leverage is.

---

## What it costs, honestly

### The registration cost is real and this codebase has already paid it once

`class-prstudio-uc-autoload.php` exists because the previous version issued 113
unconditional `require_once` calls and parsed roughly 2 MB of PHP on every
WordPress request — including front-end page views by actual customers who never
touch the control plane. The comment in that file is worth re-reading before
proposing anything that runs on every request.

Registering 1,295 abilities means building 1,295 arrays with labels, descriptions,
two JSON Schemas and two closures each, on whatever request fires
`wp_abilities_api_init`. That is the same mistake in a new costume unless the
hook only fires when abilities are actually wanted — which needs to be verified
against core, not assumed.

### The schemas are not where you would want them

**0 of the 1,295 entries in the compact index carry an `input_schema`.** They are
resolved at runtime by `normalize_capability()`, out of `WPAIB_MCP` annotations
or Agency contracts, and only for the capability being looked at.

`input_schema` and `output_schema` are the main reason an ability is worth more
than a function name. Registering without them produces 1,295 discoverable
actions nobody can call correctly. Producing them for all 1,295 at registration
time means running the resolution path 1,295 times per request.

This is the real blocker, and it is a build-step problem: the schemas should be
resolved once and written into the index, not resolved per request. That work is
useful regardless of whether abilities ever ship.

### LAW 1 is the trap

The Abilities API offers `permission_callback`, `wp_ability_validate_input` and
`wp_ability_validate_output`. Two of those three are safe; one is not.

`permission_callback` is authorisation — who is asking — and this suite already
has that in `bridge_permission`. Fine.

`wp_ability_validate_input` is where a second mutation guard would be born. It
sits before execution, it can return `WP_Error`, and it would be extremely
natural to put a risk check in it. **LAW 1 says the anti-crash gate is the only
blocking pre-mutation guard.** Input validation may reject input that does not
match the schema. It must not reject input it considers dangerous.

`wp_ability_invoked` is the useful one: it fires before validation and permission
checks, the dev note names telemetry and invocation accounting as its purpose,
and this suite has an interventions ledger that wants exactly that signal.
Non-blocking by construction, which is the correct shape.

### Versions

`wp_register_ability()` requires 6.9; the 7.1 filtering requires 7.1. The plugin
header declares `Requires at least: 6.5`, so every call site needs a
`function_exists()` guard, and the feature has to degrade to nothing rather than
fatal.

Worse for review: `php-stubs/wordpress-stubs` has no 7.1 release — 7.0.1, from 10
July, is the newest that exists. Every 7.1-only function is invisible to PHPStan
and will be reported as unknown rather than checked. That is recorded in
`phpstan.neon`.

---

## Proposed order

**1 — Consume abilities in capability search.** Add `wp_get_abilities()` results
to `prstudio_capability_search`, behind `function_exists()`. No registration, no
per-request cost, and it makes every ability-registering plugin on the site
reachable. Test: a fixture ability is registered and the search finds it.

**2 — Resolve schemas at build time.** Write `input_schema` and `output_schema`
into `capability-search-index.json` during generation instead of resolving them
per lookup. Independently useful: it is also what `tools/list` and the enterprise
contracts want, and it is the precondition for anything in step 3.

**3 — Register a curated set, and measure.** The 20 native capabilities plus the
26 currently emitted tools, with real schemas, `category` from `domain`, `meta`
carrying `read_only`/`risk_level`, and `show_in_rest` only where the capability
is safe to expose. Measure the added time on a cold request before going wider.

**4 — Wire `wp_ability_invoked` into the interventions ledger.** Cheap,
non-blocking, and it gives one accounting point for both MCP calls and abilities
invoked by anyone else.

**5 — Only then consider registering the rest,** and only if step 3's measurement
says the per-request cost is acceptable. It may well not be, and "we register the
useful ones" is a perfectly good permanent answer.

---

## What this does not change

The LAW 9 ceiling is about `tools/list`: 26 tools emitted, 98 withheld, 4,872
real tokens against 5,000. Abilities are a different surface with their own
discovery, so registering them costs nothing there. The ceiling and the ability
registry are orthogonal, and adopting one does not relieve the other.

---

## Sources

- [WordPress 7.1 Field Guide](https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/)
- [Abilities API improvements in WordPress 7.1](https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/)
- [Filtering registered abilities with wp_get_abilities()](https://make.wordpress.org/core/2026/08/05/filtering-registered-abilities-with-wp_get_abilities-in-wordpress-7-1/)
- [Abilities API in WordPress 6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)
- [What's new for developers, August 2026](https://developer.wordpress.org/news/2026/08/whats-new-for-developers-august-2026/)
