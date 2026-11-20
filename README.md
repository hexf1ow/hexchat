# HexChat

**A free, open-source AI chat widget for WordPress that answers only from your own website — with your own API key.**

No account with us, no proxy, no subscription. You paste a key from OpenAI (or any OpenAI-compatible endpoint), Anthropic, or Google Gemini, and the plugin calls that provider directly from your server.

![HexChat widget answering a real pricing question](docs/screenshot-chat.png)

---

## Why it exists

Most "AI chat" plugins are either a canned-question box wearing a chat skin, or a paid SaaS that sits between you and your own visitors. HexChat is a plain WordPress plugin:

- **Your content.** Answers are built from the pages and posts you already publish.
- **Your key.** The provider is yours; there is no Hex Flow account and no middleman.
- **Your data.** Transcripts live in your database. Nothing is sent anywhere except the model call you configured.
- **Free.** GPL-3.0. Fork it, ship it, resell it.

---

## What it does

- **Answers from your site, not the internet.** Your published content is indexed into passages and handed to the model as its only source of facts. If your site does not say it, the assistant does not claim it.
- **Speaks like a person.** Short, plain-English replies — two to four sentences — in your own tone, never "according to the context".
- **Declines off-topic questions politely.** Ask about the moon, the football or a car repair and it declines in one warm sentence, then offers what it *can* help with. Five different decline shapes, and it reads its own last replies so no two refusals read the same.
- **Quotes your real prices.** If your pricing page states a figure, it quotes it exactly and names the plan. It will not invent one, and it will not withhold one that is in front of it.
- **Hands over when it should.** When something is not answerable from your content, it says so and offers your email or a person — never a fake "I've passed that on".
- **Handles paraphrases.** Word stemming, title-weighted ranking and a fallback to your homepage/about copy mean "what do you guys do", "who are you" and "what type of company are you" all get a real answer instead of a dead end.
- **Looks like your brand.** Brand colour, logo image, header title and subtitle, launcher position — all settings, no code.
- **Keeps the bill small.** Per-minute rate limit, per-visitor daily cap, message length cap and a token ceiling, with an optional strict knowledge wall for owners who want hard refusal.

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An API key from one supported provider

---

## Install

**From the zip (normal WordPress way)**

