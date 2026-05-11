<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'parent_id' => null,
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'display_name' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
