# AI Chat Connect Addon – Copilot Instructions

Concise reference for AI agents contributing to this WordPress addon. Focus on real, current patterns (do not document roadmap features as implemented).

## Core Purpose
Receive WhatsApp (Meta Cloud API) text messages and answer using a mapped bot from either the AI Chat core plugin (`aichat_generate_bot_response`) or alternative provider (AI Engine). Admin UI manages phone → bot/provider mappings, provider behavior (timeouts / fast ack), and logs.

## Layered Architecture (All Singletons)
1. Webhook (`AIChat_Connect_Webhook`): Registers REST route, verifies GET token, extracts first text message from payload.
2. Service (`AIChat_Connect_Service`): Orchestrates bot resolution, provider call, timeout / fast ack logic, logging, outbound send.
3. Repository (`AIChat_Connect_Repository`): DB CRUD for numbers, providers, messages; bot + credential resolution.
4. API Client (`AIChat_Connect_API_Client`): Low-level Graph API POST /messages with token expiry detection + transient backoff.
5. Admin (`AIChat_Connect_Admin`, `AIChat_Connect_Admin_Providers`): Menus (Mapeos, Settings, Logs, Providers), Bootstrap assets, forms.
6. Activator (`AIChat_Connect_Activator`): Creates three tables (numbers, messages, providers) via `dbDelta` on activate / upgrade.

## Database Tables
- `*_aichat_connect_numbers`: columns `phone` (Meta phone_number_id), `bot_slug`, `service` (provider key), optional `access_token` (overrides global).
- `*_aichat_connect_messages`: unified log; inbound rows may also contain `bot_response` (so one row can hold both sides) plus status + meta JSON.
- `*_aichat_connect_providers`: configurable provider behavior (timeout_ms, fast_ack, on_timeout_action, fallback messages, meta JSON).

## Bot & Credential Resolution (Current Actual Logic)
`AIChat_Connect_Repository::resolve_bot_slug($business_id, $user_phone)`:
1. Exact match on business `phone_number_id` in numbers table.
2. Fallback: global option `aichat_global_bot_slug` (service forced to `aichat`).
No wildcard / user phone chain implemented (older doc obsolete).
Credentials: `resolve_credentials` returns mapping-specific token/phone_id else global (`aichat_connect_access_token`, `aichat_connect_default_phone_id`).

## Message Processing Flow
POST /wp-json/aichat-wa/v1/webhook → extract first text → Service `handle_incoming_text`:
- Idempotency: skip if WA message id already logged.
- Resolve bot + provider row.
- Optional fast ACK (independent send) if provider `fast_ack_enabled`.
- Provider dispatch:
    * service `aichat`: call `aichat_generate_bot_response($bot_slug,$text,$session_id,['source_channel'=>'whatsapp'])` if available.
    * service `ai-engine`: call `$GLOBALS['mwai']->simpleChatbotQuery()` if plugin active; else WP_Error.
- Timeout handling after provider call: apply `on_timeout_action` (silent | fast_ack_followup | fallback_message).
- Log inbound row (with response if any) then send WA reply via API client (unless silent timeout or error). Outbound manual sends log a separate `direction='out'` row.

## Sessions
Deterministic: `wa_` + `md5(user_phone)` for continuity across requests (no DB lookup required).

## Logging & Debug
Central helper `aichat_connect_log_debug($message,$context)` gated by constant `AICHAT_CONNECT_DEBUG` (default true in dev). Provide scalar context only; WP_Error is stringified. Messages logged before and after each major stage (mapping, provider call, send, errors, timeouts).

## Error / Resilience Patterns
- Provider & send failures use `WP_Error` with specific codes (`aichat_core_missing`, `ai_engine_not_available`, `wa_token_expired`, etc.).
- Token expiry (Graph error code 190) triggers transient `aichat_connect_token_block` (2 min) to suppress repeated failing calls.
- Idempotency: `message_exists()` check by `wa_message_id` (truncated to 100 chars if longer).

## Providers Layer
Providers table lets UI tweak runtime behavior without code changes: fast ack text, timeout thresholds, fallback strategy. Service reads row once per inbound message.

## Hooks / Filters
- Filter `aichat_connect_pre_provider( $arr, $service, $phone, $bot_slug )` can abort (`proceed=false`) or mutate text.
- Action `aichat_connect_post_provider( $info_array )` fires after successful send (informational only).
- Filter `aichat_connect_graph_version` to override Graph API version (default v23.0).

## REST Endpoints
- GET `/wp-json/aichat-wa/v1/webhook` verify: returns raw challenge if `hub.verify_token` matches stored option (uses `hash_equals`).
- POST `/wp-json/aichat-wa/v1/webhook` message intake (currently only processes first text message, others ignored gracefully).

## Admin UX Conventions
- Menus: Mapeos (numbers), Settings (tokens & webhook info), Logs (grouped day+phone), Providers (behavior tuning), hidden Logs detail page.
- Inbound + assistant response often share one row (direction=in with `bot_response`). Pure outbound manual sends create separate `direction=out` row.

## Extending Safely
- Always use repository methods for DB (they encode meta JSON & maintain updated_at).
- New provider keys: insert row in providers table; map numbers with `service`=key; implement integration inside Service switch.
- When adding media support: extend Service to branch on message type before calling provider; keep idempotency & logging semantics.

## Quick Reference Examples
Singleton pattern:
```php
class Example { private static $i; static function instance(){ return self::$i ?: self::$i = new self(); } }
```
Send outbound programmatically:
```php
aichat_connect_send_message('+34999999999', 'Hola!', 'mi_bot');
```

## Known Gaps (Intentional, See ROADMAP.md)
No signature validation (X-Hub-Signature-256) yet; no media, rate limiting, or delivery status handling. Do not assume these exist.

Keep edits consistent with these patterns; prefer adding hooks/filters over modifying core flow directly.