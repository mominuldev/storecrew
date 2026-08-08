=== StoreCrew AI ===
Contributors: decentthemes
Tags: woocommerce, ai, chatbot, customer support, sales
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI chat crew for WooCommerce that answers from your own products and policies, and looks up orders after verifying identity.

== Description ==

StoreCrew AI puts a small crew of AI agents on your WooCommerce storefront. A
shopper opens the chat, and a Sales or Support specialist answers — grounded in
*your* catalogue and policy pages, not in whatever a general chatbot happened to
be trained on.

It is built around one idea: the assistant should be useful without being
dangerous. Answers are retrieved from your own content; order data is only ever
read after the customer proves the order is theirs; and anything that would
*change* your store waits for a human. Prompt injection hidden in a product
review or a page cannot escalate into an unauthorised action, because authority
never comes from what the model says — it comes from what the customer proved.

**Bring your own AI provider.** StoreCrew does not resell AI. You connect your
own key from Anthropic (Claude), OpenAI, Google Gemini, OpenRouter, or DeepSeek,
and you talk to that provider directly — your key, your account, your data
processing agreement. Nothing is sent anywhere else.

= What the crew does =

* **Answers product questions** from your catalogue — grounded, not guessed.
  Prices and stock are read live at the moment of the answer, so the assistant
  never quotes a stale price.
* **Answers policy questions** — shipping, returns, warranty — from your own
  published pages.
* **Looks up an order** only after the customer verifies it with the order
  number and billing email. One verified identity unlocks exactly one order.
* **Hands off** between Sales and Support as the conversation shifts, and
  **escalates to a human** when it should, emailing you the thread.

= Built for real WooCommerce stores =

* **HPOS and Cart/Checkout Blocks compatible.**
* **Resumable indexing** that survives a budget host killing PHP mid-run — a
  large catalogue indexes over time without re-billing work already done.
* **Spend controls**: a hard monthly cap checked before every call, a per-turn
  budget, and a pre-flight cost estimate before you index.
* **Streaming answers** where the host allows it, with a clean fallback where it
  does not.
* **Accessible widget**: a keyboard-navigable dialog with a live region for
  screen readers, and RTL support.

= Privacy =

StoreCrew makes no outbound requests except the AI provider calls you configure
with your own key. No telemetry, no analytics, no web fonts, no phone-home. IP
addresses used for rate limiting are stored only as salted hashes.

A separate premium add-on (StoreCrew AI Pro) extends the crew with Marketing and
Analytics agents; the free plugin is complete and useful on its own.

== Installation ==

1. Install and activate WooCommerce.
2. Install StoreCrew AI and activate it. You will be taken into a short setup
   flow the first time.
3. Add an API key from your chosen AI provider (Anthropic, OpenAI, Google
   Gemini, OpenRouter, or DeepSeek).
4. Choose which content to index (products, pages) and let the first index run.
5. Enable the chat widget and pick where it appears.

== Frequently Asked Questions ==

= Do I need an AI provider account? =

Yes. StoreCrew does not resell AI — you connect your own key from Anthropic,
OpenAI, Google Gemini, OpenRouter, or DeepSeek. You are billed by that provider
directly, and StoreCrew's spend controls help you stay within a cap you set.

= Can the assistant see my customers' orders? =

Only after the customer proves the order is theirs, by giving the order number
together with the billing email on that order (or by being logged in as its
owner). A verified customer can read that one order and no other.

= Can a malicious product review or page make the assistant do something it should not? =

No. Retrieved content is treated as untrusted text and can never grant an
action. Anything that would change your store is held for human approval, and
each agent can only use the tools it was given. Injection can waste a reply, but
it cannot cross a security boundary.

= Does it work on cheap shared hosting? =

Indexing is designed for it: work is chunked and resumable, so a host that kills
long-running PHP loses at most one small batch rather than the whole run.

= Does StoreCrew send my data anywhere? =

Only to the AI provider you configured, using your key. There is no telemetry,
no analytics, and no other outbound request.

= Is it translation-ready? =

Yes. All customer-facing and server strings are translatable, and the storefront
widget supports right-to-left languages.

== Screenshots ==

1. The storefront chat widget answering a product question, grounded in the
   store's own catalogue.
2. Identity verification before an order is read.
3. The admin console: crew status, spend against cap, and the conversation
   inbox.
4. The setup flow — provider key, content selection, and going live.
5. Index health and cost estimate before a run.

== Changelog ==

The plugin is pre-release; the entries below are development milestones. See
CHANGELOG.md in the plugin for the full engineering history.

= 0.1.0 =
* Storefront chat widget with Sales and Support agents, grounded in the store's
  own products and policy pages.
* Identity verification before any order is read; one verified identity unlocks
  exactly one order.
* Knowledge base with resumable, budget-host-safe indexing; prices and stock
  read live so answers never go stale.
* Multi-provider support (Anthropic, OpenAI, Google Gemini, OpenRouter,
  DeepSeek) with spend caps, per-turn budgets, and pre-flight cost estimates.
* Streaming answers with a buffered fallback; accessible, RTL-ready widget.
* HPOS and Cart/Checkout Blocks compatibility.
* Full internationalisation of customer-facing and server strings.

== Upgrade Notice ==

= 0.1.0 =
First public pre-release.
