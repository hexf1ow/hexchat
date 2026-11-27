=== HexChat ===
Contributors: hexflow
Tags: chat, chatbot, ai, openai, assistant
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An AI chat widget that answers from your own pages. Free, open source, bring your own API key.

== Screenshots ==

1. The chat panel answering a real pricing question from the site's own pages.
2. The launcher button, branded with your logo and colour.

== Description ==

HexChat adds a modern chat widget to your site and answers visitor questions from your own published content.

It is free and it always will be. There is no subscription, no proxy service and no account with us. You paste your own API key — OpenAI, OpenRouter, Groq, Ollama, Anthropic or Gemini — and the plugin talks to that provider directly. On WordPress 7.0 and later you can instead use the site's own AI Client, so no key is needed here at all.

**What it does**

* A clean, mobile-first chat widget that loads in a shadow root, so your theme cannot break it.
* Answers grounded in your own pages and posts. No content, no answer — it says so instead of inventing one.
* Hands over to a human, by email, when it is not confident.
* Your API key is stored encrypted with your site's own secret and is never sent to the browser.
* Rate limits and a daily cap protect your API bill.

**What it does not do**

* It does not train any model on your data.
* It does not send your visitors' questions anywhere except your chosen provider.
* It does not replace your theme, your forms or your existing plugins.

== External services ==

HexChat talks to one third-party AI provider — the one you select in Settings. Nothing is sent anywhere until a visitor actually sends a message, and only one provider is used at a time.

What is sent, for every provider: the visitor's message, the text of the pages or posts HexChat retrieved to answer it, your system prompt and the model name. No visitor IP address, email address or account details are sent. Your API key is sent to your chosen provider as the authentication header.

* **OpenAI** (`api.openai.com`, or the HTTPS base URL you configure). Used to generate answers when OpenAI is the selected provider. Provided by OpenAI — [terms of use](https://openai.com/policies/terms-of-use/), [privacy policy](https://openai.com/policies/privacy-policy/).
* **Anthropic** (`api.anthropic.com`). Used to generate answers when Anthropic is the selected provider. Provided by Anthropic — [commercial terms](https://www.anthropic.com/legal/commercial-terms), [privacy policy](https://www.anthropic.com/legal/privacy).
* **Google Gemini** (`generativelanguage.googleapis.com`). Used to generate answers when Gemini is the selected provider. Provided by Google — [Gemini API terms](https://ai.google.dev/gemini-api/terms), [privacy policy](https://policies.google.com/privacy).

If you point the base URL at a different OpenAI-compatible service (OpenRouter, Groq, Ollama, LM Studio), that service becomes the recipient and its own terms and privacy policy apply.

HexChat does not contact any server operated by the plugin author, and collects no analytics or telemetry.

== Installation ==

1. Upload the `hexchat` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New.
2. Activate the plugin.
3. Go to Settings → HexChat, choose your provider, paste your API key and pick a model.
4. Tick "Show the chat widget on the site" and save.

== Frequently Asked Questions ==

= Do I need an OpenAI account? =

No. Any OpenAI-compatible endpoint works, including OpenRouter, Groq, Ollama and LM Studio. Anthropic and Gemini are supported natively.

= Where is my API key stored? =

In your WordPress options table, encrypted with libsodium using a key derived from your site's `AUTH_KEY`. It is never rendered back into the admin page and never exposed to the browser.

= What happens when the AI does not know the answer? =

It says so and offers to hand the question to a person. The system prompt forbids inventing prices, phone numbers or policy.

= Are transcripts stored? =

Yes, in a local table, with a retention setting (30 days by default) and no raw IP addresses — only a salted hash for rate limiting.

== Changelog ==

= 0.4.2 =
* Review fixes. The admin colour swatch is enqueued with `wp_add_inline_script()` instead of being printed as a `<script>` tag.
* Settings sanitisation: URLs use `esc_url_raw`, the handover email uses `sanitize_email`, the system prompt uses `sanitize_textarea_field`, and provider and position are validated against their allowed values.
* New: "WordPress AI Client (7.0+)" provider. On WordPress 7.0 and later the plugin uses the site's own configured AI provider, so no API key is needed in HexChat.
* readme: new External services section naming OpenAI, Anthropic and Google Gemini, with what is sent, when, and links to their terms and privacy policies.

= 0.4.1 =
* Plugin Check clean-up: translators comment, uninstall table name, textdomain loading and Domain Path removed for WordPress.org submission.

= 0.4.0 =
* Brand colour now actually applies (the base palette no longer overrides it), accepts ff6a00 or #ff6a00, with a live swatch in Settings.
* New: logo image in the launcher and chat header, with the Hex Flow robot bundled as the default.
* New: editable header subtitle (the "AI assistant · answers from this site" line).
* Fix: the transcript retention setting now runs daily; it was never scheduled before.
* New: choose whether answers read pages, blog posts, or both.

= 0.3.2 =
* Warmer greetings, scoped to what the business does, how it works and what it costs.
* Off-topic declines rotate through five different shapes, so no two refusals read the same.

= 0.3.0 =
* Guidance-first replies: the assistant answers naturally from the site and declines unrelated questions softly in its own words, instead of a fixed refusal.
* Behaviour rules now live in the plugin, so improving them needs no settings change.
* Strict knowledge wall kept as an experimental switch in Settings (off by default).

= 0.2.3 =
* Fix: "Hi", "hi there", "good morning" and "thanks" get a real reply instead of the knowledge-wall refusal. Greetings are answered directly and cost no API call.

= 0.2.2 =
* Fix: the widget stays on unless you explicitly untick it. A saved empty value no longer hides the chat.
* Fix: widget also hides itself when no API key is saved, with the reason shown in Settings.

= 0.2.1 =
* Broad questions ("what type of company are you", "hi what do you guys do") now answer from the site's own introduction instead of dead-ending.
* Word stemming, so "prices" finds "pricing".
* Page titles and money passages rank higher, so pricing questions quote the real figures.
* Off-topic questions stay refused, but the refusal now names what the business does and offers the team.

= 0.2.0 =
* Hard knowledge wall: if nothing on the site matches the question, the AI is never called and a fixed refusal is returned.
* Second check: replies that use words the site does not use are discarded and replaced with the refusal.

= 0.1.1 =
* Fix: Max answer length is now a visible setting, and a missing value falls back to 500 instead of sending 0 to the provider.

= 0.1.0 =
* First release: widget, retrieval over pages and posts, three providers, encrypted key storage, transcripts, rate limits.
