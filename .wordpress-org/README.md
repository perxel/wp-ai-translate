# WordPress.org listing assets

Files in this folder are pushed to the plugin's SVN `assets/` directory by the
GitHub Actions workflow. They are **not** shipped to users — they only appear on
the wordpress.org plugin page.

Expected files (add before the first release):

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` / `icon-256x256.png` (or `icon.svg`) | 128², 256² | Plugin icon |
| `banner-772x250.png` | 772×250 | Header banner |
| `banner-1544x500.png` | 1544×500 | Retina banner |
| `screenshot-1.png` … `screenshot-4.png` | any | Match the `== Screenshots ==` list in `readme.txt` |

See https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
