<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tour-agent guest egress master switch
    |--------------------------------------------------------------------------
    | Hard gate for ALL Tier-2 (guest-facing / money) agent actions. MUST stay
    | false through the draft-only training phase. When false, every Tier-2
    | action is refused by AgentActionDispatcher WITHOUT calling any sender.
    |
    | NOTE: the infra flag SEND_GUEST_MESSAGES is intentionally TRUE in prod
    | (human operators send WhatsApp via Filament), so it is NOT a backstop for
    | the agent. The agent's real second guarantee is that the Tier-2 send path
    | is not implemented at all (dispatcher returns tier2_not_implemented).
    */
    'sending_enabled' => env('AGENT_SENDING_ENABLED', false),

    /*
    | Label stamped on agent-authored internal notes and agent_action_log rows
    | so the audit trail clearly shows agent-origin vs operator-origin.
    */
    'actor' => env('AGENT_ACTOR', 'tour-agent'),
];
