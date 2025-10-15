=== AI Chat Connect ===
Contributors: estebandezafra
Tags: whatsapp, telegram, ai, chatbot, support, automation
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: aichat-connect
Domain Path: /languages

AI support for WhatsApp and Telegram. Connect Axiachat AI, AI Engine, or AIPKit to auto‑reply, log chats, and manage per‑number mappings.

== Description ==
Delight your customers with instant answers where they already are. AI Chat Connect turns your WhatsApp Business (Cloud API) and Telegram bot into an AI‑powered support channel for your site, store, or blog.

Map each WhatsApp phone number (phone_number_id) or Telegram endpoint to an AI bot, then let the plugin auto‑reply on your behalf. Every conversation is logged so you can review activity anytime.

What you can do:
* Auto‑reply on WhatsApp and Telegram using your preferred AI provider (Axiachat AI, AI Engine, or AIPKit).
* Map each number/endpoint to a specific bot (per‑channel, per‑phone mapping).
* Fine‑tune provider behavior: response timeout, “Fast Ack” quick reply, and fallback on timeout.
* See everything: unified logs (incoming/outgoing), conversation detail view, and basic stats per day/phone.
* Safer operations: token‑expiry detection (Meta Graph code 190) and short transient backoff.
* Extend to your needs with hooks/filters. Fully internationalized; POT file included.


How it works (in simple terms):
1) A webhook receives the message (WhatsApp: Meta Cloud API; Telegram: Bot API webhook).
2) The service picks the mapped bot/provider for that number/endpoint and asks it for a reply.
3) A short optional “Fast Ack” can be sent immediately while the full answer is generated.
4) The final reply is sent back to the user and everything is logged for review.

Supported providers today:
* Axiachat AI (recommended)
* AI Engine (Meow)
* AIPKit (REST provider)

This plugin is not affiliated with or endorsed by Meta or Telegram. Use according to the platforms’ terms and obtain any required user consent.

Quick Start (2 minutes):
* WhatsApp
    1. Go to Mappings → Add. Channel: WhatsApp. Endpoint ID: your phone_number_id. Paste your access token and set a Verify Token.
    2. Copy the webhook URL shown and paste it in your Meta App (Cloud API) with the same Verify Token.
    3. Send a message to your WA number and check Logs.
* Telegram
    1. Create a bot with @BotFather and copy the bot token.
    2. Go to Mappings → Add. Channel: Telegram. Endpoint ID: friendly label. Specific Token: your bot token.
    3. Click the generated setWebhook link, then send a message to your bot and check Logs.


== Use Cases ==
* Ecommerce support: order status, returns, shipping FAQs right from WhatsApp/Telegram.
* Services and appointments: business hours, pricing, booking steps, and basic triage.
* Lead capture 24/7: collect name/email/intent and hand off to your CRM (via your bot logic).
* Knowledge base deflection: answer repetitive questions using your site’s content.
* Multilingual autoresponder: greet and help users in their own language.
* Teams and agencies: map multiple numbers/endpoints to different bots and brands.

== Screenshots ==
1. Mappings: connect WhatsApp phone IDs or Telegram endpoints to bots.
2. Providers: timeout, Fast Ack, and fallback options.
3. Logs: grouped by day+phone with quick drill‑down.
4. Conversation detail: user and bot bubbles with status.

(Place screenshot-1.png, screenshot-2.png, etc. in the plugin root or /assets as per WP.org guidelines.)

== Installation ==
1. Upload the plugin folder `aichat-connect` to `/wp-content/plugins/` or install via ZIP upload.
2. Activate the plugin through the WordPress "Plugins" menu.
3. Go to: AI Chat Connect → Mappings.
4. WhatsApp: copy the Webhook URL shown and configure it in your Meta App (Cloud API) with the same Verify Token you set in the mapping.
5. Telegram: create a bot with @BotFather, paste its token in the mapping, and open the generated setWebhook link.
6. Add at least one mapping and (optionally) tune provider behavior under "Providers".
7. Send a message to your connected WhatsApp number or Telegram bot to test. Check Logs.

== Frequently Asked Questions ==
= Do I need the Axiachat AI core plugin? =
It’s recommended. If absent, mappings using provider "Axiachat AI" will fail, but other providers (e.g., AI Engine, AIPKit) can still be used.

= How do I find my Business Phone Number ID? =
In Meta Developers → WhatsApp → API Setup you will see the phone_number_id. Use that exact numeric ID in the mapping.

= What about personal WhatsApp numbers? =
Only WhatsApp Business Cloud API numbers (phone_number_id) are supported.

