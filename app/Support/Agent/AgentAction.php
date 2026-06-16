<?php

declare(strict_types=1);

namespace App\Support\Agent;

/**
 * The closed allowlist of actions the tour-agent may request via agent:apply.
 *
 * Tier 1 = internal-only writes (no guest contact, no money) — executed behind
 *          --apply once approved.
 * Tier 2 = guest-facing / money — DESIGNED but execution-disabled this phase
 *          (gated by config('agent.sending_enabled'), which stays false).
 *
 * Anything not in this enum is rejected by the command before dispatch.
 */
enum AgentAction: string
{
    // ── Tier 1 (internal) ──────────────────────────────────────────────
    case AddNote = 'add_note';
    case MarkContacted = 'mark_contacted';
    case MarkAwaitingCustomer = 'mark_awaiting_customer';
    case MarkAwaitingPayment = 'mark_awaiting_payment';
    case Assign = 'assign';
    case MarkSpam = 'mark_spam';
    case MarkCancelled = 'mark_cancelled';

    // ── Tier 2 (guest-facing / money) — execution-disabled this phase ───
    case SendInitialReply = 'send_initial_reply';
    case SendOffer = 'send_offer';
    case GenerateAndSendPaymentLink = 'generate_and_send_payment_link';
    case ConfirmBooking = 'confirm_booking';
    case MarkPaidOffline = 'mark_paid_offline';

    public function tier(): int
    {
        return match ($this) {
            self::AddNote, self::MarkContacted, self::MarkAwaitingCustomer,
            self::MarkAwaitingPayment, self::Assign, self::MarkSpam,
            self::MarkCancelled => 1,
            default => 2,
        };
    }

    /** Tier-2 actions contact the guest or move money — gated OFF this phase. */
    public function isGuestFacing(): bool
    {
        return $this->tier() === 2;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
