=== CoderEmbassy AI SEO Automation ===
Contributors: codersaleh,phpcoderhannan
Tags: woocommerce, seo, ai, openai, automation
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate SEO titles, meta descriptions, JSON-LD schema, and image alt text for WooCommerce products using AI.

== Description ==

**CoderEmbassy AI SEO Automation** connects your store to leading AI providers and automatically writes high-quality, on-brand SEO content for products.

Bring your own API key from any supported provider — no subscription required.

= Free Features =

* **Single-Product Generation** — Generate SEO title (max 60 chars), meta description (max 160 chars), image alt text, and JSON-LD schema for any product with one click from the product edit screen.
* **Preview Before Apply** — See the AI output before writing it to your product. Approve or discard.
* **Rollback / Audit Trail** — Every change is logged. Roll back any product to its previous values at any time.
* **Focus Keyphrase** — Set a target keyphrase per product to guide the AI output.
* **1 Custom Rule** — Create one reusable rule to customise tone, language, brand name, or the full prompt template.
* **Multiple AI Providers** — Supports OpenAI, Anthropic (Claude), Groq, and Google Gemini. Bring your own API key.
* **Third-Party SEO Integration** — Writes directly to Yoast SEO, RankMath, and AIOSEO meta fields when those plugins are active.
* **PII Scrubbing** — Emails, phone numbers, and postal codes are stripped from product data before every API call.
* **React Admin UI** — Fast, responsive admin interface with dark/light mode built on React 18 and Tailwind CSS.

= Pro Features (upgrade at plugin.coderembassy.com) =

* **Bulk Generation** — Queue hundreds of products at once via a background job system with real-time progress tracking.
* **Autopilot** — Automatically queue new or updated products for SEO generation on save.
* **Unlimited Rules** — Create as many prompt rules as you need, scoped to any product category.
* **CSV Export** — Export your full audit log as a CSV file.
* **WP-CLI Support** — Process jobs from the command line.
* **Cost Estimator** — Preview the estimated API cost before running a bulk job.

= Supported AI Providers =

| Provider | Models |
|---|---|
| OpenAI | GPT-4o mini (default), GPT-4o, GPT-3.5 Turbo |
| Anthropic | Claude 3 Haiku (default), Claude 3 Sonnet, Claude 3 Opus |
| Groq | Llama 3.3 70B (default), Llama 3.1 8B, Gemma 2 9B, Mixtral 8x7B |
| Google Gemini | Gemini 2.0 Flash (default), Gemini 1.5 Flash, Gemini 1.5 Pro |

= External Services =

This plugin sends product data to third-party AI APIs. **No data is sent without your explicit configuration of an API key.**

The following external services are used depending on which provider you select in Settings:

* **OpenAI** — https://api.openai.com — [Privacy Policy](https://openai.com/policies/privacy-policy) | [Terms](https://openai.com/policies/terms-of-use)
* **Anthropic** — https://api.anthropic.com — [Privacy Policy](https://www.anthropic.com/privacy) | [Terms](https://www.anthropic.com/terms)
* **Groq** — https://api.groq.com — [Privacy Policy](https://groq.com/privacy-policy/) | [Terms](https://groq.com/terms-of-use/)
* **Google Gemini** — https://generativelanguage.googleapis.com — [Privacy Policy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms)

Data sent: product title, description, SKU, price, categories, attributes. A built-in PII scrubber removes emails, phone numbers, and postal codes before transmission. No data is stored on any server outside your own WordPress database.

== Installation ==

= Minimum Requirements =

* WordPress 6.4 or higher
* WooCommerce 8.0 or higher
* PHP 8.0 or higher
* An API key for at least one supported AI provider

= Automatic Installation =

1. Log in to your WordPress admin and go to **Plugins → Add New**.
2. Search for "AI WooCommerce Product SEO Automation".
3. Click **Install Now**, then **Activate**.

= Manual Installation =

1. Download the plugin zip file.
2. Go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Click **Install Now**, then **Activate**.

= After Activation =

1. Navigate to **AI SEO** in the WordPress admin menu.
2. Open the **Settings** tab.
3. Choose your preferred AI provider and paste your API key.
4. Click **Save Settings**, then **Test Connection** to verify the key works.
5. Open any WooCommerce product. Use the **AI SEO** metabox to generate, preview, and apply SEO content.

== Frequently Asked Questions ==

= Which AI provider should I use? =

For most stores, **OpenAI GPT-4o mini** offers the best balance of quality and cost. If you want a free tier to get started, **Groq** (console.groq.com) and **Google Gemini** (aistudio.google.com) both offer generous free API plans.

= Is my product data sent to third-party servers? =

Yes — product data is sent to whichever AI provider you configure. A built-in PII scrubber removes emails, phone numbers, and postal codes before transmission. No data is stored on any server controlled by this plugin beyond your own WordPress database.

= Does the plugin work alongside Yoast SEO / RankMath / AIOSEO? =

Yes. When one of these plugins is detected, AI-generated content is written directly to its native meta fields so the SEO plugin continues to control all frontend output as normal.

= Can I roll back changes if I don't like the AI output? =

Yes. Every apply operation is recorded in the audit log. Open any WooCommerce product and use the **AI SEO** metabox to roll back to the previous values.

= How do I generate SEO for a single product? =

Open the product in the WooCommerce editor. The **AI SEO** metabox on the right-hand side lets you generate, preview, and apply SEO content for that product.

= What happens to the data if I uninstall the plugin? =

Uninstalling via **Plugins → Delete** automatically drops all plugin database tables and removes all plugin options. No residual data is left behind.

= Can I customise the AI prompt? =

Yes. Navigate to **AI SEO → Rules** and create a rule. Each rule can override the brand name, language, tone, and the full prompt template using placeholder variables (`{product_name}`, `{sku}`, `{price}`, `{categories}`, `{short_description}`). Free tier supports 1 rule.

= How do I get Bulk Generation? =

Bulk generation, autopilot, unlimited rules, CSV export, and WP-CLI are available in **AI WooCommerce SEO Pro**. Visit [plugin.coderembassy.com](https://plugin.coderembassy.com) to upgrade.

== Changelog ==

= 1.0.0 =
* Initial release.
* Single-product AI SEO generation via product metabox (OpenAI, Anthropic, Groq, Gemini).
* Preview before apply with per-product rollback support.
* Focus keyphrase support.
* Rule engine with 1 custom rule on free tier.
* Write-through integration with Yoast SEO, RankMath, and AIOSEO.
* PII scrubbing before every external API call.
* Dark/light mode admin interface.
* Uninstall routine that cleanly removes all tables and options.
