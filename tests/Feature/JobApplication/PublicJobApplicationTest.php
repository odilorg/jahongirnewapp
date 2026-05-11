<?php

declare(strict_types=1);

namespace Tests\Feature\JobApplication;

use App\Enums\HR\ApplicationStatus;
use App\Enums\HR\ExperienceLevel;
use App\Enums\HR\LanguageLevel;
use App\Enums\HR\Position;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\JobCandidate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1 (2026-05-11) — Public job application form
 * (`/jobs/apply`) end-to-end coverage.
 *
 * - Submits via the public POST endpoint (no auth)
 * - Asserts row created + correct casts
 * - Asserts owner-bot job dispatched (env-guard opt-in flag set)
 * - Asserts duplicate within 30d window silently dropped
 * - Asserts honeypot rejects without DB write
 * - Asserts file upload lands on private disk with safe filename
 * - Asserts invalid mime rejected
 */
final class PublicJobApplicationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Opt-in so OwnerAlertService::send() actually dispatches in
        // the testing env (the deployed env-guard would otherwise
        // suppress it). Bus::fake then intercepts the job for
        // assertion without anything reaching real Telegram.
        config([
            'services.owner_alert_bot.allow_outbound_in_testing' => true,
            'services.owner_alert_bot.owner_chat_id' => 12345,
            'app.url' => 'https://jahongir-app.uz',
        ]);

        Storage::fake('local');
        Bus::fake([SendTelegramNotificationJob::class]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Happy paths — one per position to prove the conditional answer routing
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function hotel_admin_application_creates_row_and_dispatches_alert(): void
    {
        $response = $this->post(route('jobs.apply.store'), $this->validPayload([
            'position' => Position::HotelAdmin->value,
            'position_answer' => 'yes',
        ]));

        $response->assertRedirect(route('jobs.apply.success'));

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(Position::HotelAdmin, $row->position);
        $this->assertSame(ApplicationStatus::New, $row->status);
        $this->assertSame(['hotel_reception_experience' => 'yes'], $row->position_answers);

        Bus::assertDispatched(SendTelegramNotificationJob::class);
    }

    /** @test */
    public function kitchen_application_routes_select_answer_to_kitchen_role_key(): void
    {
        $this->post(route('jobs.apply.store'), $this->validPayload([
            'position' => Position::Kitchen->value,
            'position_answer' => 'assistant_cook',
        ]))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertSame(Position::Kitchen, $row->position);
        $this->assertSame(['kitchen_role' => 'assistant_cook'], $row->position_answers);
    }

    /** @test */
    public function driver_application_routes_to_owns_car_key(): void
    {
        $this->post(route('jobs.apply.store'), $this->validPayload([
            'position' => Position::Driver->value,
            'position_answer' => 'yes',
        ]))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertSame(['owns_car' => 'yes'], $row->position_answers);
    }

    /** @test */
    public function other_position_accepts_free_text_answer(): void
    {
        $this->post(route('jobs.apply.store'), $this->validPayload([
            'position' => Position::Other->value,
            'position_answer' => 'IT системы / администратор',
        ]))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertSame(['desired_position' => 'IT системы / администратор'], $row->position_answers);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phone normalisation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function local_9_digit_phone_gets_prefixed_with_998(): void
    {
        $this->post(route('jobs.apply.store'), $this->validPayload([
            'phone' => '901234567',
        ]))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertSame('+998901234567', $row->phone);
    }

    /** @test */
    public function phone_with_spaces_and_dashes_is_normalised(): void
    {
        $this->post(route('jobs.apply.store'), $this->validPayload([
            'phone' => '+998 (90) 123-45-67',
        ]))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertSame('+998901234567', $row->phone);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Duplicate detection — silent drop
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function duplicate_phone_plus_position_within_30_days_silently_dropped(): void
    {
        $payload = $this->validPayload([
            'phone' => '+998901234567',
            'position' => Position::HotelAdmin->value,
        ]);

        $this->post(route('jobs.apply.store'), $payload)->assertRedirect(route('jobs.apply.success'));
        $this->post(route('jobs.apply.store'), $payload)->assertRedirect(route('jobs.apply.success'));

        $count = JobCandidate::query()
            ->where('phone', '+998901234567')
            ->where('position', Position::HotelAdmin->value)
            ->count();

        $this->assertSame(1, $count, 'duplicate within 30d must not create a second row');
    }

    /** @test */
    public function same_phone_different_position_creates_separate_row(): void
    {
        $base = $this->validPayload(['phone' => '+998901234567']);

        $this->post(route('jobs.apply.store'), array_merge($base, [
            'position' => Position::HotelAdmin->value,
            'position_answer' => 'yes',
        ]));
        $this->post(route('jobs.apply.store'), array_merge($base, [
            'position' => Position::Kitchen->value,
            'position_answer' => 'cook',
        ]));

        $this->assertSame(2, JobCandidate::query()->where('phone', '+998901234567')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Honeypot
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function honeypot_filled_results_in_silent_success_no_row_no_alert(): void
    {
        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['website' => 'https://spammer.example.com'],
        ))->assertRedirect(route('jobs.apply.success'));

        $this->assertSame(0, JobCandidate::query()->count());
        Bus::assertNotDispatched(SendTelegramNotificationJob::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File uploads
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function valid_pdf_cv_is_stored_under_private_uuid_path(): void
    {
        $cv = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['cv' => $cv],
        ))->assertRedirect();

        $row = JobCandidate::query()->latest('id')->first();
        $this->assertNotNull($row->cv_path);
        $this->assertStringStartsWith('candidates/'.now()->format('Y/m').'/', $row->cv_path);
        $this->assertStringEndsWith('.pdf', $row->cv_path);
        Storage::disk('local')->assertExists($row->cv_path);
    }

    /** @test */
    public function invalid_mime_rejected_with_validation_error(): void
    {
        $cv = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['cv' => $cv],
        ))->assertSessionHasErrors('cv');

        $this->assertSame(0, JobCandidate::query()->count());
    }

    /** @test */
    public function oversized_file_rejected(): void
    {
        // 6 MB — over the 5 MB limit
        $cv = UploadedFile::fake()->create('huge.pdf', 6_144, 'application/pdf');

        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['cv' => $cv],
        ))->assertSessionHasErrors('cv');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Source vocabulary sanitisation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function known_source_olx_stored_verbatim(): void
    {
        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['source' => 'olx'],
        ))->assertRedirect();

        $this->assertSame('olx', JobCandidate::query()->latest('id')->first()->source);
    }

    /** @test */
    public function unknown_source_collapsed_to_other(): void
    {
        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['source' => '<script>alert(1)</script>'],
        ))->assertRedirect();

        $this->assertSame('other', JobCandidate::query()->latest('id')->first()->source);
    }

    /** @test */
    public function missing_source_defaults_to_direct(): void
    {
        $payload = $this->validPayload();
        unset($payload['source']);

        $this->post(route('jobs.apply.store'), $payload)->assertRedirect();

        $this->assertSame('direct', JobCandidate::query()->latest('id')->first()->source);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Salary validation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function non_integer_salary_rejected_with_helpful_message(): void
    {
        $this->post(route('jobs.apply.store'), array_merge(
            $this->validPayload(),
            ['expected_salary_uzs' => 'договорная'],
        ))->assertSessionHasErrors('expected_salary_uzs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET — form renders with preselected position from URL
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_form_with_url_position_preselects_dropdown(): void
    {
        $response = $this->get('/jobs/apply?source=olx&position=hotel_admin');

        $response->assertOk();
        $response->assertSee('Подача заявки');
        // The preselected position name appears as the `selected` option
        $response->assertSee('Администратор', escape: false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $base = [
            'full_name' => 'Ivan Ivanov',
            'phone' => '+998901234567',
            'age' => 25,
            'city' => 'Самарканд',
            'position' => Position::HotelAdmin->value,
            'source' => 'olx',
            'expected_salary_uzs' => 3_000_000,
            'can_work_weekends' => '1',
            'can_work_nights' => '0',
            'experience_level' => ExperienceLevel::OneToThree->value,
            'uzbek_level' => LanguageLevel::Fluent->value,
            'russian_level' => LanguageLevel::Good->value,
            'english_level' => LanguageLevel::Basic->value,
            'position_answer' => 'yes',
        ];

        return array_merge($base, $overrides);
    }
}
