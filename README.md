# Batch Find and Replace

A Grav CMS admin plugin that lets administrators **find and/or replace** text, exact strings, or PCRE regex patterns across **all** `.md` page files under `user/pages/` — in batch.

<video src="https://github.com/jonata/grav-plugin-batch-find-and-replace/raw/main/media/demo.mp4" autoplay loop muted playsinline controls width="100%"></video>

> ⚠️ **WARNING:** This plugin modifies page files directly. Always make a full backup of your `user/pages/` directory before running a replacement. **There is no undo.**

## Features

- Sidebar entry in the Grav Admin panel ("Batch Replace", FontAwesome `fa-search`).
- **Find** + **Replace** text inputs.
- **Use Regex** — interpret the Find field as a PCRE pattern (UTF-8 enabled by default).
- **Case Sensitive** — toggle for both literal and regex modes.
- **Search Only (no replace)** — produces a report without modifying anything.
- Confirmation prompt before any destructive replacement.
- HTML report with summary (files scanned / files matched or modified / total occurrences) and a per-file table of line numbers + excerpts.
- Skips binary and non-`.md` files.
- Reports unreadable / non-writable files instead of crashing.
- Display capped at the first **500** matching lines (configurable) — replacements always run on the full set.
- Validates regex up front so a bad pattern returns a clear error, not a server crash.
- All operations logged to Grav's log (`logs/grav.log`) when "Log Operations" is enabled.
- CSRF-protected via Grav's `admin-form` nonce.

## Installation

1. Copy the `batch-find-and-replace` directory into `user/plugins/`:

   ```text
   user/plugins/batch-find-and-replace/
       batch-find-and-replace.php
       batch-find-and-replace.yaml
       blueprints.yaml
       README.md
       CHANGELOG.md
       LICENSE
       classes/BatchReplaceTool.php
       admin/blueprints/batch-find-and-replace.yaml
       admin/pages/batch-find-and-replace.md
       admin/templates/batch-find-and-replace.html.twig
   ```

2. Make sure the plugin is enabled (it is by default). You can verify in the Admin panel under **Plugins → Batch Find and Replace**, or check that `user/config/plugins/batch-find-and-replace.yaml` contains `enabled: true`.

3. Clear the Grav cache:

   ```sh
   bin/grav clear-cache
   ```

4. Log in to the Grav Admin panel as a user with the `admin.super` or `admin.maintenance` role, and click **Batch Find and Replace** in the sidebar.

## Usage

1. Open **Batch Find and Replace** in the Admin sidebar.
2. **Always start with "Search Only"** to preview matches.
3. Enter the **Find** string.
4. Optionally enable **Use Regex** (PCRE syntax, no delimiters — the plugin wraps your pattern with `~…~u`).
5. Optionally enable **Case Sensitive**.
6. Click **Search** to see the report.
7. To replace, uncheck **Search Only**, enter a replacement string (empty = delete matches), and click **Replace all**. Confirm the warning dialog. Or click the per-row **Replace** button to surgically replace one occurrence at a time.

### Regex tips

- Don't include delimiters — write `\bfoo\b` not `/\bfoo\b/`.
- The `u` (UTF-8) flag is always on; `i` is added when "Case Sensitive" is **off**.
- Backreferences (`$1`, `\1`) work in the replacement string per PHP's `preg_replace`.

## Configuration

`user/config/plugins/batch-find-and-replace.yaml`:

```yaml
enabled: true
max_results: 500     # max matching lines shown in the report (10–10000)
log_operations: true # log every search/replace to logs/grav.log
```

## Compatibility

- Grav CMS **1.7+**
- Grav Admin plugin **1.10+**
- PHP **8.0+**
- No external Composer dependencies.

## License

MIT
