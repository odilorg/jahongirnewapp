<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenMealCount extends Model
{
    protected $fillable = [
        'date',
        'meal_type',
        'total_expected',
        'total_adults',
        'total_children',
        'served_count',
    ];

    protected $casts = [
        'date' => 'date',
        'total_expected' => 'integer',
        'total_adults' => 'integer',
        'total_children' => 'integer',
        'served_count' => 'integer',
    ];

    public function remaining(): int
    {
        return max(0, $this->total_expected - $this->served_count);
    }

    public function incrementServed(int $count = 1): void
    {
        $this->increment('served_count', $count);
    }

    public function decrementServed(int $count = 1): void
    {
        $new = max(0, $this->served_count - $count);
        $this->update(['served_count' => $new]);
    }

    public static function forDate(string $date, string $mealType = 'breakfast'): ?self
    {
        return static::where('date', $date)->where('meal_type', $mealType)->first();
    }

    public static function getOrCreate(string $date, string $mealType = 'breakfast'): self
    {
        return static::firstOrCreate(
            ['date' => $date, 'meal_type' => $mealType],
            ['total_expected' => 0, 'total_adults' => 0, 'total_children' => 0, 'served_count' => 0]
        );
    }
}
