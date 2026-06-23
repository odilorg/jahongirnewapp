<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ListPendingWaCandidatesTest extends TestCase
{
    use DatabaseTransactions;

    private function candidate(string $status, string $phone): WaLeadCandidate
    {
        return WaLeadCandidate::create([
            'phone' => $phone, 'inbound_count' => 1, 'outbound_count' => 0,
            'status' => $status, 'first_inbound' => 'hi price for yurt camp',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function runJson(int $limit): array
    {
        $buffer = new BufferedOutput();
        Artisan::call('wa-leads:pending', ['--limit' => $limit], $buffer);

        return json_decode(trim($buffer->fetch()), true) ?? [];
    }

    public function test_emits_only_pending_candidates_as_json_and_writes_nothing(): void
    {
        $pending = $this->candidate(WaLeadCandidate::STATUS_PENDING, '998901112233');
        $this->candidate(WaLeadCandidate::STATUS_REVIEW, '998904445566');     // excluded
        $this->candidate(WaLeadCandidate::STATUS_DISMISSED, '998907778899');  // excluded

        $out = $this->runJson(10);

        $this->assertCount(1, $out);
        $this->assertSame($pending->id, $out[0]['id']);
        $this->assertSame('998901112233', $out[0]['phone']);
        $this->assertArrayHasKey('first_inbound', $out[0]);

        // read-only: nothing created/changed
        $this->assertSame(0, BookingInquiry::count());
        $this->assertSame(3, WaLeadCandidate::count());
        $this->assertSame(WaLeadCandidate::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_respects_limit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->candidate(WaLeadCandidate::STATUS_PENDING, '99890000000' . $i);
        }
        $this->assertCount(2, $this->runJson(2));
    }
}
