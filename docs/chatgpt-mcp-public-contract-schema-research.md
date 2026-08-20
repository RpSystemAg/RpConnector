# ChatGPT / MCP public tool contract schema research

Date: 2026-08-20

This note records the design basis for the public RP Studio Connector contract refinements in `class-prstudio-uc-public-tool-contracts.php`.

## Sources

- OpenAI, *Latest model / tool-use guidance*: https://developers.openai.com/api/docs/guides/latest-model
- Model Context Protocol, *2026-07-28 release candidate*: https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/
- Model Context Protocol, *Tool annotations*: https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/
- Playwright, *Locators*: https://playwright.dev/docs/locators

## Contract rules applied

1. Public descriptions lead with the action and the decision boundary: what the tool does and when it is the right tool. Long operating guidance remains available through `prstudio_tool_manual` instead of bloating `tools/list`.
2. Stable vocabularies use enums. Stable identifiers and bounded text use realistic length/pattern constraints. URLs and UUID-shaped values advertise formats when the runtime semantics support them.
3. Conditional contracts use JSON Schema composition where the runtime has conditional requirements, for example `prstudio_observe`, `prstudio_flow`, and `wordpress_content_transaction`.
4. Dynamic objects stay dynamic only when the schema is genuinely selected at runtime. In particular, `prstudio_execute.arguments` is defined by `prstudio_capability_describe`, and `prstudio_do.params` is defined by the resolved intent.
5. `social_metrics_ingest` is no longer an unbounded free-form object: its public schema mirrors the provider-neutral normalization already implemented by the backend, including account/source/platform vocabularies and metric/content bounds.
6. Browser target discovery prefers reusable target references and semantic role/label/text locators before CSS/XPath fallback. This follows the Browser Agent's own snapshot model and Playwright's resilience guidance.
7. MCP annotations reflect the worst operation a tool can perform. `prstudio_flow`, for example, cannot truthfully advertise `readOnlyHint=true` because a flow may contain mutations or destructive operations.
8. Compatibility is preserved where the runtime intentionally infers values. `prstudio_observe.target` remains optional because the handler infers `url` from `url` and `post` from `id`; conditional requirements apply only once an explicit target is selected.

## Deliberately not changed

The generic `outputSchema` envelope remains optional/off by default. RP Studio already has a bounded `tools/list` token budget, and duplicating the same large output envelope across every tool would spend catalog context without improving tool selection. Tool-specific input precision and truthful descriptions/annotations produce more value within that budget.
