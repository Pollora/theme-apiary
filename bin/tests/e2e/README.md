# Browser tests

Playwright specs for this theme, run on the framework's E2E harness: CI checks out
`pollora/framework`'s `tests/e2e` at the version the site runs, copies these files to
`specs/apiary/` and runs them there (so they import `../../support/site`).

Each spec creates the products it needs through the WooCommerce REST API and deletes
them afterwards. `bin/` is stripped by the scaffolder: none of this reaches a theme
generated from Apiary.
