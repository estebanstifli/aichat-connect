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

<!-- Sección de migración eliminada: ya migrado en tu instalación -->
## Desarrollo
Requiere el plugin principal AI Chat para usar el servicio `aichat`. El proveedor `ai-engine` funciona si el plugin AI Engine está activo.
