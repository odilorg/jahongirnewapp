<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramServiceKeyResource\Pages;

use App\Filament\Resources\TelegramServiceKeyResource;
use App\Models\TelegramServiceKey;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramServiceKey extends CreateRecord
{
    protected static string $resource = TelegramServiceKeyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $key = TelegramServiceKey::generateKey();

        $data['key_hash'] = $key['hash'];
        $data['key_prefix'] = $key['prefix'];
        $data['created_by'] = auth()->id();

        // Store plaintext temporarily to show after save
        $this->plaintextKey = $key['plaintext'];

        // Convert empty arrays to null (= allow all)
        if (empty($data['allowed_slugs'])) {
            $data['allowed_slugs'] = null;
        }
        if (empty($data['allowed_actions'])) {
            $data['allowed_actions'] = null;
        }

        return $data;
    }

    private string $plaintextKey = '';

    protected function afterCreate(): void
    {
        Notification::make()
            ->warning()
            ->title('Copy your API key — it will not be shown again')
            ->body($this->plaintextKey)
            ->persistent()
            ->send();
    }
}
