<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\TelegramBot;
use App\Models\TelegramServiceKey;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Response;

/**
 * Auto-generated API docs page for the Telegram bot proxy API.
 * Shows endpoints, slugs, active keys, and copy-paste code snippets.
 * Includes a "Download as Markdown" action for feeding to AI coders.
 */
class TelegramApiDocs extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Telegram';
    protected static ?string $navigationLabel = 'API Docs';
    protected static ?int $navigationSort = 3;
    protected static ?string $title = 'Telegram Bot API Documentation';
    protected static string $view = 'filament.pages.telegram-api-docs';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasRole('super_admin');
    }

    public function getViewData(): array
    {
        $bots = TelegramBot::where('status', 'active')
            ->orderBy('slug')
            ->get(['slug', 'name', 'bot_username']);

        $keys = TelegramServiceKey::where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'key_prefix', 'allowed_slugs', 'allowed_actions', 'last_used_at', 'expires_at']);

        $baseUrl = rtrim(config('app.url'), '/') . '/api/internal/bots';

        return [
            'bots' => $bots,
            'keys' => $keys,
            'baseUrl' => $baseUrl,
        ];
    }

    public function downloadMarkdown()
    {
        $bots = TelegramBot::where('status', 'active')->orderBy('slug')->get(['slug', 'name', 'bot_username']);
        $baseUrl = rtrim(config('app.url'), '/') . '/api/internal/bots';

        $md = $this->generateMarkdown($bots, $baseUrl);

        return Response::make($md, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="telegram-bot-api-docs.md"',
        ]);
    }

    private function generateMarkdown($bots, string $baseUrl): string
    {
        $slugList = $bots->map(fn ($b) => "- `{$b->slug}` — {$b->name}" . ($b->bot_username ? " (@{$b->bot_username})" : ''))->join("\n");

        return <<<MD
# Telegram Bot Proxy API

Base URL: `{$baseUrl}`

All requests require `X-Service-Key` header. Store the key in `.env`, never hardcode it.

## Authentication

```
X-Service-Key: tgsk_your_key_here
Content-Type: application/json
```

## Available Bots

{$slugList}

## Endpoints

### Send Message

```
POST {$baseUrl}/{slug}/send-message

{
  "chat_id": "12345",
  "text": "Your message",
  "parse_mode": "HTML"
}
```

### Send Photo

```
POST {$baseUrl}/{slug}/send-photo

{
  "chat_id": "12345",
  "photo": "https://example.com/image.jpg",
  "caption": "Optional caption"
}
```

### Test Connection

```
GET {$baseUrl}/{slug}/get-me
```

### Webhook Info

```
GET {$baseUrl}/{slug}/webhook-info
```

### Set Webhook

```
POST {$baseUrl}/{slug}/set-webhook

{
  "url": "https://your-app.com/webhook"
}
```

### Delete Webhook

```
POST {$baseUrl}/{slug}/delete-webhook
```

## Response Format

Success:
```json
{
  "ok": true,
  "result": { "message_id": 123 },
  "description": null
}
```

Error:
```json
{
  "ok": false,
  "error": "resolution_error",
  "description": "Bot not found or disabled"
}
```

## Code Examples

### cURL

```bash
curl -X POST {$baseUrl}/owner-alert/send-message \\
  -H "X-Service-Key: \$BOT_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"chat_id": "12345", "text": "Hello", "parse_mode": "HTML"}'
```

### PHP (Laravel)

```php
Http::withHeaders([
    'X-Service-Key' => env('TELEGRAM_BOT_API_KEY'),
])->post('{$baseUrl}/owner-alert/send-message', [
    'chat_id' => '12345',
    'text' => 'Hello from Laravel',
    'parse_mode' => 'HTML',
]);
```

### Python

```python
import requests

requests.post(
    '{$baseUrl}/owner-alert/send-message',
    headers={'X-Service-Key': os.environ['TELEGRAM_BOT_API_KEY']},
    json={'chat_id': '12345', 'text': 'Hello from Python'}
)
```

### Node.js

```javascript
await fetch('{$baseUrl}/owner-alert/send-message', {
    method: 'POST',
    headers: {
        'X-Service-Key': process.env.TELEGRAM_BOT_API_KEY,
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({ chat_id: '12345', text: 'Hello from Node' }),
});
```

## Rate Limits

- 60 requests per minute per API key
- Rate limit resets every minute

## Security

- API key is hashed (SHA-256) — never stored in plaintext on the server
- Each key has an allowlist of bots and actions it can access
- All requests are audit-logged
- Bot tokens never leave the server — this API proxies to Telegram
MD;
    }
}
