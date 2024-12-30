<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use Filament\Actions;
use Illuminate\Support\Carbon;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\ExpenseResource;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(),
            'Jahongir' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('hotel_id', 1)),
            'Premium' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('hotel_id', 2)),
            
'Break Jah' => Tab::make()
->modifyQueryUsing(fn (Builder $query) => 
    $query->whereHas('category', fn ($query) => 
        $query->where('name', 'Breakfast')
    )
    ->where('hotel_id', 1)
    ->whereMonth('created_at', Carbon::now()->month)
    ->whereYear('created_at', Carbon::now()->year)
),
'Break Pr' => Tab::make()
->modifyQueryUsing(fn (Builder $query) => 
    $query->whereHas('category', fn ($query) => 
        $query->where('name', 'Breakfast')
    )
    ->where('hotel_id', 2)
    ->whereMonth('created_at', Carbon::now()->month)
    ->whereYear('created_at', Carbon::now()->year)
),
             'Month Br Jah' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => 
                    $query->whereHas('category', fn ($query) => 
                        $query->where('name', 'Breakfast')
                    )
                    ->where('hotel_id', 1)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                ), 
                'Month Br Pr' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => 
                    $query->whereHas('category', fn ($query) => 
                        $query->where('name', 'Breakfast')
                    )
                    ->where('hotel_id', 2)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                ),  
        ];
    }

}
