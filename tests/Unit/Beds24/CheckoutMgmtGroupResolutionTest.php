<?php

declare(strict_types=1);

namespace Tests\Unit\Beds24;

use App\Http\Controllers\Beds24WebhookController;
use Tests\TestCase;

/**
 * Regression contract for the Beds24 checkout → housekeeping management
 * group routing. Locks the config-driven mapping that delivers
 * "🚪 Checkout!" notifications to operators.
 *
 * Real-world incident 2026-05-03:
 *   HOUSEKEEPING_PREMIUM_MGMT_GROUP_ID was empty for property 172793.
 *   The if-guard in notifyCleanersCheckout silently skipped the group
 *   dispatch — every Premium checkout for weeks queued only the
 *   personal HK sessions and never reached the management group.
 *
 * Locked invariants:
 *   - Property 172793 reads HOUSEKEEPING_PREMIUM_MGMT_GROUP_ID.
 *   - Any other property reads HOUSEKEEPING_MGMT_GROUP_ID.
 *   - Missing/empty config resolves to 0 — caller must warn-log
 *     instead of silently skipping.
 */
class CheckoutMgmtGroupResolutionTest extends TestCase
{
    public function test_premium_property_resolves_premium_group_id(): void
    {
        config()->set('services.housekeeping_bot.premium_mgmt_group_id', -1001806551666);
        config()->set('services.housekeeping_bot.mgmt_group_id', -1001234567890);

        $this->assertSame(
            -1001806551666,
            Beds24WebhookController::resolveCheckoutMgmtGroupId('172793'),
            'Premium property must read premium_mgmt_group_id'
        );
    }

    public function test_default_property_resolves_default_group_id(): void
    {
        config()->set('services.housekeeping_bot.premium_mgmt_group_id', -1001806551666);
        config()->set('services.housekeeping_bot.mgmt_group_id', -1001234567890);

        $this->assertSame(
            -1001234567890,
            Beds24WebhookController::resolveCheckoutMgmtGroupId('41097'),
            'Non-Premium property must read mgmt_group_id'
        );
    }

    public function test_premium_property_returns_zero_when_unset(): void
    {
        // Reproduces the 2026-05-03 incident config state.
        config()->set('services.housekeeping_bot.premium_mgmt_group_id', '');
        config()->set('services.housekeeping_bot.mgmt_group_id', -1001234567890);

        $this->assertSame(
            0,
            Beds24WebhookController::resolveCheckoutMgmtGroupId('172793'),
            'Empty premium config must resolve to 0 — caller is responsible for the warn-log fallthrough'
        );
    }

    public function test_default_property_returns_zero_when_unset(): void
    {
        config()->set('services.housekeeping_bot.mgmt_group_id', '');

        $this->assertSame(
            0,
            Beds24WebhookController::resolveCheckoutMgmtGroupId('41097'),
            'Empty default config must resolve to 0'
        );
    }

    public function test_string_value_is_coerced_to_int(): void
    {
        // .env values arrive as strings; controller relies on (int) cast.
        config()->set('services.housekeeping_bot.premium_mgmt_group_id', '-1001806551666');

        $this->assertSame(
            -1001806551666,
            Beds24WebhookController::resolveCheckoutMgmtGroupId('172793'),
        );
    }
}
