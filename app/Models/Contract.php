<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Jobs\GenerateContractPdf;

use Illuminate\Support\Str;

class Contract extends Model
{
    use HasFactory;
    public function turfirma(): BelongsTo
    {
        return $this->belongsTo(Turfirma::class);
    }


    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    protected $fillable = [
        'date',
        'number',
        'hotel_id',
        'turfirma_id',
        'file_name',
        'client_email', 
        'client_name', 
        'contract_title', 
        'contract_details', 
        'contract_number',
    ];

    protected static function booted()
    {
        static::creating(function ($contract) {
            // Generate a unique contract number
            $contract->number = 'CON' . Str::padLeft(
                (Contract::max('id') ?? 0) + 1,
                6,
                '0'
            );
        });

        static::created(function ($contract) {
            // Dispatch the job to generate the contract PDF
            GenerateContractPdf::dispatch($contract);
        });
    }


}
