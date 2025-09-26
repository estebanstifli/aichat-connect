# AI Chat WhatsApp Addon Roadmap

## Short Term (0.2.x)
- Implement X-Hub-Signature-256 verification (add app secret setting, HMAC SHA256 check)
- Outbound test utility in admin (enviar mensaje manual a un número)
- Per-phone rate limiting + transient based lock
- Opt-out / opt-in commands (STOP / START) con flag en tabla numbers
- Basic status handling for delivery receipts

## Mid Term (0.3.x)
- Media messages (imagenes → texto via caption / audio STT pipeline opcional)
- Multi-phone support (diferentes phone_id / business accounts)
- Reintentos diferidos (wp_cron) para respuestas fallidas
- Admin listing de mensajes con filtros
- Export CSV

## Longer Term (0.4.x+)
- Cola asíncrona (Action Scheduler) para alto volumen
- Integración con analytics (conversaciones, tasa de respuesta, tiempo medio)
- Plantillas pre-aprobadas (message templates) para iniciación outbound
- Flujos híbridos (primero menú opcional → chat libre)
- Detección de idioma y enrutado a bot específico

## Notas Técnicas
- Mantener dependencia mínima del core: solo usar `aichat_generate_bot_response` y hooks.
- Para media/STT considerar proveedor separado y almacenar resultado en meta.
