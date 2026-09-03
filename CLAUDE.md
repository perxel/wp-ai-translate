# CLAUDE.md

Guidance for working on this repository.

## What this is

`perxel-ai-translate` - a **public** WordPress plugin (repo
`github.com/perxel/wp-ai-translate`, WordPress.org slug `perxel-ai-translate`,
published under the `phucbm` .org account, branded Perxel). It bulk-translates
WPML content through the OpenRouter API.

It started as a private client mu-plugin, was made into a generic `.org` plugin,
then (0.0.2, branch `rebuild/admin-ui-kit`) had its admin layer and persistence
rebuilt: the shared Perxel UI kit, a namespaced codebase, custom DB tables in
place of the file queue, and the manual preview/apply step removed. The
translation engine itself (OpenRouter client, field extraction, WPML sync) was
carried over intact.

## Layout

```
perxel-ai-translate.php     Main file: constants, autoloader, ui/ loader, boot
uninstall.php               Drops the option + custom tables on delete
includes/*.php              One PSR-4-ish class per concern, namespace Perxel\AITranslate\
includes/views/*.php        Dumb admin templates, fed vars by the screen classes
assets/js, assets/css       Admin-only JS/CSS (plugin-specific; layout comes from the kit)
vendor/perxel-ui/           Shared admin-UI kit - vendored, see below
languages/                  .pot template
readme.txt                  WordPress.org listing (keep in sync with README.md + version)
.wordpress-org/             Listing assets - not shipped
.github/workflows/          lint.yml (PHPCS + Plugin Check), release.yml
```

`includes/` is loaded by the `spl_autoload_register` in the main file (not
Composer). `Plugin::instance()->boot()` on `plugins_loaded` (after WPML is
confirmed) wires `Admin`, `BulkAction`, `AdminBar`.

## Architecture

- **`Admin`** owns the menu (one top-level "AI Translate"), the shared layout
  args, asset loading, and the plain form / AJAX handlers. Each screen is a
  render method + a view; the heavier per-screen logic lives in `Dashboard` /
  `Confirm` / `Progress` / `History` / `IdLookup`.
- **`Db`** - schema + `dbDelta` for three plugin-owned tables: `pxat_runs` (one
  row per run), `pxat_run_items` (one per post), `pxat_run_log` (one per log
  line). Schema version in the `pxat_db_version` option; `Db::maybe_upgrade()`
  on `init`.
- **`Runs`** - the only class that touches those tables. Items come back as flat
  arrays with the JSON `payload` (fields / before / preview) merged in.
  Concurrency: `claim_ids()` flips rows to `translating` in one atomic `UPDATE`
  (batched runs use `Translator::worker_count` parallel browser workers);
  `with_write_lock()` wraps the WordPress-write phase in a MySQL `GET_LOCK` so
  two workers never create a destination post or resolve taxonomy at once;
  `reclaim_stale()` requeues rows a dead request left `translating`.
- **`Translator`** (ex two-phase job processor) - `process_item()` translates one
  post through OpenRouter then writes every selected data type into the WPML
  destination post, in one pass. No separate apply step. Full mode's per-type
  failures are non-blocking warnings (`has_warning`); Custom mode's are hard
  errors (`has_apply_error`, item ends `error`, retryable). `process_items()` is
  the batched counterpart (one request for several posts, weighted usage split).
- **`Confirm`** - Step 1 is a GET self-submit config form; "Start" is a POST that
  creates the run + items and redirects to `Progress`.
- **`Progress`** - browser loop: `assets/js/progress.js` calls `pxat_process`
  until the run is done; the AJAX responses carry **pre-rendered cell HTML**
  (`Progress::with_snapshots`) so the JS never templates a row itself.
- **`Selection`** - the `pxat_sel_*` transient that carries a picked post set
  from an entry point (bulk action / admin bar / Dashboard picker / re-run) to
  `Confirm`.

## Conventions

- **Namespace** `Perxel\AITranslate\`. Hooks, option keys and CSS stay `pxat_` /
  `pxat-`; product name is the constant `PXAT_NAME` (no rebrand option).
- **Text domain** `perxel-ai-translate` (= the slug). JS i18n via `wp.i18n`
  (`wp_set_script_translations`), handle deps include `wp-i18n`.
- **Cost in USD** (`Format::cost()`), OpenRouter's native unit.
- **The AI model is a setting, never code.** `Settings::model()` returns
  `{id, label, input, output, context, max_output}` from the stored option;
  "Test model" (`OpenRouter::test_model()`) validates the id against
  OpenRouter's public `/models` list and fills in pricing/limits. Each run
  snapshots the model id + rates into `pxat_runs` so historical cost stays
  correct. No `PXAT_OPENROUTER_MODELS`, no `pxat_openrouter_models` filter.
- **Log breadcrumbs** (`Runs::log`) are plain English, not `__()`-wrapped.
  User-facing labels, notices and messages are translated.
- WPML is only ever touched through `Wpml` (filter/action wrappers).
- Admin screens render inside `Perxel_UI_Layout`; use the kit components
  (`rows()`, `stat_grid()`, `notice()`, `code()`, `progress_bar()`,
  `checkbox_group()`, `toggle()`) rather than hand-rolled markup. Plugin-local
  bits (status badges, the preview `<dialog>`, chips) live in `assets/css`.

## The `vendor/perxel-ui/` kit

Standalone repo `perxel/wp-plugin-ui` (currently **0.17.4**), vendored via
`bin/update-ui.sh <version>` (curl a tagged tarball into `vendor/perxel-ui/`,
Action Scheduler style - no Composer). Committed; `.gitignore` keeps it out of
the general `vendor/` ignore, `.distignore` strips only its dev-only
`showcase/`. Overwriting it can never change plugin behaviour - the loader keeps
the highest registered version across active plugins. We host its component
showcase as a hidden maintainer-only screen (`PERXEL_UI_SHOWCASE_HOSTED`).

## Before committing

```bash
php -l <changed files>
vendor/bin/phpcs            # composer run lint - must stay green
composer run build          # bin/build-zip.sh - installable zip in dist/
```

`phpcs.xml.dist` curates the base `WordPress` standard: terse-docblock house
style, and the custom-table DB sniffs are excluded for `includes/Db.php` /
`Runs.php` (table names are class constants, values always via `$wpdb->prepare`
placeholders). CI also runs the official **Plugin Check** action.

There are no automated tests and no WP/WPML in the lint environment - `phpcs` and
`php -l` verify syntax and style only. Behaviour must be smoke-tested on a real
WPML site.

## Releasing

1. Bump the version in `perxel-ai-translate.php` (header + `PXAT_VERSION`) and
   `readme.txt` (`Stable tag`); add a changelog entry. The tag must equal the
   `Version:` header or `release.yml` fails.
2. Create a GitHub Release with that tag. `release.yml`'s `zip` job attaches
   `perxel-ai-translate.zip`; the `deploy` / `assets` jobs push to WordPress.org
   SVN (need `SVN_USERNAME` / `SVN_PASSWORD`; the SVN repo only exists after the
   first manual review is approved).

Build artifacts (`dist/`) are never committed.
