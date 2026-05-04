<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Models\ViatorInboundEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * V1 auto-apply: parsed `new` Viator emails → BookingInquiry rows.
 *
 * Amendments and cancellations are NOT auto-applied in V1 — they
 * stay flagged as needs_review for operator-driven reconciliation.
 * This is a deliberate governance choice: silent overwrite of
 * existing bookings is the failure mode this safeguard prevents.
 *
 * Catalog matching priority (per architectural decision):
 *   1. tour_grade_code → exact match on TourProductDirection.code
 *      (most specific Viator routing)
 *   2. product_code    → match against TourProduct.region or naming
 *      pattern (153457P2 = Samarkand city)
 *   3. tour_name       → fuzzy fallback against TourProduct.title
 *
 * If no catalog match: row still gets created (operator can link
 * later), but processing_status flips to needs_review so it surfaces
 * in the review queue.
 *
 * Idempotency: if external_reference already exists on a
 * BookingInquiry (e.g. operator inserted it manually before the
 * pipeline went live), we link to that row instead of creating a
 * duplicate. Critical for the BR-1390901059 case where the manual
 * insert happened first.
 */
class ViatorApplyNewBookings extends Command
{
    protected $signature   = 'viator:apply-new-bookings {--dry-run : Print what would be applied}';
    protected $description = 'Auto-apply parsed new-booking Viator emails to BookingInquiry';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = ViatorInboundEmail::query()
            ->where('email_type', ViatorInboundEmail::TYPE_NEW)
            ->where('processing_status', ViatorInboundEmail::STATUS_PARSED)
            ->whereNull('booking_inquiry_id')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No new bookings awaiting apply.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Will apply ' . $rows->count() . ' booking(s).');

        $created = 0;
        $linked  = 0;
        $review  = 0;
        $failed  = 0;

