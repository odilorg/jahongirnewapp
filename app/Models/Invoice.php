<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'invoice_date',
        'hotel_id',
        'turfirma_id',
        'zayavka_id',
        'contract_id',
        'status',
        'amount'
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function turfirma(): BelongsTo
    {
        return $this->belongsTo(Turfirma::class);
    }

    public function zayavka(): BelongsTo
    {
        return $this->belongsTo(Zayavka::class);
    }
}
