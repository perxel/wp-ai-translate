=== Perxel AI Translate ===
Contributors: phucbm
Tags: translation, wpml, multilingual, ai, openrouter
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.22
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

1. Open any post list, tick some rows and choose **Perxel AI Translate…** from the bulk actions (or use *Translate this page* in the toolbar while editing one post).
2. You land on a confirm screen for exactly those posts: pick the destination language and which data to process. You get a cost and word-count estimate before anything runs.
3. Press **Translate and apply**. Each post is translated and written straight into WordPress as a WPML translation; review the result in the normal editor. Close the tab any time - reopen the run and press **Resume** to carry on where it left off.
4. Every run is kept under **Tools → AI Translate → History** until you delete it.

**Faster batched requests** (on by default in Settings) sends several short posts per model request; turn it off to translate one post per request.

**Requirements**

* [WPML](https://wpml.org/) (Multilingual CMS or higher) with at least two active languages. The plugin stays inactive if WPML is not present.
* An [OpenRouter](https://openrouter.ai/) account and API key. You pay OpenRouter directly for model usage.

**Tested with**

* WordPress 7.1
* WPML 4.9.7
* Secure Custom Fields 6.9.5 (and Advanced Custom Fields)
* Rank Math SEO 1.0.277.2 and Rank Math SEO PRO 3.0.82
* WPBakery Page Builder 9.0.1

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
3. Go to **Tools → AI Translate → Settings** and enter your OpenRouter API key.
4. Open a post list, tick some rows, choose **Perxel AI Translate…** from the bulk actions, then start the run from the confirm screen.

== Frequently Asked Questions ==

= Does this use WPML's AI translation credits? =

No. Translations go through your own OpenRouter account. WPML is only used to read language settings and to link the translated posts.

= Does it work without WPML? =

No. The plugin depends on WPML for language configuration and translation linking, and stays inactive without it.

= Which AI models can I use? =

Any model id listed on openrouter.ai/models. Set it on **Tools → AI Translate → Settings**, then press *Test model* to confirm it and pull its pricing. The default is an economical Gemini Flash model until you change it.

= Where does the plugin store its runs? =

In its own database tables (`wp_pxat_runs`, `wp_pxat_run_items`, `wp_pxat_run_log`). Each post is translated and written into WordPress as the run goes; you review the result in the normal editor.

= What happens to my data if I delete the plugin? =

Deleting the plugin removes its settings and those tables. Translations already written into your posts are left untouched.

== Screenshots ==

1. Dashboard - how to start a translation, all-time run and word totals, and recent runs.
2. Translation screen - destination language, model and rates, what to translate, and a per-post plan with a cost and word-count estimate before anything runs.
3. Settings - your OpenRouter key and model, batching and glossary options, and which companion plugins (ACF, Rank Math, WPBakery) are detected.
4. History - every run with its languages, model, post count, warnings or errors, word volume and cost.

== Changelog ==

= 0.0.22 =
* **Cancel a run.** The run screen now has a **Cancel run** button, and History a **Cancel** row action, for any run that still has posts waiting. Cancelling marks the remaining posts as skipped and stops the run; translations already written stay in place.
* The default model for new installs is now `google/gemini-3.8-flash`.

= 0.0.21 =
* **Featured image no longer reports a false "Could not set the featured image".** When the destination post already had the right image (a common case - WPML often duplicates it onto the translation), WordPress's own "nothing changed" response was being read as a failure. The step now checks the stored image directly.
* Featured image is remapped through WPML: if **WPML Media Translation** has a destination-language copy of the image, the translation gets that one; otherwise it shares the source file as before.

= 0.0.20 =
* Activity log: batch-wide lines ("Batch of 3 posts…", "request sent to OpenRouter") appeared once per post in the group. They are now written once, and any accidental back-to-back duplicate line is collapsed on display.

= 0.0.19 =
* Run screen: the button is now always labelled **Resume translating** (it was mislabelled "Start translating" whenever no post had fully finished yet, so it disagreed with the "Press Resume" message next to it).

= 0.0.18 =
* Fixed an **HTTP 400 "Reasoning is mandatory for this endpoint and cannot be disabled"** error introduced in 0.0.16. Requests now ask for minimal reasoning ("low") rather than none, which every model accepts; if a model rejects even that, the request is retried once without the setting instead of failing.

= 0.0.17 =
* **The activity log is far more informative.** It opens with a run header (model name and id, language pair, scope, post count, whether batching is on), names each post by its title instead of a bare id, and every model call now reports how long it took, which provider served it, the prompt / completion token split, and whether the reply finished normally or was cut off at the output limit. The write step reports each data type as ok / failed on one line.

= 0.0.16 =
* **Faster, cheaper model calls.** Every translation request now tells OpenRouter to route to the fastest provider for the chosen model and to skip "thinking" tokens - translation is a single-pass task and does not need them. On reasoning-capable models (for example Gemini 2.5 Flash) this cuts both the wait and the cost noticeably, with no quality change for normal content.
* A one-post run no longer uses the batched request format (a heavier prompt with no benefit at a single post) - it takes the plain path. Multi-post runs are unaffected.

= 0.0.15 =
* **A run is started in exactly one place now:** the **Translate and apply** button on the Translation screen. Reloading, bookmarking or returning to a run's progress URL - or following it from the toolbar - shows that run read-only, with a manual **Resume** button, and never restarts translating (or spending) on its own.
* The **Translate this page** toolbar item now opens the Translation screen for that post - the same screen the bulk action uses - and carries a translation icon.
* **Faster batched requests** is now on by default. New installs send several short posts per model request out of the box; existing sites keep whatever they last saved, and it can still be turned off in Settings.
* **AI Translate** moved from its own top-level admin menu to **Tools → AI Translate**.

= 0.0.14 =
* Run screen rebuilt on a single run state resolved on the server, which the page, the AJAX handler and the browser loop all read - so it can no longer reload over and over on a "pending" post, and it reflects the run the moment it loads instead of waiting for the first poll.
* An interrupted model request no longer charges twice or half-writes a post: a translation that was already produced is reused when its write is retried.
* Batched runs: when a whole batch request fails it is split and retried in smaller groups, so one bad post - or an over-large group - no longer fails the rest.

= 0.0.13 =
* Run screen: **Start translating** no longer stays visible while a run is in progress (a WordPress button style was overriding the hidden state).
* **Stop** now gives feedback: the button reads "Stopping..." while the current post finishes, then the screen settles into a **Resume translating** state with a one-line status ("Stopped. Press Resume to translate the remaining posts.").
* If a translate request fails, the run screen recovers instead of freezing, telling you to press Resume to retry; if the run had actually finished, it reloads into the completion screen.
* **Retry** on a failed row now shows "Retrying..." and re-enables itself with a reason if the retry does not go through, instead of staying greyed out forever. Clearing the last error reloads into the "Complete" screen.
* Every admin request (run loop, status poll, Retry, Settings "Test") now recognises an expired session and tells you to reload the page, rather than failing silently. A dropped connection during a run is surfaced too.
* Settings "Test": a failed model check reports on the model row itself (it no longer gets stuck on "Checking...", and no longer mislabels the API-key row).
* Confirm screen: using the browser Back button no longer leaves the Start buttons disabled.
* ID lookup: the "Copied" confirmation now only shows when the copy actually succeeded.

= 0.0.12 =
* When your OpenRouter key has a credit limit set, the Settings > API key row shows how much of it is left ("Verified · $37.66 left of $50.00") and turns amber below 20%, red at zero.
* The Translation screen warns when a run's estimated cost is more than the key's remaining credit, and blocks Start once the key is exhausted (top up at openrouter.ai and reload). Keys with no limit set are unaffected.

= 0.0.11 =
* The run screen is one grouped list now: the run id, language pair, mode and start time moved into the group's heading and footnote instead of a separate caption line, and the standalone progress bar became a compact meter inside the **Progress** row.
* When a run finishes, the Progress row itself reports the outcome (**Complete**, or **Finished with errors** with a retry hint) - no separate banner.
* The activity log is the last row of that group rather than a detached block, streams new lines live as the run works (no reload needed), and scrolls within a fixed height.
* The run auto-starts on open, so the button now reads **Stop** from the outset; **Start translating** only comes back if the run pauses or fails.
* The Translation screen's "N posts selected" line is the settings group's heading now, not a caption above it.
* Shared UI kit updated to 0.21.0 (adds the inline `meter()` component and height-caps code blocks).

= 0.0.10 =
* Verified against WPML 4.9.7, Secure Custom Fields 6.9.5, Rank Math SEO 1.0.277.2 / Rank Math SEO PRO 3.0.82 and WPBakery Page Builder 9.0.1.
* Settings > Compatibility now lists Rank Math SEO PRO and WPBakery Page Builder, names Secure Custom Fields alongside ACF, and shows the version each integration was tested against next to the version live on your site.

= 0.0.9 =
* Translation volume is always shown as an estimated word count now - the Tokens/Words display setting is gone. "Token" was a concept you had to learn to read a number you only glance at; cost is computed from real usage either way.
* On sites whose WPML default language is Vietnamese, estimated and run costs are shown in VND (fixed rate, ~26,000₫ to the dollar). Model price sheets stay in USD to show OpenRouter's exact rates.

= 0.0.8 =
* Slimmed down the Translation screen: one settings group instead of two, no step captions, and the redundant "About to translate…" summary is gone (the count and cost are on the button).
* "What to translate" is now a single **Everything / Specific fields** choice; the field list only appears when you pick **Specific fields**.
* Changing the language or scope re-runs the plan on its own - a spinner shows while it updates and the start button is held until it is ready. The **Update plan** button remains for browsers without JavaScript.
* The start button now reads **Translate and apply**.
* The Settings screen now warns before you leave with unsaved changes (edited a field, then clicked another menu item or the back button).
* Shared UI kit updated to 0.20.0 (adds the unsaved-changes guard).

= 0.0.7 =
* Removed the translation cart. Picking posts from a bulk action or the *Translate this page* toolbar item now goes straight to a confirm screen for exactly those posts - no persistent, accumulating list, and no more "different post type" refusal.
* The confirm screen lives at `admin.php?page=pxat-confirm` and is titled just **Translation**.
* Shared UI kit updated to 0.19.0: a checkbox is now a plain square box; the iOS switch (the *Faster batched requests* setting) is an explicit toggle style.

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
