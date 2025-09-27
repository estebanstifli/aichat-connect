# AI Chat Connect Addon (WhatsApp)

Integración de WhatsApp (Meta Cloud API) para el plugin AI Chat. Este addon enruta mensajes entrantes a un bot configurado y responde automáticamente.

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
5. Se llama a `aichat_generate_bot_response()` (core)
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

## Migración desde prefijo antiguo (aichat_wa)
Si vienes de una versión previa con tablas `*_aichat_wa_*`, ejecuta estas consultas SQL (ajusta el prefijo wp_ si es distinto) ANTES de desinstalar la versión antigua. Haz copia de seguridad primero:

```sql
-- Números
INSERT INTO wp_aichat_connect_numbers (phone, bot_slug, service, display_name, access_token, is_active, created_at, updated_at)
SELECT phone, bot_slug, service, display_name, access_token, is_active, created_at, updated_at
FROM wp_aichat_wa_numbers
ON DUPLICATE KEY UPDATE
	bot_slug=VALUES(bot_slug), service=VALUES(service), display_name=VALUES(display_name), access_token=VALUES(access_token), is_active=VALUES(is_active), updated_at=VALUES(updated_at);

-- Providers (solo copiar configuraciones si no existen)
INSERT INTO wp_aichat_connect_providers (provider_key, name, description, is_active, timeout_ms, fast_ack_enabled, fast_ack_message, on_timeout_action, fallback_message, meta, created_at, updated_at)
SELECT provider_key, name, description, is_active, timeout_ms, fast_ack_enabled, fast_ack_message, on_timeout_action, fallback_message, meta, created_at, updated_at
FROM wp_aichat_wa_providers p
WHERE NOT EXISTS (SELECT 1 FROM wp_aichat_connect_providers c WHERE c.provider_key = p.provider_key);

-- Mensajes (evitar duplicados por wa_message_id)
INSERT INTO wp_aichat_connect_messages (wa_message_id, phone, direction, bot_slug, session_id, user_text, bot_response, status, meta, created_at)
SELECT wa_message_id, phone, direction, bot_slug, session_id, user_text, bot_response, status, meta, created_at
FROM wp_aichat_wa_messages m
WHERE NOT EXISTS (SELECT 1 FROM wp_aichat_connect_messages c WHERE c.wa_message_id = m.wa_message_id);
```

Luego puedes (opcional) eliminar las tablas antiguas:
```sql
DROP TABLE IF EXISTS wp_aichat_wa_messages;
DROP TABLE IF EXISTS wp_aichat_wa_numbers;
DROP TABLE IF EXISTS wp_aichat_wa_providers;
```

## Desarrollo
Requiere el plugin principal AI Chat para usar el servicio `aichat`. El proveedor `ai-engine` funciona si el plugin AI Engine está activo.
