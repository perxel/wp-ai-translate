# CLAUDE.md

Guidance for working on this repository.

## What this is

`perxel-ai-translate` — a **public** WordPress plugin (WordPress.org directory,
slug `perxel-ai-translate`, published under the `phucbm` account, branded Perxel).
It bulk-translates WPML content through the OpenRouter API.

It was converted from a private client mu-plugin (`perxel-ai-translate-wpml`,
"Khatra AI Translate"). The conversion goal was: a proper, generic, English-first
`.org` plugin **without** architecture or feature changes. Keep that discipline —
behaviour changes belong in their own, clearly-scoped follow-up.

## Layout

```
perxel-ai-translate.php     Main file: constants, requires, boot, activation hook
uninstall.php               Removes the option + log dir on delete
includes/class-pxat-*.php   One class per concern, prefix PXAT_ / pxat_
views/*.php                 Admin screen templates (variables documented in each header)
assets/js, assets/css       Admin-only JS/CSS
languages/                  .pot template
readme.txt                  WordPress.org listing (keep in sync with README.md + version)
.wordpress-org/             Listing assets (icon/banner/screenshots) — not shipped
.github/workflows/          lint.yml (PHPCS + Plugin Check), deploy.yml (tag → SVN)
```

## Conventions

- **Prefix everything** `pxat_` / `PXAT_` / `pxat-`. Enforced by PHPCS.
- **Text domain** is `perxel-ai-translate`, matching the slug. English is the
  source language; translations ship later via `languages/`.
- **i18n in JS** uses `wp.i18n` (`wp_set_script_translations`), handle deps
  include `wp-i18n`.
- **No white-labelling.** The product name is the constant `PXAT_NAME`
  (`'Perxel AI Translate'`), used directly. There is no rebrand option.
- **Cost is shown in USD** (`PXAT_Format::cost()`), OpenRouter's native unit.
- Diagnostic **log breadcrumbs** (batch job logs) are plain English strings, not
  wrapped in `__()`. User-facing messages, labels and notices are translated.
- The **batch queue** is JSON files under
  `wp-content/uploads/perxel-ai-translate/logs/`, with `flock()` for the parallel
  "Auto (batched)" workers. This is deliberate (WP_Filesystem has no locking) and
  documented in `class-pxat-batch.php`. A move to a custom DB table is a possible
  future change, not a v1 one.
- WPML is only ever touched through `PXAT_WPML` (filter/action wrappers).

## Before committing

```bash
php -l <changed files>
composer run lint          # needs `composer install` first
composer run build         # bin/build-zip.sh — installable zip in dist/
```

CI also runs the official **Plugin Check** action — treat its output as the
WordPress.org review would.

## Releasing

1. Bump the version in `perxel-ai-translate.php` (header + `PXAT_VERSION`),
   `readme.txt` (`Stable tag`), and add a changelog entry in both readmes.
   The tag must equal the `Version:` header or `release.yml` fails.
2. Create a GitHub Release with that tag.
3. `release.yml` attaches `perxel-ai-translate.zip` to the release (always).
   `deploy.yml` pushes to WordPress.org SVN (needs `SVN_USERNAME` /
   `SVN_PASSWORD` secrets; the SVN repo only exists after the first manual
   review is approved).

Build artifacts (`dist/`) are never committed.
