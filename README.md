# Perxel AI Translate

Bulk-translate posts, pages and custom post types across **WPML** languages with
an AI model of your choice, through [OpenRouter](https://openrouter.ai/) - without
spending WPML's own AI translation credits.

> Public WordPress plugin by [Perxel](https://perxel.com/). Published to the
> WordPress.org plugin directory as `perxel-ai-translate`.

## What it does

- A top-level **AI Translate** admin menu: a Dashboard (setup, totals), Run,
  History, ID lookup and Settings.
- Posts are picked with a **Perxel AI Translate…** bulk action on every
  WPML-translatable post type, or the *Translate this page* toolbar item while
  editing one post. Either lands on a confirm screen for exactly those posts -
  there is no persistent, accumulating list.
- The confirm screen takes a destination language, data selection and a
  cost / word-count estimate before anything runs. Costs show in USD, or in VND
  on sites whose WPML default language is Vietnamese.
- A live, resumable Run screen. Each post is translated and written straight
  into WordPress as a WPML translation - review it in the normal editor.
- The AI model is a setting: enter any OpenRouter model id, press *Test model*
  to verify it and fetch its pricing.
- Translates: title & slug, excerpt & content (HTML and page-builder shortcodes
  preserved), ACF text/textarea/WYSIWYG fields (including nested), Rank Math SEO
  fields, taxonomy terms (remapped to their WPML translations), and the featured
  image.

## Requirements

- WordPress 5.8+, PHP 7.4+
- WPML with at least two active languages
- An OpenRouter API key (you pay OpenRouter directly for usage)

## Development

```bash
composer install
composer run lint      # PHP_CodeSniffer (WordPress standard)
composer run lint:fix  # phpcbf
```

Build an installable / WordPress.org submission zip (only committed files,
minus everything in `.distignore`):

```bash
composer run build          # or: bin/build-zip.sh
bin/build-zip.sh --dirty    # include uncommitted changes
# → dist/perxel-ai-translate.zip
```

Regenerate the translation template:

```bash
wp i18n make-pot . languages/perxel-ai-translate.pot
```

### Extending

```php
// Cap how many parallel browser workers a batched run uses (default 2).
add_filter( 'pxat_batch_worker_count', fn () => 3 );
```

The AI model, its pricing and its limits are stored settings - set them on
**AI Translate → Settings**, not in code.

## Releasing

1. Bump the version in `perxel-ai-translate.php` (header **and** `PXAT_VERSION`),
   `readme.txt` (`Stable tag`), and add a changelog entry. Commit to `main`.
2. Create a **GitHub Release** with the tag = the new version (e.g. `0.0.2`).

`release.yml` then runs automatically on the published Release and does two
things:

| Job | What it does |
| --- | --- |
| `zip` | Builds `perxel-ai-translate.zip` with `bin/build-zip.sh` and **attaches it to the release**. Works immediately. |
| `deploy` / `assets` | Pushes the version to the WordPress.org SVN repo and updates the `.org` readme and listing assets. Only works **after** the plugin's first submission has been approved, and needs the repo secrets `SVN_USERNAME` / `SVN_PASSWORD`. |

The zip is a build artifact - it is **not** committed to the repo (`dist/` is
git-ignored). The stable download URL for the latest build is
`https://github.com/perxel/wp-ai-translate/releases/latest/download/perxel-ai-translate.zip`.

Listing assets (icon, banner, screenshots) live in `.wordpress-org/` and are
pushed to SVN on the same `release.yml` run.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
