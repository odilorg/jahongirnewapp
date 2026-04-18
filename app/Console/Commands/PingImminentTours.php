<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Models\InquiryStay;
use App\Services\DriverDispatchNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 — T-1h supplier ping.
 *
 * Sends a short Telegram message to already-dispatched suppliers
 * (driver / guide / accommodation) approximately 1 hour before
 * pickup. Scheduler runs every 15 minutes; window is pickup_time
 * between now+45min and now+75min in Asia/Tashkent.
 *
 * Idempotent via markers in internal_notes — reruns within the
 * same window do not re-send.
 */
class PingImminentTours extends Command
{
    protected $signature   = 'supplier:ping-imminent-tours {--dry-run : Log without sending}';
    protected $description = 'T-1h Telegram ping to dispatched suppliers before departure';

    // Marker strings — CHECK + APPEND symmetry
    public const MARKER_DRIVER = 'T-1h driver ping sent';
    public const MARKER_GUIDE  = 'T-1h guide ping sent';
    public const MARKER_STAY   = 'T-1h stay ping sent';

    public function handle(DriverDispatchNotifier $dispatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $now       = Carbon::now('Asia/Tashkent');
        $lower     = $now->copy()->addMinutes(45);
        $upper     = $now->copy()->addMinutes(75);
        $today     = $now->toDateString();
        $lowerTime = $lower->format('H:i:s');
        $upperTime = $upper->format('H:i:s');

        $this->line("{$prefix}supplier:ping-imminent-tours · now={$now->format('Y-m-d H:i')} Tashkent");
        $this->line("{$prefix}Window: {$lowerTime}–{$upperTime} on {$today}");

        $inquiries = BookingInquiry::query()
            ->whereIn('status', [
                BookingInquiry::STATUS_CONFIRMED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ])
            ->whereDate('travel_date', $today)
            ->whereNotNull('pickup_time')
            ->whereBetween('pickup_time', [$lowerTime, $upperTime])
            ->with(['driver', 'guide', 'stays.accommodation'])
            ->get();

        $this->info("{$prefix}Found {$inquiries->count()} imminent booking(s) in window");
        Log::info('supplier:ping-imminent-tours: started', [
            'now'      => $now->toIso8601String(),
            'window'   => "{$lowerTime}–{$upperTime}",
            'found'    => $inquiries->count(),
            'dry_run'  => $dryRun,
        ]);

        $sent    = ['driver' => 0, 'guide' => 0, 'stay' => 0];
        $skipped = ['driver' => 0, 'guide' => 0, 'stay' => 0];

        foreach ($inquiries as $inquiry) {
            $this->line("{$prefix}→ {$inquiry->reference} · {$inquiry->customer_name} · pickup {$inquiry->pickup_time}");

            // Driver
            if ($inquiry->driver_id) {
                if ($this->markerExists($inquiry, self::MARKER_DRIVER)) {
                    $this->line("{$prefix}   🚗 driver: already pinged — skip");
                    $skipped['driver']++;
                } elseif (! $this->wasDriverDispatched($inquiry)) {
                    $this->line("{$prefix}   🚗 driver: not dispatched — skip");
                    $skipped['driver']++;
                } else {
                    if ($dryRun) {
                        $this->line("   🚗 driver: WOULD ping {$inquiry->driver->full_name}");
                        $sent['driver']++;
                    } else {
                        $result = $dispatcher->notifyImminent($inquiry, 'driver');
                        if ($result['ok'] ?? false) {
                            $this->appendMarker($inquiry, self::MARKER_DRIVER . " (msg_id={$result['msg_id']})");
                            $this->info("   🚗 driver: ✅ sent (msg_id={$result['msg_id']})");
                            $sent['driver']++;
                        } else {
                            $this->appendMarker($inquiry, '⚠️ T-1h driver ping FAILED: ' . ($result['reason'] ?? 'unknown'));
                            $this->error("   🚗 driver: ❌ failed — " . ($result['reason'] ?? 'unknown'));
                            $skipped['driver']++;
                        }
                    }
                }
            }

            // Guide
            if ($inquiry->guide_id) {
                if ($this->markerExists($inquiry, self::MARKER_GUIDE)) {
                    $this->line("{$prefix}   🧭 guide: already pinged — skip");
                    $skipped['guide']++;
                } elseif (! $this->wasGuideDispatched($inquiry)) {
                    $this->line("{$prefix}   🧭 guide: not dispatched — skip");
                    $skipped['guide']++;
                } else {
                    if ($dryRun) {
                        $this->line("   🧭 guide: WOULD ping {$inquiry->guide->full_name}");
                        $sent['guide']++;
                    } else {
                        $result = $dispatcher->notifyImminent($inquiry, 'guide');
                        if ($result['ok'] ?? false) {
                            $this->appendMarker($inquiry, self::MARKER_GUIDE . " (msg_id={$result['msg_id']})");
                            $this->info("   🧭 guide: ✅ sent (msg_id={$result['msg_id']})");
                            $sent['guide']++;
                        } else {
                            $this->appendMarker($inquiry, '⚠️ T-1h guide ping FAILED: ' . ($result['reason'] ?? 'unknown'));
                            $this->error("   🧭 guide: ❌ failed — " . ($result['reason'] ?? 'unknown'));
                            $skipped['guide']++;
                        }
                    }
                }
            }

            // Stays — conservative: only stays with stay_date = today that are dispatched
            foreach ($inquiry->stays as $stay) {
                if (! $stay->accommodation_id) {
                    continue;
                }
                if (! $stay->stay_date || $stay->stay_date->toDateString() !== $today) {
                    continue; // skip multi-night stays that aren't for today
                }

                $accName = $stay->accommodation?->name;
                if (! $accName) {
                    continue;
                }

                $stayMarker = self::MARKER_STAY . " for {$accName}";
                if ($this->markerExists($inquiry, $stayMarker)) {
                    $this->line("{$prefix}   🏕 stay {$accName}: already pinged — skip");
                    $skipped['stay']++;
                    continue;
                }

                if (! $this->wasStayDispatched($inquiry, $accName)) {
                    $this->line("{$prefix}   🏕 stay {$accName}: not dispatched — skip");
                    $skipped['stay']++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("   🏕 stay: WOULD ping {$accName}");
                    $sent['stay']++;
                } else {
                    $result = $dispatcher->notifyStayImminent($inquiry, $stay);
                    if ($result['ok'] ?? false) {
                        $this->appendMarker($inquiry, $stayMarker . " (msg_id={$result['msg_id']})");
                        $this->info("   🏕 stay {$accName}: ✅ sent (msg_id={$result['msg_id']})");
                        $sent['stay']++;
                    } else {
                        $this->appendMarker($inquiry, "⚠️ T-1h stay ping FAILED for {$accName}: " . ($result['reason'] ?? 'unknown'));
                        $this->error("   🏕 stay {$accName}: ❌ failed — " . ($result['reason'] ?? 'unknown'));
                        $skipped['stay']++;
                    }
                }
            }
        }

        $summary = sprintf(
            'Sent: driver=%d guide=%d stay=%d · Skipped: driver=%d guide=%d stay=%d',
            $sent['driver'], $sent['guide'], $sent['stay'],
            $skipped['driver'], $skipped['guide'], $skipped['stay'],
        );
        $this->info("{$prefix}{$summary}");
        Log::info('supplier:ping-imminent-tours: done', compact('sent', 'skipped'));

        return self::SUCCESS;
    }

