<?php

namespace App\Filament\Tourfirm\Resources\ZayavkaResource\Pages;

use App\Filament\Tourfirm\Resources\ZayavkaResource;
use Filament\Actions;

use Filament\Resources\Pages\CreateRecord;



class CreateZayavka extends CreateRecord
{
    protected static string $resource = ZayavkaResource::class;
    public function getTitle(): string
    {
        return 'Создать Заявку'; // Custom title for the edit page
    }

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('create')
                ->label('Создать') // Change the "Create" button label
                ->action('create'),

            Actions\Action::make('createAndCreateAnother')
                ->label('Создать и создать другую') // Change the "Create & Create Another" button label
                ->action('createAndCreateAnother'),

            Actions\Action::make('cancel')
                ->label('Отменить') // Change the "Cancel" button label
                ->url($this->getResource()::getUrl('index')) // Redirect to index page
                ->color('secondary'),
        ];
    }
    public function getBreadcrumb(): string
    {
        return 'Создать Заявку'; // Custom breadcrumb for this page
    }

}
