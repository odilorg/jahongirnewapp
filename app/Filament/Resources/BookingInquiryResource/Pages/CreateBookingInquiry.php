<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Resources\Pages\CreateRecord;

/**
 * Manual booking inquiry creation — for inquiries that didn't come
 * through the public website form (WhatsApp, phone, walk-in, etc.).
 *
 * Auto-fills the same provenance fields the API path sets:
 *   reference, submitted_at, ip_address, user_agent, status=new
 *
 * Operator gets credit for the entry via user_agent so the audit log
 * shows who created the row when it didn't come from the public site.
 */
class CreateBookingInquiry extends CreateRecord
{
    protected static string $resource = BookingInquiryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reference']    ??= BookingInquiry::generateReference();
        $data['source']       ??= 'manual';
        $data['status']       ??= BookingInquiry::STATUS_NEW;
        $data['submitted_at'] ??= now();
        $data['ip_address']   ??= '127.0.0.1';

        $operator = auth()->user();
        $data['user_agent'] ??= 'Filament admin'
            . ($operator ? ' (' . $operator->name . ')' : '');

        // Phase 15.1 — attribute to the operator who manually created this.
        // System-created inquiries (GYG, website, WhatsApp) have created_by = null.
        if ($operator) {
            $data['created_by_user_id']  ??= $operator->id;
            $data['assigned_to_user_id'] ??= $operator->id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // After save, drop the operator on the new inquiry's detail page
        // so they can immediately add a driver/guide/stays or fire a
        // WhatsApp reply.
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
