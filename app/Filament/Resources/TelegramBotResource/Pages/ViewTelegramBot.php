<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBotResource\Pages;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Enums\BotStatus;
use App\Enums\SecretStatus;
use App\Exceptions\Telegram\TelegramApiException;
use App\Filament\Resources\TelegramBotResource;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Crypt;

class ViewTelegramBot extends ViewRecord
{
    protected static string $resource = TelegramBotResource::class;

    protected function getHeaderActions(): array
    {
        $isSuperAdmin = fn (): bool => auth()->user()?->hasRole('super_admin') ?? false;

        return [
            // ── Ops actions ──────────────────────────────

            Actions\Action::make('testConnection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->visible($isSuperAdmin)
                ->requiresConfirmation()
                ->modalHeading('Test Bot Connection')
                ->modalDescription('Calls getMe on the Telegram API to verify the bot token is valid. If the bot @username has changed, it will be updated in the database.')
                ->action(fn () => $this->testConnection()),

            Actions\Action::make('viewWebhookInfo')
                ->label('Webhook Info')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->visible($isSuperAdmin)
                ->requiresConfirmation()
                ->modalHeading('Fetch Webhook Info')
                ->modalDescription('Queries Telegram for the current webhook configuration of this bot. Read-only, no changes made.')
                ->action(fn () => $this->viewWebhookInfo()),

            // ── Secret lifecycle ─────────────────────────

            Actions\Action::make('rotateToken')
                ->label('Rotate Token')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible($isSuperAdmin)
                ->requiresConfirmation()
                ->modalHeading('Rotate Bot Token')
                ->modalDescription('Creates a new secret version with the provided token. The current active secret will be revoked. This is irreversible — make sure the new token is correct.')
                ->form([
                    Forms\Components\TextInput::make('new_token')
                        ->label('New Bot Token (from BotFather)')
                        ->required()
                        ->password()
                        ->revealable()
                        ->placeholder('123456:ABC-DEF...')
                        ->minLength(20)
                        ->maxLength(200),
                    Forms\Components\TextInput::make('new_webhook_secret')
                        ->label('New Webhook Secret (optional)')
                        ->password()
                        ->revealable()
                        ->placeholder('Leave empty to keep current or set none')
                        ->maxLength(256),
                ])
                ->action(function (array $data) {
                    $this->rotateToken($data);
                }),

            // ── Status lifecycle ─────────────────────────

            Actions\Action::make('disableBot')
                ->label('Disable')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->visible(fn () => $isSuperAdmin() && $this->getRecord()->status === BotStatus::Active)
                ->requiresConfirmation()
                ->modalHeading('Disable Bot')
                ->modalDescription('Disabling the bot will prevent all message sending and webhook processing. The bot can be re-enabled later.')
                ->action(fn () => $this->changeStatus(BotStatus::Disabled, 'Bot disabled')),

            Actions\Action::make('enableBot')
                ->label('Enable')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn () => $isSuperAdmin() && $this->getRecord()->status === BotStatus::Disabled)
                ->requiresConfirmation()
                ->modalHeading('Enable Bot')
                ->modalDescription('Re-enables the bot for normal operation.')
                ->action(fn () => $this->changeStatus(BotStatus::Active, 'Bot enabled')),

            Actions\Action::make('revokeBot')
                ->label('Revoke')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $isSuperAdmin() && $this->getRecord()->status !== BotStatus::Revoked)
                ->requiresConfirmation()
                ->modalHeading('Permanently Revoke Bot')
                ->modalDescription('This will permanently revoke the bot and all its secrets. This action CANNOT be undone. The bot will never be usable again.')
                ->action(fn () => $this->revokeBot()),
        ];
    }

    // ──────────────────────────────────────────────
    // Action implementations
    // ──────────────────────────────────────────────

    private function testConnection(): void
    {
        /** @var TelegramBot $bot */
        $bot = $this->getRecord();

        try {
            $resolver = app(BotResolverInterface::class);
            $transport = app(TelegramTransportInterface::class);

            $resolved = $resolver->resolve($bot->slug);
            $result = $transport->getMe($resolved);

            if ($result->succeeded()) {
                $me = $result->result;
                $username = $me['username'] ?? 'unknown';
                $firstName = $me['first_name'] ?? '';

                Notification::make()
                    ->success()
                    ->title('Connection OK')
                    ->body("Bot: @{$username} ({$firstName})")
                    ->persistent()
                    ->send();

                if ($bot->bot_username !== $username) {
                    $bot->update(['bot_username' => $username]);
                }
            } else {
                Notification::make()
                    ->danger()
                    ->title('Connection Failed')
                    ->body("HTTP {$result->httpStatus}: {$result->description}")
                    ->persistent()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Error')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function viewWebhookInfo(): void
    {
        /** @var TelegramBot $bot */
        $bot = $this->getRecord();

        try {
            $resolver = app(BotResolverInterface::class);
            $transport = app(TelegramTransportInterface::class);

            $resolved = $resolver->resolve($bot->slug);
            $result = $transport->getWebhookInfo($resolved);

            if ($result->succeeded()) {
                $info = $result->result;

                Notification::make()
                    ->info()
                    ->title('Webhook Info')
                    ->body(
                        "URL: " . ($info['url'] ?? '(not set)') . "\n"
                        . "Pending: " . ($info['pending_update_count'] ?? 0) . "\n"
                        . "IP: " . ($info['ip_address'] ?? 'N/A') . "\n"
                        . "Last error: " . ($info['last_error_message'] ?? 'None')
                    )
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->danger()
                    ->title('Failed')
                    ->body("HTTP {$result->httpStatus}: {$result->description}")
                    ->persistent()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Error')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function rotateToken(array $data): void
    {
        /** @var TelegramBot $bot */
        $bot = $this->getRecord();
        $newToken = $data['new_token'];
        $newWebhookSecret = $data['new_webhook_secret'] ?? null;

        // Revoke current active secret(s)
        $bot->secrets()->where('status', SecretStatus::Active)->each(function (TelegramBotSecret $secret) {
            $secret->markRevoked();
        });

        // Determine next version
        $nextVersion = ($bot->secrets()->max('version') ?? 0) + 1;

        // Create new secret
        $secret = new TelegramBotSecret([
            'telegram_bot_id' => $bot->id,
            'version' => $nextVersion,
            'status' => SecretStatus::Active,
            'activated_at' => now(),
            'created_by' => auth()->id(),
        ]);
        $secret->token_encrypted = Crypt::encryptString($newToken);

        if ($newWebhookSecret !== null && $newWebhookSecret !== '') {
            $secret->webhook_secret_encrypted = Crypt::encryptString($newWebhookSecret);
        }

        $secret->save();

        Notification::make()
            ->success()
            ->title('Token Rotated')
            ->body("New secret v{$nextVersion} is now active. Previous secret(s) revoked.")
            ->persistent()
            ->send();
    }

    private function changeStatus(BotStatus $newStatus, string $message): void
    {
        /** @var TelegramBot $bot */
        $bot = $this->getRecord();

        $bot->update([
            'status' => $newStatus,
            'updated_by' => auth()->id(),
        ]);

        Notification::make()
            ->success()
            ->title($message)
            ->body("Bot [{$bot->slug}] status changed to {$newStatus->label()}.")
            ->send();
    }

    private function revokeBot(): void
    {
        /** @var TelegramBot $bot */
        $bot = $this->getRecord();

        // Revoke all secrets
        $bot->secrets()->where('status', '!=', SecretStatus::Revoked)->each(function (TelegramBotSecret $secret) {
            $secret->markRevoked();
        });

        // Set bot status to revoked
        $bot->update([
            'status' => BotStatus::Revoked,
            'updated_by' => auth()->id(),
        ]);

        Notification::make()
            ->danger()
            ->title('Bot Permanently Revoked')
            ->body("Bot [{$bot->slug}] and all its secrets have been revoked. This cannot be undone.")
            ->persistent()
            ->send();
    }
}