    private function markerExists(BookingInquiry $inquiry, string $marker): bool
    {
        return str_contains((string) $inquiry->internal_notes, $marker);
    }

    private function appendMarker(BookingInquiry $inquiry, string $text): void
    {
        $timestamp = now()->format('Y-m-d H:i');
        $existing  = $inquiry->internal_notes ?? '';
        $separator = $existing ? "\n" : '';
        $inquiry->update([
            'internal_notes' => $existing . $separator . "[{$timestamp}] {$text}",
        ]);
    }

    /**
     * Same detection pattern as BookingInquiryObserver::wasDispatched
     * so behavior matches Phase 19.1 amendments.
     */
    private function wasDriverDispatched(BookingInquiry $inquiry): bool
    {
        return $this->matchDispatchMarkers((string) $inquiry->internal_notes, 'driver');
    }

    private function wasGuideDispatched(BookingInquiry $inquiry): bool
    {
        return $this->matchDispatchMarkers((string) $inquiry->internal_notes, 'guide');
    }

    private function matchDispatchMarkers(string $notes, string $role): bool
    {
        if ($notes === '') return false;

        $patterns = [
            "Calendar dispatch TG → {$role}",
            "Dispatch TG → {$role}",
            ucfirst($role) . ' dispatch sent',
            "{$role} dispatched",
        ];
        foreach ($patterns as $p) {
            if (str_contains($notes, $p) || str_contains(strtolower($notes), strtolower($p))) {
                return true;
            }
        }
        return false;
    }

    private function wasStayDispatched(BookingInquiry $inquiry, string $accName): bool
    {
        $notes = (string) $inquiry->internal_notes;
        if ($notes === '') return false;

        return str_contains($notes, "Calendar dispatch TG → stay {$accName}")
            || str_contains($notes, "dispatch TG → stay {$accName}")
            || preg_match('/dispatch.*stay.*' . preg_quote($accName, '/') . '/i', $notes) === 1;
    }
}
