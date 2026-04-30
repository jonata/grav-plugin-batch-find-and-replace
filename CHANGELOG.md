# v0.0.4
## 04/30/2026

1. [](#new)
    * **Multiline Find and Replace.** Both fields are now `<textarea>` inputs that carry real newlines through the form. Patterns can now span multiple lines (literal mode: paste or press Enter; regex mode: write `\R`, `\n`, etc.). Press Ctrl+Enter (Cmd+Enter on macOS) to run.
    * Server-side scanning rewritten to match against the full file at once via `preg_match_all` with `PREG_OFFSET_CAPTURE`, then map byte offsets to 1-based line numbers via a precomputed line-offset table (binary search, O(log n) per match). Multiline matches are reported with the starting line number and an excerpt covering every line they span.
1. [](#improved)
    * During a **Replace all**, the results table is dimmed until the auto-refresh search renders, so it's clear the displayed results are about to update.
    * Per-row "Replace" buttons are hidden when the find pattern is multiline (the per-row endpoint operates on a single line; only "Replace all" can apply multiline patterns).
    * `makeExcerpt()` reordered to wrap match highlights **before** collapsing whitespace, so multiline patterns still highlight correctly in the report.

# v0.0.3
## 04/29/2026

1. [](#bugfix)
    * Fixed README demo embed: GitHub strips raw `<video>` tags, so the demo is now embedded as an animated GIF (`media/demo.gif`) that links to the source MP4. The GIF autoplays in the rendered README on GitHub.

# v0.0.2
## 04/29/2026

1. [](#improved)
    * After a successful **Replace all**, the table is automatically refreshed by re-running the same query as a search, so the user can verify the post-replace state (typically empty). The replacement summary stays visible briefly before the search summary takes over.
    * Added `media/demo.mp4` — a short screen recording of the plugin in action.

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
