<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobCandidateResource\Pages;

use App\Filament\Resources\JobCandidateResource;
use Filament\Resources\Pages\EditRecord;

class EditJobCandidate extends EditRecord
{
    protected static string $resource = JobCandidateResource::class;
}
