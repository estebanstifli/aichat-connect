# AI Chat - WhatsApp Addon

Integración inicial de WhatsApp (Meta Cloud API) para el plugin AI Chat. Este add-on escucha mensajes entrantes y responde usando los bots configurados en el plugin principal.

## Estado
Version 0.1.0 (alpha) – Solo texto entrante saliente.

## Tablas
- wp_aichat_wa_numbers: mapping telefono -> bot_slug
- wp_aichat_wa_messages: log de mensajes in/out + respuesta

## Flujo Entrante
1. Meta envía webhook a /wp-json/aichat-wa/v1/webhook
2. Se valida el verify token (solo en GET inicial)
3. Se extrae el primer mensaje text
4. Se localiza bot por telefono
5. Se llama a `aichat_generate_bot_response()` (core)
6. Se guarda log local + se envía la respuesta por la API de WhatsApp

## Configuración
En Ajustes (submenu WhatsApp bajo AI Chat):
- Access Token
- Default Phone ID
- Verify Token

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

## Desarrollo
Este add-on requiere que el plugin principal AI Chat esté activo para exponer `aichat_generate_bot_response`.
