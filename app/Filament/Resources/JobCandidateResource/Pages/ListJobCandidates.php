<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobCandidateResource\Pages;

use App\Filament\Resources\JobCandidateResource;
use Filament\Resources\Pages\ListRecords;

class ListJobCandidates extends ListRecords
{
    protected static string $resource = JobCandidateResource::class;
}
