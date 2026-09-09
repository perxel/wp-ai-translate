# One-click "Translate this page" from the admin bar

Status: **deferred** (2026-09-09). Nice-to-have. Not worth building for a single
user right now; parked here in case it comes back.

## The idea

The admin-bar "Translate this page" node currently links to the Confirm screen.
Wish: click it and have the current post translated into every other active
language, with no Confirm-page detour, so the editor can carry on with other
work. Ideal flow:

1. Click "Translate this page".
2. A confirm dialog: "Translate **{title}** from **{source}** to
   **{lang, lang, ...}** in the background. You do not need to do anything else."
3. On confirm, the run(s) start; the page reloads where the user was.
4. Results still land in History exactly as today.

## The constraint that drives everything

There is **no server-side worker**. Translation only happens inside
`wp_ajax_pxat_process` (`Progress::ajax_process()`), and that handler is only
ever called by `assets/js/progress.js` running on the Progress screen. No
WP-Cron, no Action Scheduler, no loopback request. Each AJAX call does one
OpenRouter call + one WPML write (`set_time_limit(180)`), and the browser loop
keeps firing until the run is done.

So "in the background" today = "a browser tab parked on the Progress screen".
Close it and the run stalls - recoverably: `Runs::reclaim_stale()` + the Resume
button exist for exactly that.

Also: **a run targets exactly one destination language** (`pxat_runs.dest_lang`,
snapshotted for cost). "Translate to all languages" = N runs, one per target.

## Feature broken into parts

| Part | Feasibility | Notes |
|---|---|---|
| 1. Skip Confirm, create the run(s) from one click | **Easy** | `Confirm::create_run()` is already `public`; its docblock says "Shared by the Confirm form and the one-click entry points." A new `admin-post.php` handler + nonce on the bar node calls it with a default config (`data_mode => 'full'`, `batched => Settings::batched()`). |
| 2. Confirm dialog with title + langs | **Easy** | Native `confirm()`, or the kit's `data-pxui-confirm` attribute already used for "Cancel run" (`Progress.php`). Language list is a one-liner from `Wpml::get_active_languages()`. |
| 3. Translate into *all* other languages | **Moderate** | Cleanest: loop `create_run()` once per target language -> N rows in History ("Home -> fr", "Home -> de"). Reuses everything. A multi-lang run would be a schema + `Translator` + Progress-UI change - not worth it. |
| 4. Actually run it in the background | **The real work** | See options below. |

## Options for part 4 (background execution)

**A. Invisible driver on admin pages (smallest real step).**
After creating the runs, redirect back to where the user was and enqueue a tiny
script that runs the same pump loop against `pxat_process` for any unfinished
run. An admin-bar badge polls `pxat_status` to show "Translating... 3/8" and
surface errors.
Cost: medium. Reuses the whole existing loop / resume / reclaim stack.
Limits: only progresses while some wp-admin tab is open; closing it or moving to
the front end silently pauses. Less transparent than today's visible Progress
screen - the badge has to compensate.

**B. WP-Cron loopback (the "proper" background).**
On run create, schedule a one-off event; the handler translates a chunk and
re-schedules itself until done. Survives tab close.
Cost: medium-high.
Caveats: WP-Cron only fires on site traffic (fine for live sites, document it);
long OpenRouter calls inside cron can hit host PHP limits on cheap shared
hosting; loses the parallel batch workers (`Translator::worker_count`), so runs
get slower. DB safety already handled - `claim_ids()` + the `GET_LOCK` write lock
guard against overlap.

**C. Bundle Action Scheduler.**
Most reliable, but a dependency the project has deliberately avoided (no Composer,
custom tables instead of the old file queue). Overkill for a nice-to-have.

## Recommended phasing (if resumed)

1. **Phase 1:** admin bar -> confirm dialog (part 2) -> `create_run()` per target
   language (parts 1 + 3) -> redirect to the existing Progress screen with
   `?pxat_autostart=1`. Delivers "one click, no Confirm page, still in History".
   Gap vs. the vision: user watches Progress instead of their editor. Small,
   safe, no new infrastructure.
2. **Phase 2 (if wanted):** Option A (invisible driver + admin-bar status badge)
   so they can leave the Progress screen. Document the "keep a wp-admin tab open"
   limitation.
3. **Phase 3 (only if sites must close the tab entirely):** Option B.

## Risks to weigh

- **Cost guardrail removed.** Today you must pass a cost estimate before
  spending. One-click-from-toolbar bypasses that, and "all languages" multiplies
  spend silently. Mitigate: put the estimate in the confirm dialog
  (`Confirm::build_plan()` is callable but a bit heavy) or an admin notice right
  after.
- **Where do errors go?** Bad API key / no credit currently surface on
  Confirm/Progress. A background flow needs a home for them - admin notice or the
  toolbar badge.
- Nonce + `manage_options` on the new action (both already the norm here).

## Touch points

- `includes/AdminBar.php` - node becomes an `admin-post.php` action link (or a
  small dialog + form).
- `includes/Admin.php` - register the new `admin_post_*` handler.
- `includes/Confirm.php` - `create_run()` already does the heavy lifting; maybe
  factor the per-language loop into a helper.
- `assets/js/` - dialog wiring for Phase 1; the invisible driver + badge for
  Phase 2.