= Does it support Telegram? =
Yes. Create a bot with @BotFather, add a Telegram mapping with a friendly Endpoint ID and your bot token, then hit the provided setWebhook URL.

= Which AI providers are supported? =
Axiachat AI (core), AI Engine (Meow), and AIPKit (REST). New providers can be added via a table row + a small integration.

= Which message types are supported? =
Currently only text inbound messages are processed. Media, templates, and interactive message types are gracefully ignored (logged as ignored). See ROADMAP.md for planned extensions.

= How are sessions tracked? =
Session id = `wa_` + `md5(user_phone)`. This keeps continuity across messages without writing a session record.

= How do timeouts work? =
If a provider call exceeds the configured timeout, the `on_timeout_action` determines behavior: silent, fast_ack_followup, or fallback_message.

= What is Fast ACK? =
A short immediate response (e.g. "One moment...") sent before the full provider response, improving perceived responsiveness.

= Can I customize the Graph API version? =
Yes. Use the filter `aichat_connect_graph_version` to return a string like `v23.0`.

= How do I modify / sanitize user text before it reaches the provider? =
Use the filter `aichat_connect_pre_provider`. Return an array with `proceed => false` to abort, or mutate `text`.

= How do I know when a message was sent? =
Action `aichat_connect_post_provider` fires after a successful send with an info array.

= Can I add new providers? =
Yes. Insert a row in the providers table with a new `provider_key` then extend the Service switch logic to handle it (or hook via filters).

= How do I translate the plugin? =
Use the included `languages/aichat-connect.pot` with your translation tool (e.g. Poedit) and place compiled MO files under `languages/` or the global WP languages directory.

== Hooks / Filters Reference ==
`aichat_connect_pre_provider( $arr, $service, $phone, $bot_slug )`  
Filter: Modify or abort before provider dispatch. Set `proceed=false` to stop.

`aichat_connect_post_provider( $info_array )`  
Action: Fires after outbound send attempt (success context).

`aichat_connect_graph_version`  
Filter: Override Graph API version (default: v23.0 or current hardcoded default in API client).

== Database Schema ==
Tables (with `$wpdb->prefix`):
* `{prefix}aichat_connect_numbers`: phone (Meta phone_number_id or Telegram endpoint), channel, bot_slug, service, display_name, access_token, verify_token, is_active, created_at/updated_at.
* `{prefix}aichat_connect_messages`: wa_message_id, phone, channel, external_id, direction (in|out), bot_slug, session_id, user_text, bot_response, status, meta JSON, created_at.
* `{prefix}aichat_connect_providers`: provider_key, name, description, is_active, timeout_ms, fast_ack_enabled, fast_ack_message, on_timeout_action, fallback_message, meta, created_at/updated_at.

== Security Notes ==
* WhatsApp webhook verification echoes the raw `hub.challenge` as required by Meta. Use HTTPS.
* Verify tokens are stored per mapping and matched during verification in your Meta App.
* Token‑expiry errors (Graph code 190) trigger a short transient block to avoid repeated failing calls.
* Store access tokens securely and rotate as needed.

== Roadmap (Abbreviated) ==
(See `ROADMAP.md` for details) Media handling, delivery status events, rate limiting, signature validation, extended provider ecosystem.

== Changelog ==
= 0.1.0 =
* Initial public release (foundational mapping, providers layer, logging, i18n base, security hardening, PHPCS compliance adjustments).

== Upgrade Notice ==
= 0.1.0 =
First release. Review settings after upgrade; verify webhook verify token and token permissions.

== Localization ==
English included (base). POT file available. Load a translation by adding the MO file to `/languages/` (text domain: aichat-connect).

== Code Snippets ==
Programmatic outbound message:
```
if ( function_exists('aichat_connect_send_message') ) {
    aichat_connect_send_message('+34123456789', 'Hello from code!', 'my_bot_slug');
}
```
Filter example to prepend a note to user text:
```
add_filter('aichat_connect_pre_provider', function($arr){
    if (!empty($arr['text'])) { $arr['text'] = '[User via WhatsApp] ' . $arr['text']; }
    return $arr;
}, 10, 1);
```

== Support ==
Open an issue in your project tracker or contact the author. Provide logs (enable debug by defining `AICHAT_CONNECT_DEBUG` true) and sanitized payloads when reporting issues.

== Disclaimer ==
Use at your own risk. Ensure compliance with WhatsApp / Meta platform policies and privacy regulations (e.g. GDPR) when processing user messages.
