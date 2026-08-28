=== Perxel AI Translate ===
Contributors: phucbm
Tags: translation, wpml, multilingual, ai, openrouter
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-translate posts, pages and custom post types across WPML languages with an AI model of your choice, through OpenRouter.

== Description ==

Perxel AI Translate adds a bulk action to your post lists that sends the selected posts to an AI model (via OpenRouter) and writes the translations back as WPML translations — without spending WPML's own AI translation credits.

It is aimed at sites that already run **WPML** and want to translate a lot of content quickly, with a review step before anything is published.

**What it translates**

* Post title and slug
* Excerpt and content (HTML and page-builder shortcodes such as `[vc_row]` are preserved; only the readable text is translated)
* Advanced Custom Fields text, textarea and WYSIWYG fields (including fields nested in Groups, Repeaters and Flexible Content) — other ACF field types are copied as-is
* Rank Math SEO fields (title, description, focus keyword, social meta) when Rank Math is active
* Taxonomy terms — remapped to their WPML translations
* Featured image

**How it works**

1. Select posts in the list table and choose **Perxel AI Translate…** from the bulk actions (or use *Translate this page* in the toolbar while editing one post).
2. On the confirm screen, pick the destination language, which data to process, and a run mode. You get a cost and token estimate before anything runs.
3. On the progress screen the plugin translates each post. In **Manual** mode you preview each result and choose what to apply; in **Auto** and **Auto (batched)** modes translations are written straight into WordPress.
4. A permanent history of every run is kept under *Settings → … → Translation history*.

**Run modes**

* **Manual** — translate first, then review and apply each post.
* **Auto** — translate and apply immediately, one post per request.
* **Auto (batched)** — group several short posts into each request for faster throughput.

**Requirements**

* [WPML](https://wpml.org/) (Multilingual CMS or higher) with at least two active languages. The plugin stays inactive if WPML is not present.
* An [OpenRouter](https://openrouter.ai/) account and API key. You pay OpenRouter directly for model usage.

== External services ==

This plugin connects to the **OpenRouter API** (https://openrouter.ai) to perform translations. It is required for the plugin's core function and no translation happens without it.

**What is sent, and when**

* When you run a translation: the text content of the posts you selected (title, excerpt, content, the ACF/SEO fields listed above), the source and destination language codes, and the AI model you chose are sent to OpenRouter, which forwards the request to the model provider you selected (for example Google, OpenAI or Anthropic, depending on the model).
* When you click *Test* on the settings screen: your API key is sent to OpenRouter's key-info endpoint to check that it is valid. No post content is sent.

Data is sent only when you explicitly start a translation or test the key. Your OpenRouter API key is stored in your site's database and sent as an authorization header with each request.

* OpenRouter Terms of Service: https://openrouter.ai/terms
* OpenRouter Privacy Policy: https://openrouter.ai/privacy
* Model providers reachable through OpenRouter have their own terms; see https://openrouter.ai/docs for the current list and their data-handling policies.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/perxel-ai-translate`, or install it from the Plugins screen.
2. Activate it. WPML must already be active with at least two languages.
3. Go to **Settings → Perxel AI Translate** and enter your OpenRouter API key.
4. Open any post list, select some posts, and choose **Perxel AI Translate…** from the bulk actions.

== Frequently Asked Questions ==

= Does this use WPML's AI translation credits? =

No. Translations go through your own OpenRouter account. WPML is only used to read language settings and to link the translated posts.

= Does it work without WPML? =

No. The plugin depends on WPML for language configuration and translation linking, and stays inactive without it.

= Which AI models can I use? =

The plugin ships with one economical default model. Developers can change or extend the list with the `pxat_openrouter_models` filter using any model id available on OpenRouter.

= Where are translations stored before I apply them? =

In a JSON file per batch under `wp-content/uploads/perxel-ai-translate/logs/`. Nothing is written to your posts until you apply a translation (or you chose an Auto run mode).

= What happens to my data if I delete the plugin? =

Deleting the plugin removes its settings and the batch log directory. Translations already written into your posts are left untouched.

== Screenshots ==

1. Settings screen — API key and system prompt.
2. Confirm screen — language, data selection, run mode and cost estimate.
3. Progress screen — live translation status with preview and apply.
4. Translation history.

== Changelog ==

= 1.0.0 =
* First public release.
