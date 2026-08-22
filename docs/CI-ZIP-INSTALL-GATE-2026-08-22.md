# CI WordPress ZIP installation gate — 2026-08-22

## Incident

A real WordPress installation from the release ZIP failed with `Could not copy file` on different plugin files across attempts. A focused diagnostic ZIP containing both a benign PHP payload and the exact `contract/action-hot-index.php` installed successfully on the same host, so the incident cannot be explained by a deterministic corruption of that entry alone.

## Certification gap found

The existing Unified H24 WordPress job did **not** install the release ZIP. It copied the checkout directly into `wp-content/plugins/prstudio-unified-control` and then ran `wp plugin activate`.

That proves activation/runtime behavior after the files already exist on disk, but it does not exercise the WordPress upgrader path used by an administrator upload:

`ZIP -> ZipArchive -> WP_Filesystem -> plugin directory -> activation`.

The production-readiness lab report likewise used a copy of the checkout and `activate_plugin()`. Its previous heading `Installazione WordPress reale` therefore referred to WordPress core installation plus plugin activation, not installation of the plugin package itself.

## Required gate

A green WordPress control-plane job must:

1. Build `prstudio-unified-control-1.0.0.zip` with `tests/build-release.py --build`.
2. Verify the release archive with `tests/build-release.py --check`.
3. Create a clean real WordPress 7.0.4 + MariaDB instance.
4. Install the generated ZIP through WP-CLI `plugin install <zip> --activate`, which uses the WordPress plugin upgrader/filesystem path rather than copying the checkout.
5. Run the existing real activation/MCP probe against the installed package.
6. Publish the generated installable ZIP as a GitHub Actions artifact so operators never have to recompress the source folder manuallymente.

Until that gate is green on the exact candidate commit, CI must not claim that the WordPress release ZIP has been installation-tested.
