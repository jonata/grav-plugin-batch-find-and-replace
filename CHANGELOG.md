# v0.0.1
## 04/29/2026

1. [](#new)
    * Initial release
    * Admin-only sidebar entry "Batch Find and Replace"
    * Find and/or replace text, exact strings, or PCRE regex patterns across all `.md` files under `user/pages/`
    * "Use Regex" toggle (UTF-8 enabled by default)
    * "Case Sensitive" toggle for both literal and regex modes
    * "Search Only" toggle for non-destructive previews
    * Per-row surgical replace (one occurrence at a time)
    * HTML report with summary and per-file table of line numbers + highlighted excerpts
    * Click-through links from report to the page editor
    * Configurable display cap (`max_results`, default 500) — replacements always run on the full set
    * CSRF-protected via Grav admin's `admin-form` nonce
    * Optional logging of all operations to `logs/grav.log`
    * Permission gated to `admin.super` or `admin.maintenance`
