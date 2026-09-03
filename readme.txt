=== Perxel AI Translate ===
Contributors: phucbm
Tags: translation, wpml, multilingual, ai, openrouter
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-translate posts, pages and custom post types across WPML languages with an AI model of your choice, through OpenRouter.

== Description ==

Perxel AI Translate adds a bulk action to your post lists that sends the selected posts to an AI model (via OpenRouter) and writes the translations back as WPML translations - without spending WPML's own AI translation credits.

It is aimed at sites that already run **WPML** and want to translate a lot of content quickly. Each post is written straight into WordPress as a WPML translation; you review the result in the normal editor.

**What it translates**

* Post title and slug
* Excerpt and content (HTML and page-builder shortcodes such as `[vc_row]` are preserved; only the readable text is translated)
* Advanced Custom Fields text, textarea and WYSIWYG fields (including fields nested in Groups, Repeaters and Flexible Content) - other ACF field types are copied as-is
* Rank Math SEO fields (title, description, focus keyword, social meta) when Rank Math is active
* Taxonomy terms - remapped to their WPML translations
* Featured image

**How it works**

1. Open any post list, tick some rows and choose **Perxel AI Translate…** from the bulk actions (or use *Translate this page* in the toolbar while editing one post). The posts land in your **translation cart** under **AI Translate**.
2. Keep adding posts to the cart from anywhere - it stays until you start a run or clear it.
3. On the cart screen, remove any rows you changed your mind about, then pick the destination language and which data to process. You get a cost and token estimate before anything runs.
4. Press **Start**. Each post is translated and written straight into WordPress as a WPML translation; review the result in the normal editor. Close the tab any time - the run resumes where it left off.
5. Every run is kept under **AI Translate → History** until you delete it.

Turn on **Faster batched requests** on the cart screen to send several short posts per model request.

**Requirements**

* [WPML](https://wpml.org/) (Multilingual CMS or higher) with at least two active languages. The plugin stays inactive if WPML is not present.
* An [OpenRouter](https://openrouter.ai/) account and API key. You pay OpenRouter directly for model usage.

== External services ==

This plugin connects to the **OpenRouter API** (https://openrouter.ai) to perform translations. It is required for the plugin's core function and no translation happens without it.

**What is sent, and when**

* When you run a translation: the text content of the posts you selected (title, excerpt, content, the ACF/SEO fields listed above), the source and destination language codes, and the AI model you chose are sent to OpenRouter, which forwards the request to the model provider you selected (for example Google, OpenAI or Anthropic, depending on the model).
* When you click *Test* on the settings screen: your API key is sent to OpenRouter's key-info endpoint (`/api/v1/auth/key`) to check that it is valid. No post content is sent.
* When you click *Test model* on the settings screen: the plugin fetches OpenRouter's public model list (`/api/v1/models`, no authentication) to confirm the model id exists and read its pricing. No key or post content is sent.

Data is sent only when you explicitly start a translation or test the key. Your OpenRouter API key is stored in your site's database and sent as an authorization header with each request.

* OpenRouter Terms of Service: https://openrouter.ai/terms
* OpenRouter Privacy Policy: https://openrouter.ai/privacy
* Model providers reachable through OpenRouter have their own terms; see https://openrouter.ai/docs for the current list and their data-handling policies.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/perxel-ai-translate`, or install it from the Plugins screen.
2. Activate it. WPML must already be active with at least two languages.
3. Go to **AI Translate → Settings** and enter your OpenRouter API key.
4. Open a post list, tick some rows, choose **Perxel AI Translate…** from the bulk actions, then start a run from the translation cart.

== Frequently Asked Questions ==

= Does this use WPML's AI translation credits? =

No. Translations go through your own OpenRouter account. WPML is only used to read language settings and to link the translated posts.

= Does it work without WPML? =

No. The plugin depends on WPML for language configuration and translation linking, and stays inactive without it.

= Which AI models can I use? =

Any model id listed on openrouter.ai/models. Set it on **AI Translate → Settings**, then press *Test model* to confirm it and pull its pricing. The default is an economical Gemini Flash model until you change it.

= Where does the plugin store its runs? =

In its own database tables (`wp_pxat_runs`, `wp_pxat_run_items`, `wp_pxat_run_log`). Each post is translated and written into WordPress as the run goes; you review the result in the normal editor.

= What happens to my data if I delete the plugin? =

Deleting the plugin removes its settings and those tables. Translations already written into your posts are left untouched.

== Screenshots ==

1. Dashboard - setup state and all-time totals.
2. Translation cart - collected posts, language, data selection and cost estimate.
3. Run screen - live translation progress.
4. History of every run.

== Changelog ==

= 0.0.6 =
* The translation run now begins on its own when the run screen opens - pressing **Start** on the cart is the only step.
* **Translation cart** appears in the AI Translate menu only while it holds posts; its address is now `admin.php?page=pxat-cart`.

= 0.0.5 =
* Run and Dashboard figures now read as a grouped list (label left, value right) instead of a tile grid; the History screen runs full width.
* Shared UI kit updated to 0.18.0 (dropped the stat tile grid).

= 0.0.4 =
* Posts you pick now collect into a persistent **translation cart** instead of going straight to a one-off confirm screen. Add posts from the bulk action or a post's toolbar over as many sittings as you like, then start one run.
* The cart screen lists everything collected with a per-row **Remove** and a **Clear cart**; the "AI Translate" menu shows how many posts are waiting.
* Removed the Dashboard post picker - use the post list's own filters plus the bulk action, or the single-post *Translate this page* item.

= 0.0.3 =
* The AI model is now a setting, not code. Enter any OpenRouter model id on the Settings screen and press "Test model" to verify it and fetch its live pricing. No models are hard-coded any more.
* Settings screen: new Environment section (WPML, languages, API key, model, PHP), the system prompt shown as a read-only field, consistent field styling from the shared UI kit.
* Shared UI kit updated to 0.17.4 (consistent text-field / textarea styling, a Test button on the group title, status dots per row).

= 0.0.2 =
* Rebuilt the admin experience on the shared Perxel UI kit: a consistent left-sidebar layout across every screen and a new Dashboard landing page.
* Removed the manual preview/apply step - every run translates and writes straight into WordPress; review the result in the editor.
* Replaced the three run modes with one optional "Faster batched requests" toggle.
* Moved the run queue from JSON files to custom database tables (faster history, cleaner concurrency, no uploads-dir dependency).
* Menu moved from Settings to its own top-level "AI Translate" menu.

= 0.0.1 =
* First public release.
