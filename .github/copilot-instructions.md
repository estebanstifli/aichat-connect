# AI Chat WhatsApp Addon - Copilot Instructions

## Project Overview
This WordPress plugin acts as a WhatsApp integration addon for the main "AI Chat" plugin. It receives WhatsApp messages via Meta Cloud API webhooks and routes them to existing chat bots configured in the core plugin.

**Critical Dependency**: This addon requires the main AI Chat plugin to be active and expects the `aichat_generate_bot_response()` function to be available.

## Architecture Pattern
The codebase follows a layered service architecture with singleton classes:

- **Webhook Layer** (`AIChat_WA_Webhook`): Handles Meta webhook verification and incoming message processing
- **Service Layer** (`AIChat_WA_Service`): Business logic for message routing and bot interaction
- **Repository Layer** (`AIChat_WA_Repository`): Database operations and bot resolution logic
- **API Client** (`AIChat_WA_API_Client`): WhatsApp Graph API communication
- **Admin Interface** (`AIChat_WA_Admin`): WordPress admin panel integration

## Database Schema
Two custom tables manage the integration:
- `wp_aichat_wa_numbers`: Phone number → bot mapping with fallback chain
- `wp_aichat_wa_messages`: Message log with direction tracking (in/out)

## Bot Resolution Chain
The system uses a sophisticated fallback mechanism in `resolve_bot_slug()`:
1. Business phone_number_id mapping (for business accounts)
2. Explicit user phone mapping  
3. Wildcard '*' mapping (catch-all)
4. Global bot from core plugin settings

## Key Workflow Patterns

### Incoming Message Flow
1. Webhook receives POST to `/wp-json/aichat-wa/v1/webhook`
2. Extract first text message from Meta payload
3. Resolve bot using phone number fallback chain
4. Call core plugin: `aichat_generate_bot_response($bot_slug, $text, $session_id, ['source_channel' => 'whatsapp'])`
5. Log conversation and send response via Graph API

### Session Management
Session IDs are deterministic: `'wa_' . md5($phone)` - this ensures consistent conversation context per phone number.

### Error Handling Patterns
- Use `WP_Error` objects consistently for API failures
- Implement token blocking via transients to prevent API spam
- Log all operations to `wp_aichat_wa_messages` table for debugging

## Development Conventions

### Singleton Pattern
All main classes use this pattern:
```php
private static $instance;
public static function instance(){ 
    if(!self::$instance){ self::$instance = new self(); } 
    return self::$instance; 
}
```

### Debug Logging
Use the `AICHAT_WA_DEBUG` constant for conditional logging:
```php
if (defined('AICHAT_WA_DEBUG') && AICHAT_WA_DEBUG) {
    error_log('[AIChat-WA] Debug message here');
}
```

### Security Practices
- Always use `hash_equals()` for token comparison (timing attack prevention)
- Validate webhook verify tokens in GET requests
- TODO: Implement X-Hub-Signature-256 verification (see ROADMAP.md)

## REST API Endpoints
- `GET /wp-json/aichat-wa/v1/webhook` - Meta webhook verification
- `POST /wp-json/aichat-wa/v1/webhook` - Incoming message processing

## Configuration Settings
Stored as WordPress options:
- `aichat_wa_access_token` - Meta Graph API token
- `aichat_wa_default_phone_id` - Default business phone ID
- `aichat_wa_verify_token` - Webhook verification token

## Testing & Debugging
- Enable debug mode by setting `AICHAT_WA_DEBUG` to true in main plugin file
- Check WordPress error logs for detailed webhook processing information
- Use admin interface at "AI Chat > WhatsApp" to manage phone mappings
- Webhook URL for Meta configuration: `{site_url}/wp-json/aichat-wa/v1/webhook`

## Future Architecture Notes
- Current implementation is synchronous; consider Action Scheduler for high-volume scenarios
- Media message support will require additional MIME type handling in service layer
- Rate limiting should be implemented per phone number using WordPress transients