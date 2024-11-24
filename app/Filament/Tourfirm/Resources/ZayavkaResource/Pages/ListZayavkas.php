<?php

namespace App\Filament\Tourfirm\Resources\ZayavkaResource\Pages;

use App\Filament\Tourfirm\Resources\ZayavkaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction; // Use the new namespace



class ListZayavkas extends ListRecords
{
    protected static string $resource = ZayavkaResource::class;
    public function getTitle(): string
    {
        return 'Мои Заявки'; // Custom title for the edit page
    }
    

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Новая Заявка'), // Change the button label here

        ];
    }
    public function getBreadcrumb(): string
    {
        return 'Мои Заявки'; // Custom breadcrumb text
    }
    
}
