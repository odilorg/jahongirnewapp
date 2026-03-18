<x-filament-panels::page>

    {{-- Download button --}}
    <div class="flex justify-end mb-4">
        <x-filament::button
            wire:click="downloadMarkdown"
            icon="heroicon-o-arrow-down-tray"
            color="info"
        >
            Download as Markdown (for AI)
        </x-filament::button>
    </div>

    {{-- Base URL --}}
    <x-filament::section heading="Base URL" icon="heroicon-o-globe-alt">
        <code class="block bg-gray-100 dark:bg-gray-800 p-3 rounded text-sm font-mono">{{ $baseUrl }}/{slug}/{action}</code>
        <p class="text-sm text-gray-500 mt-2">All requests require <code>X-Service-Key</code> header.</p>
    </x-filament::section>

    {{-- Available Bots --}}
    <x-filament::section heading="Available Bots" icon="heroicon-o-paper-airplane">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-gray-700">
                        <th class="py-2 px-3">Slug</th>
                        <th class="py-2 px-3">Name</th>
                        <th class="py-2 px-3">Username</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($bots as $bot)
                    <tr class="border-b dark:border-gray-700">
                        <td class="py-2 px-3"><code class="bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded">{{ $bot->slug }}</code></td>
                        <td class="py-2 px-3">{{ $bot->name }}</td>
                        <td class="py-2 px-3 text-gray-500">{{ $bot->bot_username ? '@'.$bot->bot_username : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Endpoints --}}
    <x-filament::section heading="Endpoints" icon="heroicon-o-code-bracket">
        <div class="space-y-3">
            @php
            $endpoints = [
                ['POST', '/{slug}/send-message', 'Send a text message', '{"chat_id": "12345", "text": "Hello", "parse_mode": "HTML"}'],
                ['POST', '/{slug}/send-photo', 'Send a photo', '{"chat_id": "12345", "photo": "url_or_file_id", "caption": "Optional"}'],
                ['GET', '/{slug}/get-me', 'Test bot connection', null],
                ['GET', '/{slug}/webhook-info', 'Get webhook status', null],
                ['POST', '/{slug}/set-webhook', 'Register webhook URL', '{"url": "https://your-app.com/webhook"}'],
                ['POST', '/{slug}/delete-webhook', 'Remove webhook', null],
            ];
            @endphp

            @foreach($endpoints as [$method, $path, $desc, $body])
            <div class="bg-gray-50 dark:bg-gray-800 rounded p-3">
                <div class="flex items-center gap-2 mb-1">
                    <span class="px-2 py-0.5 text-xs font-bold rounded {{ $method === 'GET' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' }}">{{ $method }}</span>
                    <code class="text-sm">{{ $path }}</code>
                </div>
                <p class="text-sm text-gray-500">{{ $desc }}</p>
                @if($body)
                <pre class="mt-2 bg-gray-100 dark:bg-gray-900 p-2 rounded text-xs overflow-x-auto">{{ $body }}</pre>
                @endif
            </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Quick Example --}}
    <x-filament::section heading="Quick Example (cURL)" icon="heroicon-o-command-line">
        <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded text-sm overflow-x-auto">curl -X POST {{ $baseUrl }}/owner-alert/send-message \
  -H "X-Service-Key: $BOT_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"chat_id": "12345", "text": "Hello!", "parse_mode": "HTML"}'</pre>
    </x-filament::section>

    {{-- Active API Keys --}}
    <x-filament::section heading="Active API Keys" icon="heroicon-o-key">
        @if($keys->isEmpty())
            <p class="text-sm text-gray-500">No active keys. <a href="{{ route('filament.admin.resources.telegram-service-keys.create') }}" class="text-primary-600 underline">Generate one</a></p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-gray-700">
                        <th class="py-2 px-3">Name</th>
                        <th class="py-2 px-3">Prefix</th>
                        <th class="py-2 px-3">Allowed Bots</th>
                        <th class="py-2 px-3">Allowed Actions</th>
                        <th class="py-2 px-3">Last Used</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($keys as $key)
                    <tr class="border-b dark:border-gray-700">
                        <td class="py-2 px-3 font-medium">{{ $key->name }}</td>
                        <td class="py-2 px-3"><code class="font-mono text-xs">{{ $key->key_prefix }}...</code></td>
                        <td class="py-2 px-3 text-gray-500">{{ $key->allowed_slugs ? implode(', ', $key->allowed_slugs) : 'All' }}</td>
                        <td class="py-2 px-3 text-gray-500">{{ $key->allowed_actions ? implode(', ', $key->allowed_actions) : 'All' }}</td>
                        <td class="py-2 px-3 text-gray-500">{{ $key->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>

    {{-- For AI Coders --}}
    <x-filament::section heading="For AI Coders" icon="heroicon-o-cpu-chip" collapsed>
        <p class="text-sm text-gray-500 mb-3">Copy this instruction block and paste it into your AI coding session:</p>
        <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded text-xs overflow-x-auto whitespace-pre-wrap">When you need to send a Telegram message, use this HTTP API. Do not create bots or store tokens.

POST {{ $baseUrl }}/{slug}/send-message
Headers: X-Service-Key: (from .env TELEGRAM_BOT_API_KEY), Content-Type: application/json
Body: {"chat_id": "CHAT_ID", "text": "MESSAGE", "parse_mode": "HTML"}

Available slugs: {{ $bots->pluck('slug')->join(', ') }}

Other endpoints: send-photo, get-me, webhook-info, set-webhook, delete-webhook

Store the API key in .env as TELEGRAM_BOT_API_KEY. Never hardcode it.</pre>
    </x-filament::section>

</x-filament-panels::page>
