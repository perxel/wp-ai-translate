# WordPress.org review, round 3 (2026-09-23)

Manual review of 0.0.24. Unlike round 2, lines were cited:

1. `includes/Runs.php:578` - "The MySQL lock acquisition result is ignored, so
   writes may run concurrently after lock timeout and cause race conditions."
   (flagged by the reviewers' AI pass).
2. Escaping - `includes/views/progress.php:175`
   `echo $item['html']['action']; // phpcs:ignore ... escaped in Progress::render_*_cell().`
   and `includes/Admin.php:37`
   `echo $html; // phpcs:ignore ... trusted Perxel_UI markup; see docblock.`

## Findings

- **Lock**: real. `with_write_lock()` ran the callback whatever `GET_LOCK`
  returned, so a timeout (0) or error (NULL) still wrote.
- **Escaping**: no XSS - the HTML was escaped when built - but round 2's fix
  (per-line `phpcs:ignore` + "escaped earlier" reason) is itself rejected. The
  rule is escape *at the echo*, never suppress `EscapeOutput`, even per line.

## Fix (0.0.25, `aa6713a`, PR #4)

- `Runs::with_write_lock( $callback, $on_busy )`: only `'1'` runs the callback;
  otherwise `$on_busy()` (item ends `error`, retryable at no model cost since
  the preview is stored). Release in `finally`.
- `Admin::kit()` = `echo wp_kses( $html, \Perxel_UI::allowed_html() )` (kit
  0.23.0 added the allowlist). Progress cells and Confirm's plan column echo
  through `wp_kses( $html, Admin::inline_html() )`.
- `bin/check-suppressions.sh` now fails on *any* `EscapeOutput` suppression.
- Ported to every Perxel plugin (toolkit 0.0.5, starter v0.0.2,
  image-optimizer merged, unreleased).

## Also in the uploaded 0.0.25 zip (not from the review)

- `871a55b`: guard `define( 'PERXEL_UI_SHOWCASE_HOSTED' )` with `defined()`;
  two Perxel plugins on one site raised "Constant already defined". Same fix
  pushed to toolkit, image-optimizer, khatra-showcase and the starter.
- Zip rebuilt from `main` as 0.0.25, so it no longer matches the `0.0.25` tag.
  First .org deploy after approval should be 0.0.26.

## Checks before upload

- `composer run lint` green (suppression check + phpcs).
- Every remaining echo in `includes/` escaped at output; no `EscapeOutput`
  suppressions in the plugin or vendored kit.
- Still to do by hand: clean WP + WPML, `WP_DEBUG` true, one full run.

## Reply email (short, no change list)

```txt
Hello,

Thanks for the review. Both issues are fixed in the uploaded version:

- Runs::with_write_lock() now checks the GET_LOCK result; the guarded write runs only when it returns 1, otherwise nothing is written and the item is marked retryable.
- All HTML output is escaped at the point of output with wp_kses() and an explicit allowlist. No EscapeOutput suppressions remain. Checked with Plugin Check and PHPCS/WPCS.

Thanks,
Phuc
```
