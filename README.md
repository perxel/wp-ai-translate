# Perxel AI Translate

Bulk-translate posts, pages and custom post types across **WPML** languages with
an AI model of your choice, through [OpenRouter](https://openrouter.ai/) — without
spending WPML's own AI translation credits.

> Public WordPress plugin by [Perxel](https://perxel.com/). Published to the
> WordPress.org plugin directory as `perxel-ai-translate`.

## What it does

- Adds a **Perxel AI Translate…** bulk action to every WPML-translatable post type,
  plus a *Translate this page* toolbar item on the post editor.
- Confirm screen with destination language, data selection, run mode, and a
  cost / token estimate before anything runs.
- Progress screen with a live, resumable translation loop, a preview/apply review
  step, per-post retry, and a permanent run history.
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
// Add or replace the AI models offered to users.
add_filter( 'pxat_openrouter_models', function ( $models ) {
    $models[] = array(
        'id'                => 'anthropic/claude-3.5-haiku',
        'label'             => 'Claude 3.5 Haiku',
        'input'             => 0.80,
        'output'            => 4.00,
        'max_output_tokens' => 8192,
    );
    return $models;
} );
```

You can also define `PXAT_OPENROUTER_MODELS` in `wp-config.php` or an mu-plugin
to replace the built-in list entirely.

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

The zip is a build artifact — it is **not** committed to the repo (`dist/` is
git-ignored). The stable download URL for the latest build is
`https://github.com/phucbm/perxel-ai-translate/releases/latest/download/perxel-ai-translate.zip`.

Listing assets (icon, banner, screenshots) live in `.wordpress-org/` and are
pushed to SVN on the same `release.yml` run.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
