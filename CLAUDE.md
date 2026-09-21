# CLAUDE.md

Guidance for working on this repository. This is the **only** agent/maintainer
document (see "Documentation rules" below).

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

It predates, and is kept in line with,
[`perxel/wp-plugin-starter`](https://github.com/perxel/wp-plugin-starter), which
is the source of truth for shared process - CI, release/deploy, WordPress.org
compliance rules, `.distignore`, build scripts, and the "Releasing" and
"Compliance" sections below. If you improve one of those here, make the same
change in the starter (or tell the maintainer). Plugin-specific code and listing
art stay here.

## Documentation rules

Every Perxel plugin follows these; they are owned by the starter.

- **`README.md` is public-facing only**: what the plugin does, screenshots,
  install, requirements, what data it stores / external services, license. No
  architecture, folder layout, build/lint/release steps, or "how to extend" -
  none of that belongs on the public page.
- **`CLAUDE.md` is the one and only file for developers and agents**:
  architecture, conventions, compliance, releasing. There is **no `AGENTS.md`**
  (and no second "playbook" file) - do not recreate it or duplicate content
  across the two. Claude Code reads `CLAUDE.md`; other agents can be pointed at it.
- `readme.txt` is the WordPress.org listing, `CHANGELOG.md` (optional) the
  changelog. Neither carries developer guidance.
- Master/source art for `.wordpress-org/` lives in `.claude/assets-src/`.
- `.env.local` holds credentials: never commit it (it is in `.gitignore`).
- `bin/*.sh` derive the slug from the main plugin file, so they are byte-identical
  across plugins - never hard-code a slug in them. Per-plugin Plugin Check
  suppressions go in `lint.yml` -> `ignore-codes`.
- `languages/` is optional; `.org` auto-loads translations.

## Layout

```
perxel-ai-translate.php     Main file: constants, autoloader, UI-kit loader, boot
uninstall.php               Drops the option + custom tables on delete
includes/*.php              One PSR-4-ish class per concern, namespace Perxel_Ai_Translate\
includes/views/*.php        Dumb admin templates, fed vars by the screen classes
assets/js, assets/css       Admin-only JS/CSS (plugin-specific; layout comes from the kit)
vendor/perxel-ui/           Shared admin-UI kit - vendored, see below
languages/                  .pot template
readme.txt                  WordPress.org listing (keep in sync with README.md + version)
.wordpress-org/             Listing assets - not shipped
.github/workflows/          lint.yml (PHPCS + Plugin Check), release.yml
bin/                        build-zip.sh, update-ui.sh - identical in every plugin
.claude/assets-src/         Master/source art for the listing assets - committed, not shipped
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
- **`Confirm`** - the "Translation" screen (menu slug `pxat-confirm`, off the
  menu, reached only by redirect). The selection is *not* stored: entry points
  (`BulkAction`, `AdminBar` "Translate this page", `Progress` re-run) redirect
  here with `?ids=1,2,3&post_type=page` in the URL. `Confirm::read_selection()`
  parses that from `$_GET` / `$_POST`; a non-translatable `post_type` voids the
  selection (→ `views/confirm-empty.php`). A GET self-submit config form and the
  "Start" POST both carry `ids` + `post_type` through as hidden fields. "Start"
  creates the run + items and redirects to `Progress`. There is no picker/filter
  UI - posts are chosen with the post list's own filters + bulk action, or the
  single-post bar item.
- **`Progress`** - browser loop: `assets/js/progress.js` calls `pxat_process`
  until the run is done; the AJAX responses carry **pre-rendered cell HTML**
  (`Progress::with_snapshots`) so the JS never templates a row itself.

## Conventions

- **Namespace** `Perxel_Ai_Translate\`. Hooks, option keys and CSS stay `pxat_` /
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
  (`rows()`, `notice()`, `code()`, `progress_bar()`, `checkbox_group()`,
  `toggle()`) rather than hand-rolled markup. A bare `<input type="checkbox">`
  renders as a square box; the iOS switch is `toggle()` / the `.pxui-toggle`
  class (kit 0.19.0 flipped this). Figures (run counts, all-time
  totals) are a `rows()` group - label left, number as `content` right, `sub`
  for the qualifier, `tone` for good/warn/bad - not a tile grid (the kit's
  `stat_grid()` was dropped in 0.18.0). Plugin-local bits (status badges, the
  preview `<dialog>`, chips) live in `assets/css`.

## The `vendor/perxel-ui/` kit

Standalone repo `perxel/wp-plugin-ui` (currently **0.19.0**), vendored via
`bin/update-ui.sh <version>` (curl a tagged tarball into `vendor/perxel-ui/`,
Action Scheduler style - no Composer). Committed; `.gitignore` keeps it out of
the general `vendor/` ignore, `.distignore` strips only its dev-only
`showcase/`. Overwriting it can never change plugin behaviour - the loader keeps
the highest registered version across active plugins. We host its component
showcase as a hidden maintainer-only screen (`PERXEL_UI_SHOWCASE_HOSTED`).

## Extending

```php
// Cap how many parallel browser workers a batched run uses (default 2).
add_filter( 'pxat_batch_worker_count', fn () => 3 );
```

The AI model, its pricing and its limits are stored settings - set them on
**Tools -> AI Translate -> Settings**, not in code.

Regenerate the translation template (optional; `languages/` is not required):

```bash
wp i18n make-pot . languages/perxel-ai-translate.pot
```

## Before committing

```bash
php -l <changed files>
vendor/bin/phpcs            # composer run lint (also runs bin/check-suppressions.sh) - must stay green
composer run build          # bin/build-zip.sh - installable zip in dist/
```

`phpcs.xml.dist` curates the base `WordPress` standard: terse-docblock house
style. Custom-table queries in `Db` / `Runs` / `Admin` bind the table name with
the `%i` placeholder (hence `Requires at least: 6.2`), so the prepared-SQL sniffs
pass unaided; only `WordPress.DB.DirectDatabaseQuery` stays excluded there (a live
queue reads uncached; `Db` issues DDL). The one dynamic `IN ()` list in
`Runs::claim_ids()` has a scoped `phpcs:disable`.

CI also runs the official **Plugin Check** action. It ignores `phpcs.xml.dist`,
so its `ignore-codes` (in `lint.yml`) repeats
the two documented `PrefixAllGlobals` false positives: the `wpml_*` hook names
(WPML's API) and view-template variables (plus the deliberate `suppress_filters`).

There are no automated tests and no WP/WPML in the lint environment - `phpcs` and
`php -l` verify syntax and style only. Behaviour must be smoke-tested on a real
WPML site.

## WordPress.org / Plugin Check compliance

Rules that are not obvious and cost real time when re-derived per plugin:

| Rule | Why |
|---|---|
| Namespace root = slug in `Ucfirst_Snake` (`Perxel_Ai_Translate`). | `PrefixAllGlobals` accepts it as the prefix; a `Vendor\Package` namespace is flagged (`NonPrefixedNamespaceFound`) and Plugin Check ignores the `phpcs.xml.dist` prefix list |
| Custom-table names via `%i`, never string-concatenated | `WordPress.DB.PreparedSQL.NotPrepared` is **error-level** and blocks .org (see "Custom tables") |
| No `load_plugin_textdomain()` | .org auto-loads translations (slug == text domain); calling it on `plugins_loaded` is "too early" on WP 6.7+ |
| Prefix any variable you **assign** in a view (`$pxat_url`); vars passed in via `extract()` are fine | `NonPrefixedVariableFound` fires on template-scope assignments |
| `set_time_limit()` etc.: `function_exists()` guard + inline `// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- <reason>` | discouraged-function warning |
| Calling another plugin's hooks (WPML `wpml_*`, WooCommerce): scope a `phpcs.xml.dist` exclude to the wrapper file **and** add the code to `lint.yml` -> `ignore-codes` | `NonPrefixedHooknameFound`; the two tools don't share config |
| Never `phpcs:disable EscapeOutput` for a whole view. Kit markup goes through `Admin::kit()` (the one delegated `echo`); any other pre-escaped echo gets a per-line `phpcs:ignore` with the reason | Reviewers flag file-wide disables as escaping/nonce failures (hit perxel-image-optimizer and this plugin); `bin/check-suppressions.sh` (run by `composer run lint`, so CI) fails on the blanket form |
| `'suppress_filters' => true` in a query: same dual-suppression, code `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters` | deliberate but flagged |

The split that bites: **Plugin Check runs its own ruleset, not `phpcs.xml.dist`.**
Any suppression for a documented false positive goes in *both* places -
`phpcs.xml.dist` (for `composer run lint`) and `lint.yml` -> `ignore-codes`.

## Releasing

1. Bump the version in `perxel-ai-translate.php` (header + `PXAT_VERSION`) and
   `readme.txt` (`Stable tag`); add a changelog entry to `readme.txt`. Merge to `main` first. Tag, plugin `Version:` and `Stable tag`
   must all be equal or the deploy fails before touching SVN.
2. Create the tag on `main` and publish a GitHub Release. `release.yml`'s `zip`
   job attaches `perxel-ai-translate.zip`; the `deploy` job commits trunk +
   `tags/<version>` + `.wordpress-org/` (-> SVN `assets/`) with the SHA-pinned
   10up action. It only runs when the repo variable `DEPLOY_TO_WPORG` is `true`.
3. Verify `https://wordpress.org/plugins/<slug>/` and
   `https://api.wordpress.org/plugins/info/1.0/<slug>.json` show the new version.
   Assets can 404 on `ps.w.org` for a while after the first commit (CDN lag).

### First release of a new plugin (the only manual bit is the review)

1. Upload `dist/<slug>.zip` at <https://wordpress.org/plugins/developers/add/>.
   No SVN repo exists until the review team approves it.
2. Secrets `SVN_USERNAME` / `SVN_PASSWORD`: set once as **org** secrets and grant
   this repo access (org -> Settings -> Secrets -> Repository access). Use an
   SVN-specific password if the wordpress.org profile offers one. Never paste it
   in chat or commit it.
3. Once approved: set the repo variable `DEPLOY_TO_WPORG=true`, run **Actions ->
   Release -> Run workflow** with the tag and `dry_run` on (default) to check the
   staging without committing, then publish the Release. The very first version
   deploys the same way as every later one - no manual SVN commit.
4. If automation ever breaks, plain `svn` works: check out
   `https://plugins.svn.wordpress.org/<slug>`, copy the `.distignore`-filtered
   build into `trunk/`, `.wordpress-org/*` into `assets/`, `svn cp trunk
   tags/<version>`, `svn ci`.

Notes: a large first commit (hundreds of vendored files) sits on "Committing
transaction..." for minutes - normal. The action strips the `v` from a `vX.Y.Z`
tag itself; on a manual run it can't, hence the explicit `VERSION`. Do not bump
versions, tag or publish releases without the maintainer asking.

Build artifacts (`dist/`) are never committed.
