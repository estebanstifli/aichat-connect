# AI Chat WhatsApp Addon Roadmap

## Short Term (0.2.x)
- Implement X-Hub-Signature-256 verification (add app secret setting, HMAC SHA256 check)
- Outbound test utility in admin (enviar mensaje manual a un nÃºmero)
- Per-phone rate limiting + transient based lock
- Opt-out / opt-in commands (STOP / START) con flag en tabla numbers
- Basic status handling for delivery receipts

## Mid Term (0.3.x)
- Media messages (imagenes -> texto via caption / audio STT pipeline opcional)
- Multi-phone support (diferentes phone_id / business accounts)
- Reintentos diferidos (wp_cron) para respuestas fallidas
- Admin listing de mensajes con filtros
- Export CSV

## Longer Term (0.4.x+)
- Cola asÃ­ncrona (Action Scheduler) para alto volumen
- IntegraciÃ³n con analytics (conversaciones, tasa de respuesta, tiempo medio)
- Plantillas pre-aprobadas (message templates) para iniciaciÃ³n outbound
- Flujos hÃ­bridos (primero menÃº opcional -> chat libre)
- DetecciÃ³n de idioma y enrutado a bot especÃ­fico

## Notas TÃ©cnicas
- Mantener dependencia mÃ­nima del core: solo usar `aichat_generate_bot_response` y hooks.
- Para media/STT considerar proveedor separado y almacenar resultado en meta.

