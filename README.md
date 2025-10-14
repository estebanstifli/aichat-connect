# AI Chat Connect Addon (WhatsApp)

Integración de WhatsApp (Meta Cloud API) para el plugin Axiachat AI. Este addon enruta mensajes entrantes a un bot configurado y responde automáticamente.

## Estado
Version 0.1.0 (alpha) – Solo texto entrante/saliente.

## Tablas
- wp_aichat_connect_numbers: mapping telefono -> bot_slug
- wp_aichat_connect_messages: log de mensajes in/out + respuesta

## Flujo Entrante
1. Meta envía webhook a /wp-json/aichat-wa/v1/webhook (namespace REST pendiente de migrar a aichat-connect/v1)
2. Se valida el verify token (solo en GET inicial)
3. Se extrae el primer mensaje text
4. Se localiza bot por telefono
5. Se llama a `aichat_generate_bot_response()` (core Axiachat AI)
6. Se guarda log local + se envía la respuesta por la API de WhatsApp

## Configuración
En el menú "AI Chat Connect" (slugs admin `aichat-connect*`):
- Access Token (`aichat_connect_access_token`)
- Default Phone ID (`aichat_connect_default_phone_id`)
- Verify Token (`aichat_connect_verify_token`)

## Seguridad / Pendiente
- [ ] Verificación de firma X-Hub-Signature-256 (añadir setting app secret)
- [ ] Rate limiting específico por teléfono
- [ ] Comando de opt-out (ej: "STOP")
- [ ] Sanitizar longitud de respuesta antes de enviar (límite WA)

## Próximos Pasos
- Media (imagenes, audio)
- Estados de entrega (statuses)
- Reintentos exponenciales en errores 5xx
- Herramienta de prueba manual en admin
- Mapping múltiple (diferentes phone_id)

<!-- Sección de migración eliminada: ya migrado en tu instalación -->
## Desarrollo
Requiere el plugin principal Axiachat AI para usar el servicio `aichat`. El proveedor `ai-engine` funciona si el plugin AI Engine está activo.

## AIPKit Provider (New)
Use the chatbot module of the "GPT3 AI Content Generator" plugin (AIPKit). New endpoint format: `POST /wp-json/aipkit/v1/chat/{bot_id}/message` with `Authorization: Bearer <API_KEY>`. Legacy fallback (`POST /wp-json/aipkit/v1/chat` + body bot_id + aipkit_api_key) is still attempted automatically if the new path returns 404/400.

### How to use
1. Activate the AIPKit plugin (CPT `aipkit_chatbot` must exist).
2. Create or edit a chatbot and note its ID (column in the list or edit screen URL).
3. In "Providers" ensure the row with `provider_key = aipkit` exists and activate it.
4. (Optional) Set the AIPKit API key if required (field "AIPKit API Key"). It will be sent as Bearer header for the new endpoint or embedded in body for legacy fallback.
5. In "Mapeos" (Mappings) add/edit a line linking `phone` (Meta phone_number_id) with:
   - `bot_slug` = numeric AIPKit chatbot ID.
   - `service` = `aipkit`.
6. Incoming messages for that phone_number_id are sent to AIPKit and the `reply` is returned to the WhatsApp user.

### Notes
* Currently only the last user message is sent (extend with `aichat_connect_aipkit_payload` to inject history/system).
* Timeout / fast ack logic is reused like other providers.
* Error codes: `aipkit_missing`, `aipkit_http_error`, `aipkit_bad_response`, `aipkit_provider_missing`, `aipkit_loopback_failed`.
* Result array now includes `endpoint` => `new|legacy` and `fallback` => `internal` if internal dispatch was used.
* A 401 with code `rest_aipkit_invalid_api_key` triggers an automatic retry against the legacy endpoint embedding the key in the body.
* Filter `aichat_connect_aipkit_headers( array $headers, int $bot_id, array $args )` lets you inject custom headers (e.g., multi-tenant keys).

### Relevant filters
* `aichat_connect_aipkit_payload( array $payload, array $args )`: Adjust `messages`, add `system` content or context before the call.
* `aichat_connect_core_context_args( array $ctx, string $bot_slug, string $user_phone, ?string $business_phone_id )`: Extend data sent to AI Chat core.
* `aichat_connect_normalize_user_phone( string $normalized, string $raw )`: Override phone normalization used for `aichat_generate_bot_response_for_phone`.

### Quick example adding ad-hoc system context
```php
add_filter('aichat_connect_aipkit_payload', function($payload, $args){
	$payload['messages'] = array_merge([
		['role'=>'system','content'=>'Always answer in concise English.'],
	], $payload['messages']);
	return $payload;
}, 10, 2);
```

