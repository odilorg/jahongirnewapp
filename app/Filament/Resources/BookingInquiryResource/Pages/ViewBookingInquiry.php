<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Actions\Feedback\SendManualTripAdvisorReviewRequestAction;
use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBookingInquiry extends ViewRecord
{
    protected static string $resource = BookingInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // Manual TripAdvisor review request (Phase 1.7.0).
            // Auto-cron was disabled by business decision — operators
            // pick happy guests by hand. Visible only when:
            //   - inquiry is confirmed AND not cancelled
            //   - either phone or email is on file (else nothing to send)
            //   - actor is super_admin / admin / manager
            // Repeat sends are allowed but force a confirmation modal
            // that surfaces the prior send timestamp.
            Actions\Action::make('sendTripAdvisorRequest')
                ->label('🌟 Send TripAdvisor Review Request')
                ->color('info')
                ->icon('heroicon-o-star')
                ->visible(fn (BookingInquiry $record): bool => $this->canSendReviewRequest($record))
                ->requiresConfirmation()
                ->modalHeading('Send TripAdvisor review request')
                ->modalDescription(function (BookingInquiry $record): string {
                    if ($record->review_request_sent_at) {
                        $when = $record->review_request_sent_at->format('d M Y H:i');
                        return "⚠ A review request was already sent on {$when}. Send again to {$record->customer_name}?";
                    }
                    return "Send TripAdvisor review request to {$record->customer_name}?";
                })
                ->modalSubmitActionLabel('Send')
                ->action(fn (BookingInquiry $record) => $this->dispatchTripAdvisorRequest($record)),
        ];
    }

    /**
     * Lifted out of the Filament action closure to keep that closure
     * thin (CLAUDE.md hard line — no business logic past ~10 LOC inside
     * Filament closures). All actual send logic lives in the Action;
     * this method only translates its result into operator notifications.
     */
    private function dispatchTripAdvisorRequest(BookingInquiry $record): void
    {
        $result = app(SendManualTripAdvisorReviewRequestAction::class)
            ->execute($record, auth()->id());

        if ($result['sent']) {
            Notification::make()
                ->success()
                ->title('Review request sent')
                ->body("Channel: {$result['channel']} · {$record->customer_name}")
                ->send();
            return;
        }

        Notification::make()
            ->danger()
            ->title('Send failed — not stamped')
            ->body((string) ($result['reason'] ?? 'Unknown error'))
            ->persistent()
            ->send();
    }

    private function canSendReviewRequest(BookingInquiry $record): bool
    {
        $user = auth()->user();
        if (! $user || ! $user->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            return false;
        }
        if ($record->status !== BookingInquiry::STATUS_CONFIRMED) {
            return false;
        }
        if ($record->cancelled_at !== null) {
            return false;
        }
        // Need at least one channel on file
        return filled($record->customer_phone) || filled($record->customer_email);
    }
}
