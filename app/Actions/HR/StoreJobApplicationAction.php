<?php

declare(strict_types=1);

namespace App\Actions\HR;

use App\Enums\HR\ApplicationStatus;
use App\Enums\HR\Position;
use App\Models\JobCandidate;
use App\Services\OwnerAlertService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists a validated public job application + side effects:
 *  - phone canonicalisation (strip whitespace, ensure +998 prefix
 *    if it looks like a 9-digit UZ local number)
 *  - duplicate detection (same phone + same position in last 30 days
 *    → silent no-op; caller renders the same "thanks" success page
 *    so spam bots can't probe for valid phone+position pairs)
 *  - CV upload to private disk under `candidates/{YYYY}/{MM}/{uuid}.{ext}`
 *  - position_answers JSON build from the single submitted value
 *  - owner-bot Telegram ping (subject to deployed env-guard)
 *
 * Phase 1, 2026-05-11. Lives here per CLAUDE.md hard line (controllers
 * stay thin; business logic in Actions).
 *
 * Returns the created JobCandidate or null when the submission was
 * silently dropped as a duplicate / honeypot hit.
 */
final class StoreJobApplicationAction
{
    public function __construct(
        private readonly OwnerAlertService $alertService,
    ) {}

    /**
     * @param  array<string,mixed>  $data  Validated payload from StoreJobApplicationRequest
     * @param  UploadedFile|null  $cvFile  Optional CV/photo upload
     * @param  array<string,string>  $audit  Keys: ip, user_agent
     * @return JobCandidate|null Null when the submission was a duplicate.
     */
    public function execute(array $data, ?UploadedFile $cvFile, array $audit): ?JobCandidate
    {
        $phone = $this->normalisePhone((string) $data['phone']);
        $waPhone = ! empty($data['whatsapp_phone'])
            ? $this->normalisePhone((string) $data['whatsapp_phone'])
            : null;
        $position = Position::from((string) $data['position']);

        // Dedup window — silent drop. Catches accidental double-submits
        // and naïve bot replays without leaking "this phone is already
        // in our system" information to scrapers.
        $existing = JobCandidate::query()
            ->where('phone', $phone)
            ->where('position', $position->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            Log::info('StoreJobApplicationAction: duplicate within 30d window, silently skipped', [
                'existing_id' => $existing->id,
                'phone' => $phone,
                'position' => $position->value,
            ]);

            return null;
        }

        $cvPath = $cvFile !== null ? $this->storeCv($cvFile) : null;

        $candidate = JobCandidate::create([
            'full_name' => trim((string) $data['full_name']),
            'phone' => $phone,
            'whatsapp_phone' => $waPhone,
            'age' => (int) $data['age'],
            'city' => trim((string) $data['city']),

            'position' => $position->value,
            'source' => $this->sanitiseSource($data['source'] ?? null),
            'source_reference' => $data['source_reference'] ?? null,

            'expected_salary_uzs' => (int) $data['expected_salary_uzs'],
            'available_from' => $data['available_from'] ?? null,
            // Checkbox defaults — unchecked = key absent from POST.
            'can_work_weekends' => (bool) ($data['can_work_weekends'] ?? false),
            'can_work_nights' => (bool) ($data['can_work_nights'] ?? false),

            'availability_slots' => array_values((array) ($data['availability_slots'] ?? [])),
            'is_currently_working' => (bool) ($data['is_currently_working'] ?? false),
            'is_currently_studying' => (bool) ($data['is_currently_studying'] ?? false),

            'experience_level' => $data['experience_level'],
            'previous_workplace_text' => $data['previous_workplace_text'] ?? null,
            'uzbek_level' => $data['uzbek_level'],
            'russian_level' => $data['russian_level'],
            'english_level' => $data['english_level'],

            'position_answers' => [
                $this->answerKeyFor($position) => (string) $data['position_answer'],
            ],

            'cv_path' => $cvPath,
            'status' => ApplicationStatus::New->value,

            'submitted_ip' => $audit['ip'] ?? null,
            'submitted_user_agent' => mb_substr((string) ($audit['user_agent'] ?? ''), 0, 255),
        ]);

        $this->alertService->alertNewJobApplication($candidate);

        return $candidate;
    }

    /**
     * Canonical phone form: strip whitespace + dashes + parens.
     * If the result is a 9-digit local number (90xxxxxxx etc.),
     * prefix +998. If it already starts with +998 or 998, normalise
     * to +998. Otherwise leave as-is (preserves international
     * numbers for the occasional non-UZ applicant).
     */
    private function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/[\s\-\(\)]+/', '', $raw) ?? $raw;

        if (str_starts_with($digits, '+998')) {
            return $digits;
        }
        if (str_starts_with($digits, '998') && strlen($digits) === 12) {
            return '+'.$digits;
        }
        if (preg_match('/^\d{9}$/', $digits)) {
            return '+998'.$digits;
        }

        return $digits;
    }

    /**
     * URL-supplied source is trusted but constrained to the known
     * vocabulary. Anything unrecognised collapses to "other" so a
     * crafted ?source=<script> can never reach the DB / TG message.
     */
    private function sanitiseSource(?string $raw): string
    {
        $allowed = ['olx', 'telegram', 'referral', 'walk_in', 'direct'];

        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return 'direct';
        }

        return in_array($value, $allowed, true) ? $value : 'other';
    }

    /**
     * Lookup key for the single position-specific answer stored in
     * `position_answers` JSON. Per-position so reports can `JSON_EXTRACT`
     * by stable name without inspecting the schema.
     */
    private function answerKeyFor(Position $position): string
    {
        return match ($position) {
            Position::HotelAdmin => 'hotel_reception_experience',
            Position::Kitchen => 'kitchen_role',
            Position::Housekeeping => 'housekeeping_experience',
            Position::Waiter => 'waiter_experience',
            Position::Cashier => 'cash_pos_experience',
            Position::Driver => 'owns_car',
            Position::Guide => 'has_license',
            Position::Other => 'desired_position',
        };
    }

    /**
     * Store CV under a uuid-named path on the private disk so the
     * filename is unguessable and never collides. Filament's
     * download action enforces auth.
     */
    private function storeCv(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid()->toString().'.'.$ext;
        $dir = 'candidates/'.now()->format('Y/m');

        return Storage::disk('local')->putFileAs($dir, $file, $filename);
    }
}
