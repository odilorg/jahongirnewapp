<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBotResource\Pages;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Exceptions\Telegram\TelegramApiException;
use App\Filament\Resources\TelegramBotResource;
use App\Models\TelegramBot;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTelegramBot extends ViewRecord
{
    protected static string $resource = TelegramBotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('testConnection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Test Bot Connection')
                ->modalDescription('Calls getMe on the Telegram API to verify the bot token is valid. If the bot @username has changed, it will be updated in the database.')
                ->action(function () {
                    $this->testConnection();
                }),

            Actions\Action::make('viewWebhookInfo')
                ->label('Webhook Info')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Fetch Webhook Info')
                ->modalDescription('Queries Telegram for the current webhook configuration of this bot. Read-only, no changes made.')
                ->action(function () {
                    $this->viewWebhookInfo();
                }),
        ];
    }

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
                $canJoinGroups = ($me['can_join_groups'] ?? false) ? 'Yes' : 'No';
                $canReadGroupMessages = ($me['can_read_all_group_messages'] ?? false) ? 'Yes' : 'No';

                Notification::make()
                    ->success()
                    ->title('Connection OK')
                    ->body(
                        "Bot: @{$username} ({$firstName})\n"
                        . "Can join groups: {$canJoinGroups}\n"
                        . "Can read group messages: {$canReadGroupMessages}"
                    )
                    ->persistent()
                    ->send();

                // Update bot_username if it changed
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
        } catch (TelegramApiException $e) {
            Notification::make()
                ->danger()
                ->title('Connection Error')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Resolution Error')
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
                $url = $info['url'] ?? '(not set)';
                $pending = $info['pending_update_count'] ?? 0;
                $hasCustomCert = ($info['has_custom_certificate'] ?? false) ? 'Yes' : 'No';
                $lastError = $info['last_error_message'] ?? 'None';
                $lastErrorDate = isset($info['last_error_date'])
                    ? date('Y-m-d H:i:s', $info['last_error_date'])
                    : 'Never';
                $maxConnections = $info['max_connections'] ?? 'Default';
                $ipAddress = $info['ip_address'] ?? 'N/A';

                Notification::make()
                    ->info()
                    ->title('Webhook Info')
                    ->body(
                        "URL: {$url}\n"
                        . "Pending updates: {$pending}\n"
                        . "Custom cert: {$hasCustomCert}\n"
                        . "IP: {$ipAddress}\n"
                        . "Max connections: {$maxConnections}\n"
                        . "Last error: {$lastError}\n"
                        . "Last error date: {$lastErrorDate}"
                    )
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->danger()
                    ->title('Webhook Info Failed')
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
}
