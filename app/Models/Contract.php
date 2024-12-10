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
            // Handle contract year based on the month
            $month = now()->month;
            $year = $month >= 11 ? now()->year + 1 : now()->year; // Use next year if it's November or December

            // Temporarily assign a placeholder number (needed for saving)
            $contract->number = 'TEMP';
        });

        static::created(function ($contract) {
            // Handle contract year based on the month
            $month = now()->month;
            $year = $month >= 11 ? now()->year + 1 : now()->year;

            // Generate the contract number using the actual ID
            $contract->number = "CON-$year-" . Str::padLeft($contract->id, 3, '0');

            // Save the updated contract number
            $contract->saveQuietly();

            // Dispatch the job to generate the contract PDF
            GenerateContractPdf::dispatch($contract);
        });
    }
}
