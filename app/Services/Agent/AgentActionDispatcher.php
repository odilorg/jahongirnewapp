<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Actions\Inquiry\AppendInquiryNoteAction;
use App\Actions\Inquiry\AssignInquiryAction;
use App\Actions\Inquiry\TransitionInquiryStatusAction;
use App\Models\AgentActionLog;
use App\Models\BookingInquiry;
use App\Support\Agent\AgentAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tour-agent's HANDS (CRM side). Executes ONE pre-approved, structured
 * action against an inquiry by wrapping canonical Laravel Actions, enforcing
 * every guardrail in code (not by convention):
 *
 *   - approval token required for any write (--apply),
 *   - idempotency: a prior APPLIED row for the same key short-circuits,
 *   - Tier-2 (guest-facing / money) is refused while config('agent.sending_enabled')
 *     is false — no sender is ever called,
 *   - dry-run by default (simulate); --apply required to write,
 *   - every attempt (applied | simulated | refused | failed) is journaled to
 *     agent_action_log keyed by idempotency_key.
 *
 * The external runner never writes the DB — it only calls agent:apply, which
 * calls this dispatcher.
 */
class AgentActionDispatcher
{
    public function __construct(
        private readonly AppendInquiryNoteAction $appendNote,
        private readonly TransitionInquiryStatusAction $transition,
        private readonly AssignInquiryAction $assign,
    ) {}

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function dispatch(
        BookingInquiry $inquiry,
        AgentAction $action,
        array $params,
        string $actor,
        ?string $approvalToken,
        string $idempotencyKey,
        bool $apply,
    ): array {
        // 1. Writes require an approval token (minted by the Telegram approval bot).
        if ($apply && trim((string) $approvalToken) === '') {
            return $this->record($inquiry, $action, $params, $actor, $approvalToken, $idempotencyKey,
                AgentActionLog::STATUS_REFUSED, 'approval_token_required');
        }

        // 2. Idempotency: a prior successful apply for this key never re-runs.
        $prior = AgentActionLog::where('idempotency_key', $idempotencyKey)->first();
        if ($prior && $prior->status === AgentActionLog::STATUS_APPLIED) {
            return [
                'ok' => true,
                'status' => 'idempotent_replay',
                'action' => $action->value,
                'reason' => null,
                'log_id' => $prior->id,
                'inquiry' => $this->snapshot($inquiry->refresh()),
            ];
        }

        // 3. Tier-2 is execution-disabled this phase — refuse without calling any sender.
        if ($action->isGuestFacing()) {
            $reason = config('agent.sending_enabled') ? 'tier2_not_implemented' : 'sending_disabled';

            return $this->record($inquiry, $action, $params, $actor, $approvalToken, $idempotencyKey,
                AgentActionLog::STATUS_REFUSED, $reason);
        }

        // 4. Tier-1 internal action.
        try {
            if (! $apply) {
                $this->validateParams($inquiry, $action, $params); // dry validate, no writes
                return $this->record($inquiry, $action, $params, $actor, $approvalToken, $idempotencyKey,
                    AgentActionLog::STATUS_SIMULATED, null, ['would_apply' => true]);
            }

            $result = DB::transaction(fn (): array => $this->applyTier1($inquiry, $action, $params, $actor));

            return $this->record($inquiry->refresh(), $action, $params, $actor, $approvalToken, $idempotencyKey,
                AgentActionLog::STATUS_APPLIED, null, $result);
        } catch (ValidationException $e) {
            $msg = implode(' ', array_merge(...array_values($e->errors())));

            return $this->record($inquiry, $action, $params, $actor, $approvalToken, $idempotencyKey,
                AgentActionLog::STATUS_FAILED, 'validation_error', null, $msg);
        } catch (\Throwable $e) {
            return $this->record($inquiry, $action, $params, $actor, $approvalToken, $idempotencyKey,
                AgentActionLog::STATUS_FAILED, 'exception', null, $e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function applyTier1(BookingInquiry $inquiry, AgentAction $action, array $params, string $actor): array
    {
        switch ($action) {
            case AgentAction::AddNote:
                $this->appendNote->execute($inquiry, (string) ($params['note'] ?? ''), $actor);

                return ['note_appended' => true];

            case AgentAction::Assign:
                $this->assign->execute($inquiry, (int) ($params['user_id'] ?? 0));

                return ['assigned_to_user_id' => $inquiry->refresh()->assigned_to_user_id];

            case AgentAction::MarkContacted:
            case AgentAction::MarkAwaitingCustomer:
            case AgentAction::MarkAwaitingPayment:
            case AgentAction::MarkSpam:
            case AgentAction::MarkCancelled:
                $this->transition->execute($inquiry, self::targetStatus($action), $actor, $params['note'] ?? null);

                return ['status' => $inquiry->refresh()->status];

            default:
                // Unreachable: Tier-2 is filtered before this method.
                throw new \LogicException("applyTier1 reached with non-Tier-1 action: {$action->value}");
        }
    }

    /**
     * Dry-run validation: catch the obvious shape/transition errors during
     * simulate so the runner sees them before approval, without writing.
     *
     * @param  array<string,mixed>  $params
     */
    private function validateParams(BookingInquiry $inquiry, AgentAction $action, array $params): void
    {
        if ($action === AgentAction::AddNote && trim((string) ($params['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'Note text is required.']);
        }
        if ($action === AgentAction::Assign && (int) ($params['user_id'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['user_id' => 'A valid user_id is required.']);
        }
        if (in_array($action, [
            AgentAction::MarkContacted, AgentAction::MarkAwaitingCustomer,
            AgentAction::MarkAwaitingPayment, AgentAction::MarkSpam, AgentAction::MarkCancelled,
        ], true)) {
            $target = self::targetStatus($action);
            if ($inquiry->status !== $target) {
                $this->transition->assertCanTransition($inquiry, $target);
            }
        }
    }

    private static function targetStatus(AgentAction $action): string
    {
        return match ($action) {
            AgentAction::MarkContacted => BookingInquiry::STATUS_CONTACTED,
            AgentAction::MarkAwaitingCustomer => BookingInquiry::STATUS_AWAITING_CUSTOMER,
            AgentAction::MarkAwaitingPayment => BookingInquiry::STATUS_AWAITING_PAYMENT,
            AgentAction::MarkSpam => BookingInquiry::STATUS_SPAM,
            AgentAction::MarkCancelled => BookingInquiry::STATUS_CANCELLED,
            default => throw new \LogicException('targetStatus called with non-transition action'),
        };
    }

    /**
     * Upsert the audit/idempotency row and return the structured response.
     *
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>|null  $result
     * @return array<string,mixed>
     */
    private function record(
        BookingInquiry $inquiry,
        AgentAction $action,
        array $params,
        string $actor,
        ?string $approvalToken,
        string $idempotencyKey,
        string $status,
        ?string $reason,
        ?array $result = null,
        ?string $error = null,
    ): array {
        $log = AgentActionLog::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'booking_inquiry_id' => $inquiry->id,
                'action' => $action->value,
                'params' => $params,
                'actor' => $actor,
                'approval_token' => $approvalToken,
                'status' => $status,
                'reason' => $reason,
                'result' => $result,
                'error' => $error,
            ],
        );

        $ok = in_array($status, [AgentActionLog::STATUS_APPLIED, AgentActionLog::STATUS_SIMULATED], true);

        return [
            'ok' => $ok,
            'status' => $status,
            'action' => $action->value,
            'reason' => $reason,
            'error' => $error,
            'result' => $result,
            'log_id' => $log->id,
            'inquiry' => $this->snapshot($inquiry),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(BookingInquiry $inquiry): array
    {
        return [
            'id' => $inquiry->id,
            'reference' => $inquiry->reference,
            'status' => $inquiry->status,
            'prep_status' => $inquiry->prep_status,
            'assigned_to_user_id' => $inquiry->assigned_to_user_id,
        ];
    }
}
