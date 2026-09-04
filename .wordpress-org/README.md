# WordPress.org listing assets

Files in this folder are pushed to the plugin's SVN `assets/` directory by the
GitHub Actions workflow. They are **not** shipped to users - they only appear on
the wordpress.org plugin page.

Only recognised asset filenames belong in this folder - everything here is
synced to the plugin's SVN `assets/` directory. Keep source masters elsewhere.

| File | Size | Purpose | Status |
| --- | --- | --- | --- |
| `icon-128x128.png` / `icon-256x256.png` | 128², 256² | Plugin icon | done |
| `screenshot-1.png` … `screenshot-4.png` | any | Match the `== Screenshots ==` list in `readme.txt` | done |
| `banner-772x250.png` | 772×250 | Header banner | done |
| `banner-1544x500.png` | 1544×500 | Retina banner | done |

See https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
