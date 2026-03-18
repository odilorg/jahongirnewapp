<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramBotResource\Pages;

use App\Filament\Resources\TelegramBotResource;
use Filament\Resources\Pages\ListRecords;

class ListTelegramBots extends ListRecords
{
    protected static string $resource = TelegramBotResource::class;
}
