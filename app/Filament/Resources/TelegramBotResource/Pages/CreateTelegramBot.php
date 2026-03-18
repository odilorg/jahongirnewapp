<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBotResource\Pages;

use App\Enums\SecretStatus;
use App\Filament\Resources\TelegramBotResource;
use App\Models\TelegramBotSecret;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Crypt;

class CreateTelegramBot extends CreateRecord
{
    protected static string $resource = TelegramBotResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        // Remove token fields — they're handled in afterCreate
        unset($data['initial_token'], $data['initial_webhook_secret']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $token = $this->data['initial_token'] ?? null;
        $webhookSecret = $this->data['initial_webhook_secret'] ?? null;

        if (! $token) {
            return;
        }

        $secret = new TelegramBotSecret([
            'telegram_bot_id' => $this->record->id,
            'version' => 1,
            'status' => SecretStatus::Active,
            'activated_at' => now(),
            'created_by' => auth()->id(),
        ]);
        $secret->token_encrypted = Crypt::encryptString($token);

        if ($webhookSecret !== null && $webhookSecret !== '') {
            $secret->webhook_secret_encrypted = Crypt::encryptString($webhookSecret);
        }

        $secret->save();
    }
}
