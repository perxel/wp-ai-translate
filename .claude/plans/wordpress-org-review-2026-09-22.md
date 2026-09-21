# WordPress.org review, round 2 (2026-09-22)

Auto-review email flagged: (1) nonces + permissions before processing requests,
(2) escaping of outputs, (3) Plugin URI returned 404. All three are generic
reminders with no lines cited.

## Findings

- **Nonces/permissions**: no real gap. Every `admin_post_*` handler, every AJAX
  endpoint and History's bulk delete already check a nonce + `manage_options`.
  Remaining `$_GET` reads are display-only navigation params.
- **Escaping**: no XSS. Every dynamic value was escaped inline. The liability was
  file-wide `phpcs:disable WordPress.Security.EscapeOutput` in all six views (and
  `NonceVerification` disable blocks, three of which had no matching `enable`, so
  they leaked to the end of the file).
- **Plugin URI**: the GitHub repo was private; made public.

## Repeat of perxel-image-optimizer (2026-09-06)

Same escaping flag, same cause; fixed there in `024e077` (`$render()` closure,
branch `webp/plugin-review-fixes`). Nonce flag is new this round.

## Root cause

`perxel/wp-plugin-starter` taught and shipped the blanket disable
(`views/settings.php`, CLAUDE.md "Escape at output"). Every scaffolded plugin
inherited it.

## Fix (0.0.24)

- `Admin::kit()`: the single delegated `echo` for Perxel_UI markup; views call it.
- 8 already-escaped echoes (progress cells, confirm) got per-line ignores + reason.
- All `NonceVerification.Recommended` blocks became per-line ignores.
- `bin/check-suppressions.sh` (byte-identical across plugins) runs inside
  `composer run lint`, so CI fails on any blanket `WordPress.Security` disable.
- Starter fixed in perxel/wp-plugin-starter#3 (same code + guard + docs).

## Reply email (short, no change list)

```txt
Hello,

An updated version is uploaded. Nonce and capability checks were reviewed on every action, and output escaping was tightened across the admin views. The Plugin URI repository is now public.

Thanks,
Phuc
```
