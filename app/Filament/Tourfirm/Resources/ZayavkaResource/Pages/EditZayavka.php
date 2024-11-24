<?php

namespace App\Filament\Tourfirm\Resources\ZayavkaResource\Pages;

use App\Filament\Tourfirm\Resources\ZayavkaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditZayavka extends EditRecord
{
    protected static string $resource = ZayavkaResource::class;

    public function getTitle(): string
    {
        return 'Изменить Заявку'; // Custom title for the edit page
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Удалить'), // Correct way to handle delete action
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('save')
                ->label('Сохранить') // Change the "Save" button label
                ->action('save'),
        ];
    }

    public function getBreadcrumb(): string
    {
        return 'Изменить Заявку'; // Custom breadcrumb text
    }
}
