=== HexChat ===
Contributors: hexflow
Tags: chat, chatbot, ai, openai, assistant
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An AI chat widget that answers from your own pages. Free, open source, bring your own API key.

== Description ==

HexChat adds a modern chat widget to your site and answers visitor questions from your own published content.

It is free and it always will be. There is no subscription, no proxy service and no account with us. You paste your own API key — OpenAI, OpenRouter, Groq, Ollama, Anthropic or Gemini — and the plugin talks to that provider directly.

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
