
# MCP Toolchain 17.0.0

WP-CLI, filesystem, Git, SQLite/Postgres, PHP lint, JSON validation, Playwright/CDP, axe, PDF/Pandoc/Mermaid, OSV e Local WP restano **native-first e sidecar-on-demand**. Nessun processo parte al bootstrap, nessun `@latest`, nessun comando utente passa a una shell arbitraria. `proc_open` riceve argv, timeout e output cap.

`engineering_repo_map` restituisce errori tipizzati per path mancanti/non validi. `php_lint` usa l'exit code effettivo anche su Windows e conserva stdout/stderr bounded. La rigenerazione dei contratti usa `tests/dump-wpaib-tools.php` e `tests/regenerate-contract-artifacts.py --php-binary <php> --check`.

Il coding loop minimo è: map/search → patch autorizzata → lint → test → run/observe → verifica → rollback se necessario. La presenza di un tool non vale punti nell'Agent Bench: conta soltanto il completamento verificato del task.
