<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftHandover extends Model
{
    protected $fillable = [
        'outgoing_shift_id', 'incoming_shift_id', 'cash_photo_path',
        'counted_uzs', 'counted_usd', 'counted_eur',
        'expected_uzs', 'expected_usd', 'expected_eur',
        'incoming_user_id', 'incoming_confirmed_at', 'discrepancy_notes',
        'owner_notified_at',
    ];

    protected $casts = [
        'counted_uzs' => 'decimal:2', 'counted_usd' => 'decimal:2', 'counted_eur' => 'decimal:2',
        'expected_uzs' => 'decimal:2', 'expected_usd' => 'decimal:2', 'expected_eur' => 'decimal:2',
        'incoming_confirmed_at' => 'datetime', 'owner_notified_at' => 'datetime',
    ];

    public function outgoingShift() { return $this->belongsTo(CashierShift::class, 'outgoing_shift_id'); }
    public function incomingShift() { return $this->belongsTo(CashierShift::class, 'incoming_shift_id'); }
    public function incomingUser() { return $this->belongsTo(User::class, 'incoming_user_id'); }

    public function hasDiscrepancy(): bool
    {
        return abs($this->counted_uzs - $this->expected_uzs) > 100
            || abs($this->counted_usd - $this->expected_usd) > 0.5
            || abs($this->counted_eur - $this->expected_eur) > 0.5;
    }
}
