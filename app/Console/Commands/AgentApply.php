<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Agent\AgentActionDispatcher;
use App\Support\Agent\AgentAction;
use Illuminate\Console\Command;

/**
 * Tour-agent HANDS (Phase: draft-only). Executes ONE pre-approved structured
 * action against an inquiry by delegating to AgentActionDispatcher.
 *
 * Orchestration only (Principle 9) — all logic lives in the dispatcher/Actions.
 *
 * Dry-run is the DEFAULT. --apply is required to write. Tier-2 (guest-facing /
 * money) actions are refused while config('agent.sending_enabled') is false.
 *
 *   php artisan agent:apply 176 --action=mark_contacted --approval-token=abc --apply
 *   php artisan agent:apply 176 --action=add_note --params='{"note":"Asked for dates"}'
 *   php artisan agent:apply 176 --action=send_offer --apply   # -> refused: sending_disabled
 */
class AgentApply extends Command
{
    protected $signature = 'agent:apply
                            {inquiry : Inquiry id or reference (INQ-YYYY-NNNNNN)}
                            {--action= : One of the allowed agent actions}
                            {--params= : JSON object of action params, e.g. {"note":"..."}}
                            {--actor= : Override the actor label (default config agent.actor)}
                            {--approval-token= : Approval token minted by the review bot (required for --apply)}
                            {--idempotency-key= : Override the derived idempotency key}
                            {--apply : Persist the action (omit for dry-run/simulate; dry-run is the default)}';

    protected $description = 'Execute one approved tour-agent action on an inquiry (dry-run by default; Tier-2 disabled).';

    public function handle(AgentActionDispatcher $dispatcher): int
    {
        $key = (string) $this->argument('inquiry');
        $inquiry = BookingInquiry::when(
            ctype_digit($key),
            fn ($q) => $q->whereKey((int) $key),
            fn ($q) => $q->where('reference', $key),
        )->first();

        if ($inquiry === null) {
            return $this->fail("No booking inquiry found for: {$key}");
        }

        $action = AgentAction::tryFrom((string) $this->option('action'));
        if ($action === null) {
            return $this->fail('Unknown or missing --action. Allowed: '.implode(', ', AgentAction::values()));
        }

        $params = $this->decodeParams();
        if ($params === null) {
            return $this->fail('--params must be a JSON object.');
        }

        $actor = (string) ($this->option('actor') ?: config('agent.actor'));
        $token = (string) $this->option('approval-token');
        $apply = (bool) $this->option('apply');
        $idempotencyKey = (string) ($this->option('idempotency-key')
            ?: $inquiry->reference.':'.$action->value.':'.($token !== '' ? $token : 'no-token'));

        $result = $dispatcher->dispatch($inquiry, $action, $params, $actor, $token, $idempotencyKey, $apply);

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed>|null null = invalid JSON object */
    private function decodeParams(): ?array
    {
        $raw = (string) ($this->option('params') ?: '{}');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function fail(string $message): int
    {
        $this->line((string) json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