        foreach ($rows as $email) {
            try {
                if ($dryRun) {
                    $this->line(sprintf(
                        '  → %s · %s · %s',
                        $email->external_reference,
                        $email->parsed_payload['lead_traveler_name'] ?? '?',
                        $email->parsed_payload['travel_date']        ?? '?',
                    ));
                    continue;
                }

                $result = DB::transaction(fn () => $this->applyOne($email));
                match ($result) {
                    'created' => $created++,
                    'linked'  => $linked++,
                    'review'  => $review++,
                    default   => null,
                };
            } catch (\Throwable $e) {
                $failed++;
                $email->forceFill([
                    'processing_status' => ViatorInboundEmail::STATUS_FAILED,
                    'error_message'     => mb_substr($e->getMessage(), 0, 1000),
                    'processed_at'      => now(),
                ])->save();
                Log::error('ViatorApplyNewBookings: failed', [
                    'email_id' => $email->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->warn("  ✗ Email {$email->id}: " . $e->getMessage());
            }
        }

        $this->info("Done. Created: {$created}, Linked-to-existing: {$linked}, Sent to review: {$review}, Failed: {$failed}");
        return self::SUCCESS;
    }

    /**
     * @return 'created'|'linked'|'review'
     */
    private function applyOne(ViatorInboundEmail $email): string
    {
        $payload = $email->parsed_payload ?? [];
        $br      = $email->external_reference;

        if (! $br) {
            $email->forceFill([
                'processing_status' => ViatorInboundEmail::STATUS_NEEDS_REVIEW,
                'error_message'     => 'Missing external_reference',
                'processed_at'      => now(),
            ])->save();
            return 'review';
        }

        // Idempotency: same BR already an inquiry? Link, do not duplicate.
        $existing = BookingInquiry::where('external_reference', $br)->first();
        if ($existing) {
            $email->forceFill([
                'processing_status'   => ViatorInboundEmail::STATUS_APPLIED,
                'booking_inquiry_id'  => $existing->id,
                'processed_at'        => now(),
            ])->save();
            return 'linked';
        }

        $match = $this->matchCatalog($payload);

        $inquiry = BookingInquiry::create([
            'reference'                 => BookingInquiry::generateReference(),
            'source'                    => 'viator',
            'external_reference'        => $br,
            'tour_slug'                 => $match['slug'],
            'tour_name_snapshot'        => $payload['tour_name'] ?? 'Viator booking',
            'tour_product_id'           => $match['tour_product_id'],
            'tour_product_direction_id' => $match['direction_id'],
            'tour_type'                 => $this->guessTourType($payload),
            'customer_name'             => $payload['lead_traveler_name'] ?? 'Viator Guest',
            'customer_phone'            => (string) ($payload['phone'] ?? ''),
            'people_adults'             => (int) ($payload['people_adults']   ?? 1),
            'people_children'           => (int) ($payload['people_children'] ?? 0),
            'travel_date'               => $payload['travel_date'] ?? null,
            'pickup_time'               => $this->extractPickupTime($payload['tour_grade'] ?? null),
            'pickup_point'              => $payload['hotel_pickup'] ?? $payload['meeting_point'] ?? null,
            'price_quoted'              => $payload['net_rate_amount'] ?? null,
            'currency'                  => $payload['net_rate_currency'] ?? 'USD',
            'status'                    => BookingInquiry::STATUS_CONFIRMED,
            'prep_status'               => 'not_prepared',
            'confirmation_source'       => 'ota',
            'confirmed_at'              => now(),
            'submitted_at'              => now(),
            'paid_at'                   => now(), // Viator collects payment; we receive net.
            'message'                   => $this->buildAuditMessage($payload),
        ]);

        $email->forceFill([
            'processing_status'   => $match['tour_product_id']
                ? ViatorInboundEmail::STATUS_APPLIED
                : ViatorInboundEmail::STATUS_NEEDS_REVIEW,
            'booking_inquiry_id'  => $inquiry->id,
            'error_message'       => $match['tour_product_id']
                ? null
                : 'Could not match catalog product — operator review needed',
            'processed_at'        => now(),
        ])->save();

        return $match['tour_product_id'] ? 'created' : 'review';
    }

    /**
     * Catalog matcher per architectural priority:
     *   tour_grade_code > product_code > tour_name fuzzy
     *
     * @return array{tour_product_id: ?int, direction_id: ?int, slug: ?string}
     */
    private function matchCatalog(array $payload): array
    {
        $gradeCode  = (string) ($payload['tour_grade_code'] ?? '');
        $productCd  = (string) ($payload['product_code']    ?? '');
        $tourName   = (string) ($payload['tour_name']       ?? '');

        // 1. tour_grade_code on direction (rare to be set today, but
        //    the most specific signal once you start tagging directions
        //    with Viator codes).
        if ($gradeCode !== '') {
            $dir = TourProductDirection::where('code', $gradeCode)
                ->with('tourProduct:id,slug')
                ->first();
            if ($dir) {
                return [
                    'tour_product_id' => $dir->tour_product_id,
                    'direction_id'    => $dir->id,
                    'slug'            => $dir->tourProduct?->slug,
                ];
            }
        }

        // 2. product_code keyword heuristic — Viator's 153457PN format
        //    where N typically matches a product variant. Map by slug
        //    pattern (resilient to catalog ID drift across envs).
        //    P2 = Samarkand city, P1 = transport-only Shahrisabz,
        //    P5 = transfer (no catalog product yet — surfaces for review).
        $codeToSlugLike = [
            '153457P2' => 'samarkand-city',
            '153457P1' => 'shahrisabz',
            '153457P5' => null, // Transfer — operator-review path
        ];
        if ($productCd !== '' && array_key_exists($productCd, $codeToSlugLike)) {
            $slugLike = $codeToSlugLike[$productCd];
            if ($slugLike) {
                $product = TourProduct::where('slug', 'like', $slugLike . '%')
                    ->orWhere('slug', 'like', '%' . $slugLike . '%')
                    ->first();
                if ($product) {
                    $direction = TourProductDirection::where('tour_product_id', $product->id)->first();
                    return [
                        'tour_product_id' => $product->id,
                        'direction_id'    => $direction?->id,
                        'slug'            => $product->slug,
                    ];
                }
            }
        }

        // 3. tour_name fuzzy — last-ditch Levenshtein-ish via LIKE
        if ($tourName !== '') {
            $product = TourProduct::query()
                ->where('title', 'like', '%' . mb_substr($tourName, 0, 20) . '%')
                ->first();
            if ($product) {
                $direction = TourProductDirection::where('tour_product_id', $product->id)->first();
                return [
                    'tour_product_id' => $product->id,
                    'direction_id'    => $direction?->id,
                    'slug'            => $product->slug,
                ];
            }
        }

        return ['tour_product_id' => null, 'direction_id' => null, 'slug' => null];
    }

    private function guessTourType(array $payload): string
    {
        $grade = strtolower((string) ($payload['tour_grade'] ?? ''));
        if (str_contains($grade, 'private')) return 'private';
        if (str_contains($grade, 'group'))   return 'group';
        return 'private'; // safe default for Viator OTA bookings
    }

    private function extractPickupTime(?string $tourGrade): ?string
    {
        if (! $tourGrade) {
            return null;
        }
        if (preg_match('/(\d{2}:\d{2})/', $tourGrade, $m)) {
            return $m[1] . ':00';
        }
        return null;
    }

    private function buildAuditMessage(array $payload): string
    {
        $parts = ['Viator booking auto-imported via email pipeline.'];
        if (! empty($payload['product_code'])) {
            $parts[] = "Product: {$payload['product_code']}";
        }
        if (! empty($payload['tour_grade'])) {
            $parts[] = "Grade: {$payload['tour_grade']}";
        }
        if (! empty($payload['special_requirements']) && $payload['special_requirements'] !== 'No') {
            $parts[] = "Notes: {$payload['special_requirements']}";
        }
        return implode(' · ', $parts);
    }
}
