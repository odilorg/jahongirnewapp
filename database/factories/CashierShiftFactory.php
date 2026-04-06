<?php

namespace Database\Factories;

use App\Models\CashDrawer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashierShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_drawer_id'  => CashDrawer::factory(),
            'user_id'         => User::factory(),
            'status'          => 'open',
            'opened_at'       => now(),
        ];
    }
}
