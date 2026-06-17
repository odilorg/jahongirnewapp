<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\BookingInquiry;
use App\Services\Agent\InquiryQueueReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Contract tests for the M3 read-only poller queue. Load-bearing invariants:
 * only draftable leads are returned, no PII leaks, oldest-first.
 */
class InquiryQueueReaderTest extends TestCase
{
    use DatabaseTransactions;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'QT'.substr(uniqid(), -6); // short, isolates this test's rows from prod data
    }

    private function make(array $o = []): BookingInquiry
    {
        $i = BookingInquiry::create(array_merge([
            'reference' => $this->tag.substr(uniqid(), -6),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_NEW,
            'tour_name_snapshot' => 'Test Tour',
            'customer_name' => 'Secret Guest',
            'customer_phone' => '+998900000000',
            'customer_email' => 'secret@example.com',
            'people_adults' => 2,
            'people_children' => 1,
            'travel_date' => now()->addDays(20)->toDateString(),
            'submitted_at' => now(),
        ], $o));
        if (isset($o['created_at'])) {
            $i->forceFill(['created_at' => $o['created_at']])->save();
        }

        return $i;
    }

    private function mine(InquiryQueueReader $r, array $statuses = ['new'], bool $ota = true): array
    {
        // Filter the result down to rows created by THIS test (prod DB may hold others).
        $refs = array_map(
            fn ($x) => $x['reference'],
            array_filter($r->candidates($statuses, 200, $ota)['inquiries'],
                fn ($x) => str_starts_with((string) $x['reference'], $this->tag)),
        );

        return $refs;
    }

    public function test_returns_only_draftable_new_leads(): void
    {
        $keep = $this->make(['status' => 'new', 'source' => 'website']);
        $this->make(['status' => 'contacted']);
        $this->make(['status' => 'confirmed']);
        $this->make(['status' => 'cancelled']);
        $this->make(['status' => 'spam']);
        $this->make(['status' => 'awaiting_payment']);
        $this->make(['status' => 'new', 'travel_date' => now()->subDays(3)->toDateString()]); // past → excluded
        $this->make(['status' => 'new', 'source' => 'gyg']);   // OTA → excluded
        $this->make(['status' => 'new', 'source' => 'viator']); // OTA → excluded

        $refs = $this->mine(new InquiryQueueReader());
        $this->assertSame([$keep->reference], $refs, 'only the website new+future lead should remain');
    }

    public function test_undated_lead_is_included(): void
    {
        $u = $this->make(['status' => 'new', 'travel_date' => null]);
        $this->assertContains($u->reference, $this->mine(new InquiryQueueReader()));
    }

    public function test_exclude_ota_flag_toggles_gyg(): void
    {
        $g = $this->make(['status' => 'new', 'source' => 'gyg']);
        $this->assertNotContains($g->reference, $this->mine(new InquiryQueueReader(), ['new'], true));
        $this->assertContains($g->reference, $this->mine(new InquiryQueueReader(), ['new'], false));
    }

    public function test_oldest_first_order(): void
    {
        $old = $this->make(['created_at' => now()->subDays(5)]);
        $new = $this->make(['created_at' => now()->subDays(1)]);
        $refs = $this->mine(new InquiryQueueReader());
        $this->assertLessThan(array_search($new->reference, $refs, true), array_search($old->reference, $refs, true));
    }

    public function test_no_pii_in_output(): void
    {
        $this->make(['status' => 'new']);
        $row = collect((new InquiryQueueReader())->candidates(['new'], 200, true)['inquiries'])
            ->first(fn ($x) => str_starts_with((string) $x['reference'], $this->tag));

        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing(
            ['id', 'reference', 'source', 'status', 'created_at', 'updated_at', 'travel_date', 'party_size'],
            array_keys($row),
        );
        foreach (['customer_name', 'customer_phone', 'customer_email', 'name', 'phone', 'email'] as $pii) {
            $this->assertArrayNotHasKey($pii, $row);
        }
        $this->assertSame(3, $row['party_size']); // 2 adults + 1 child
    }

    public function test_command_emits_json_and_rejects_bad_status(): void
    {
        Artisan::call('agent:inquiry-queue', ['--statuses' => 'new', '--exclude-ota' => true, '--compact' => true]);
        $out = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($out);
        $this->assertArrayHasKey('inquiries', $out);

        $code = Artisan::call('agent:inquiry-queue', ['--statuses' => 'bogus', '--compact' => true]);
        $this->assertSame(1, $code);
    }
}
