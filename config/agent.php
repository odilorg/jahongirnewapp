<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tour-agent guest egress master switch
    |--------------------------------------------------------------------------
    | Hard gate for ALL Tier-2 (guest-facing / money) agent actions. MUST stay
    | false through the draft-only training phase. When false, every Tier-2
    | action is refused by AgentActionDispatcher WITHOUT calling any sender —
    | belt-and-suspenders with the infra-level SEND_GUEST_MESSAGES flag.
    */
    'sending_enabled' => env('AGENT_SENDING_ENABLED', false),

    /*
    | Label stamped on agent-authored internal notes and agent_action_log rows
    | so the audit trail clearly shows agent-origin vs operator-origin.
    */
    'actor' => env('AGENT_ACTOR', 'tour-agent'),
];
