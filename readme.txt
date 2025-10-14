=== AI Chat Connect ===
Contributors: estebandezafra
Tags: whatsapp, ai, chatbot, meta, automation
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: aichat-connect
Domain Path: /languages

AI Chat Connect integrates the WhatsApp (Meta Cloud API) channel with the existing Axiachat AI (core) plugin or alternative AI providers (AI Engine) so incoming WhatsApp messages are automatically answered by mapped bots.

== Description ==
AI Chat Connect is an addon that receives incoming WhatsApp messages (via Meta Cloud API Webhook) and routes them to a selected bot provider. Each Business Phone Number ID (phone_number_id) can be mapped to an Axiachat AI bot or an alternative provider (currently AI Engine). The reply is sent back over the WhatsApp Cloud API and all interactions are logged.

Key architectural layers (all singletons):
1. Webhook (REST): Validates GET challenge, parses first text message from incoming payload.
2. Service: Orchestrates provider call, timeout / fast ACK logic, logging and outbound send.
3. Repository: Database CRUD for numbers, providers, messages.
4. API Client: Low‑level Graph API /messages POST with token expiry detection + transient backoff.
5. Admin UI: Mappings, Settings, Logs, Providers behavior tuning.
6. Activator: Creates / upgrades 3 custom tables.

Features:
* Map each Business Phone Number ID to a bot slug and provider (Axiachat AI core or AI Engine).
* Per-provider behavior: timeout (ms), fast acknowledgement text, fallback strategy.
* Fast ACK: send an immediate short response while the real provider call completes.
* Unified message logging (in + out) with optional compact row (inbound + bot response).
* Deterministic session id: `wa_{md5(user_phone)}` for continuity without DB lookups.
* Token expiry detection (Graph error code 190) with short transient suppression of repeated failing calls.
* Filters & actions for custom pre-processing and post-send hooks.
* Internationalized (English base). POT file included (`languages/aichat-connect.pot`).

Disclaimer: This plugin is not affiliated with or endorsed by Meta. You must comply with all Meta / WhatsApp terms and obtain any required user consent.

== Screenshots ==
1. Mappings screen: Phone ID → Bot.
2. Settings: Webhook info & credentials.
3. Providers: timeout, fast ACK & fallback options.
4. Logs: grouped by day + phone.
5. Conversation detail view.

(Place screenshot-1.png, screenshot-2.png, etc. in the plugin root or /assets as per WP.org guidelines.)

== Installation ==
1. Upload the plugin folder `aichat-whatsapp` to `/wp-content/plugins/` or install via ZIP upload.
2. Activate plugin through the WordPress "Plugins" menu.
3. Go to: AI Chat Connect → Mappings.
4. Copy the Webhook URL shown and configure it in your Meta App (WhatsApp Cloud API) with the same Verify Token you set in the plugin.
5. In "Mappings" add at least one Phone ID → Bot mapping (or rely on global bot fallback if set in AI Chat core).
6. (Optional) Tune provider behavior under "Providers".
7. Send a WhatsApp message to your connected number to test. Check Logs.

== Frequently Asked Questions ==
= Do I need the Axiachat AI core plugin? =
It is recommended. If absent, mappings using provider "Axiachat AI" will fail, but AI Engine provider mappings can still function.

= How do I find my Business Phone Number ID? =
In Meta Developers → WhatsApp → API Setup you will see the phone_number_id. Use that exact numeric ID in the mapping.

= What about personal WhatsApp numbers? =
Only WhatsApp Business Cloud API numbers (phone_number_id) are supported.

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
* `{prefix}aichat_connect_numbers`: phone (Meta phone_number_id), bot_slug, service, display_name, access_token, is_active.
* `{prefix}aichat_connect_messages`: wa_message_id, phone, direction (in|out), bot_slug, session_id, user_text, bot_response, status, meta JSON, created_at.
* `{prefix}aichat_connect_providers`: provider_key, name, description, is_active, timeout_ms, fast_ack_enabled, fast_ack_message, on_timeout_action, fallback_message, meta.

== Security Notes ==
* Verify token comparison uses `hash_equals`.
* Token expiry errors (Graph code 190) trigger a short transient block to avoid spamming failing requests.
* Challenge response echoes raw `hub.challenge` as required by Meta (string usually numeric). Ensure webhook endpoint is served over HTTPS.
* Always store production access tokens securely and rotate as needed.

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