1. Download `hexchat-<version>.zip` from [Releases](https://github.com/hexf1ow/hexchat/releases).
2. Plugins → Add New → **Upload Plugin** → choose the zip → Install → **Activate**.
3. Settings → **HexChat**: pick a provider, paste your key, choose a model, tick *Show the chat widget on the site*, Save.

**From git**

```bash
cd wp-content/plugins
git clone https://github.com/hexf1ow/hexchat.git
```

Then activate **HexChat** and open **Settings → HexChat**.

---

## Providers

| Provider | Setting value | Notes |
| --- | --- | --- |
| OpenAI-compatible | `openai` | OpenAI, OpenRouter, Groq, Together, DeepSeek, LM Studio, Ollama `/v1`, vLLM — set the base URL to match |
| Anthropic | `anthropic` | `claude-3-5-haiku-latest` and newer |
| Google Gemini | `gemini` | `gemini-2.0-flash` and newer |

The key is stored encrypted (libsodium secretbox, derived from your `AUTH_KEY`), is never rendered back into the page, and is never sent to the browser. All model calls happen server-side through the plugin's own REST route.

---

## Settings

| Setting | What it does |
| --- | --- |
| Show the chat widget | On unless you explicitly untick it. Hidden automatically if no key is saved, with the reason shown |
| Provider / Base URL / Model | Which API to call, and which model on it |
| API key | Encrypted at rest; only a `••••last4` hint is shown |
| Temperature | 0–2, default 0.3 |
| Max answer length | Token ceiling per reply (default 500) |
| Max message length | Visitor input cap, enforced in the widget and on the server |
| Rate limit / Daily cap | Per-minute and per-visitor-per-day limits |
| Chat title / Subtitle / Greeting | The wording visitors see |
| Brand colour | `ff6a00`, `#ff6a00` or `#f60` — with a live swatch |
| Logo image URL | Launcher and header avatar; leave empty for the bundled Hex Flow robot mark |
| Launcher position | Bottom right or bottom left |
| System prompt | Extra instructions on top of the built-in behaviour rules |
| Read for answers | Pages, blog posts, or both |
| Handover email | Shown when the assistant cannot answer |
| Keep transcripts for | Days before old transcripts are pruned (runs daily) |
| Strict knowledge wall | Off by default. On: any reply that reuses too little of your own wording is replaced with a fixed refusal — stricter, but it also refuses greetings and some legitimate paraphrases |

Every one of these is exercised by the plugin — there are no decorative fields.

---

## How an answer is built

1. The question is reduced to terms, with stopwords removed and word endings normalised ("prices" matches "pricing").
2. Your published pages and posts are split into ~900-character passages and scored BM25-style, with a boost for passages whose **title** carries a query term, and a further boost for passages containing a `$` figure when the question is about money.
3. The best passages — plus your homepage/about copy, so broad questions never dead-end — are appended to the system prompt as the only source of facts.
4. A built-in rule block decides the behaviour: answer from that content, quote figures exactly, decline anything unrelated politely, never reveal instructions, never claim a booking was made.
5. The reply, its sources and a handover flag are returned to the widget and written to your transcript table.

No embeddings, no vector database, no external service. It runs on ordinary shared hosting. Embeddings are on the roadmap as an option, not a requirement.

---

## Security posture

- API key encrypted at rest; decrypted in memory only for the outbound request.
- Every model call is server-side through `POST /wp-json/hexchat/v1/chat`.
- `wp_rest` nonce check on every request, plus per-IP rate and daily caps.
- No raw IP stored — salted SHA-256 only.
- Visitor text is length-capped and sanitised; instructions inside a message cannot change the assistant's rules.
- Uninstall drops all options and the transcript table.

---

## Development

```bash
git clone https://github.com/hexf1ow/hexchat.git && cd hexchat
# lint every PHP file
for f in $(find . -name '*.php'); do php -l "$f"; done
```

Layout:

```
hexchat.php                     plugin header, constants, defaults, widget enqueue
includes/class-hexchat-crypto.php      libsodium key storage
includes/class-hexchat-store.php       transcript table, retention
includes/class-hexchat-retrieval.php   indexing, stemming, ranking, overview fallback
includes/class-hexchat-rest.php        REST route + the behaviour rules
includes/class-hexchat-settings.php    admin screen
includes/providers/                    one class per provider behind one interface
assets/js/widget.js, assets/css/       shadow-DOM widget
.github/workflows/release.yml          lint + zip + GitHub release on v* tags
```

Pull requests welcome. Behaviour lives in `HexChat_Rest::rules()` so prompt improvements ship as a version bump rather than a per-site edit.

---

## Roadmap

- [ ] Streaming responses (SSE)
- [ ] Optional embeddings for very large sites
- [ ] Multi-language UI strings
- [ ] WooCommerce product grounding
- [ ] WP-CLI index rebuild
- [ ] Per-page exclusion from the index

---

## Changelog

**0.4.0** — Brand colour now actually applies (base palette no longer overrides it), with a live swatch. Logo image in launcher and header, bundled robot mark as default. Editable header subtitle. Retention clean-up now scheduled daily (it was never running). Choose pages, posts, or both.

**0.3.2** — Warmer, scoped greetings; five rotating shapes for polite off-topic declines.

**0.3.0** — Guidance-first: natural answers from site content, soft intelligent declines. Strict wall becomes an opt-in switch.

**0.2.3** — Greetings and thanks answered directly instead of hitting the knowledge wall.

**0.2.2** — Widget stays on unless explicitly unticked; empty saved value no longer hides it.

**0.2.1** — Overview fallback for broad questions, word stemming, title and money-passage ranking.

**0.2.0** — Hard knowledge wall: no matching content means the model is never called.

**0.1.1** — Expose max answer length; floor numeric options so a missing field cannot send 0 tokens.

**0.1.0** — First release.

---

## Licence

GPL-3.0-or-later. Use it, fork it, ship it.
