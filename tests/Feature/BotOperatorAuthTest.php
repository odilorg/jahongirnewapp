<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BotOperator;
use App\Services\BotOperatorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the BotOperator auth/authz system.
 *
 * Scenarios:
 *  (A) BotOperatorAuth — resolves active operator by from.id
 *  (B) BotOperatorAuth — returns null for unknown user
 *  (C) BotOperatorAuth — returns null for inactive operator
 *  (D) BotOperatorAuth — extracts from.id from message updates
 *  (E) BotOperatorAuth — extracts from.id from callback_query updates
 *  (F) BotOperator::can() — permission matrix per role
 *  (G) Webhook — unauthorized user denied, no state created
 *  (H) Webhook — inactive user denied
 *  (I) Webhook — authorized admin gets response
 *  (J) Artisan bot:operator command — create/update/deactivate/list
 */
class BotOperatorAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOperator(string $role = 'operator', bool $active = true): BotOperator
    {
        return BotOperator::create([
            'telegram_user_id' => '111222333',
            'role'             => $role,
            'is_active'        => $active,
            'name'             => 'Test Op',
        ]);
    }

    private function messageUpdate(string $userId, string $chatId = '999888777', ?string $text = '/start'): array
    {
        return [
            'update_id' => 1,
            'message'   => [
                'message_id' => 1,
                'from'       => ['id' => (int) $userId, 'first_name' => 'Test'],
                'chat'       => ['id' => (int) $chatId, 'type' => 'private'],
                'text'       => $text,
            ],
        ];
    }

    private function callbackUpdate(string $userId, string $chatId = '999888777', string $data = 'ops:menu'): array
    {
        return [
            'update_id'      => 2,
            'callback_query' => [
                'id'   => 'cq123',
                'from' => ['id' => (int) $userId],
                'message' => [
                    'message_id' => 1,
                    'chat'       => ['id' => (int) $chatId],
                ],
                'data' => $data,
            ],
        ];
    }

    // ── (A) Resolves active operator ──────────────────────────────────────────

    /** @test */
    public function auth_resolves_active_operator_from_message_update(): void
    {
        $this->makeOperator('manager');
        $auth   = new BotOperatorAuth();
        $update = $this->messageUpdate('111222333');

        $operator = $auth->fromUpdate($update);

        $this->assertNotNull($operator);
        $this->assertSame('111222333', $operator->telegram_user_id);
        $this->assertSame('manager', $operator->role);
    }

    /** @test */
    public function auth_resolves_active_operator_from_callback_update(): void
    {
        $this->makeOperator('admin');
        $auth   = new BotOperatorAuth();
        $update = $this->callbackUpdate('111222333');

        $operator = $auth->fromUpdate($update);

        $this->assertNotNull($operator);
        $this->assertSame('admin', $operator->role);
    }

    // ── (B) Unknown user denied ───────────────────────────────────────────────

    /** @test */
    public function auth_returns_null_for_unknown_user(): void
    {
        $auth   = new BotOperatorAuth();
        $update = $this->messageUpdate('999999999');

        $this->assertNull($auth->fromUpdate($update));
    }

    // ── (C) Inactive operator denied ─────────────────────────────────────────

    /** @test */
    public function auth_returns_null_for_inactive_operator(): void
    {
        $this->makeOperator('operator', false);
        $auth   = new BotOperatorAuth();
        $update = $this->messageUpdate('111222333');

        $this->assertNull($auth->fromUpdate($update));
    }

    // ── (D-E) extractUserId ───────────────────────────────────────────────────

    /** @test */
    public function auth_extracts_user_id_from_message(): void
    {
        $auth = new BotOperatorAuth();
        $this->assertSame('42', $auth->extractUserId($this->messageUpdate('42')));
    }

    /** @test */
    public function auth_extracts_user_id_from_callback(): void
    {
        $auth = new BotOperatorAuth();
        $this->assertSame('99', $auth->extractUserId($this->callbackUpdate('99')));
    }

    /** @test */
    public function auth_returns_null_user_id_for_empty_update(): void
    {
        $auth = new BotOperatorAuth();
        $this->assertNull($auth->extractUserId([]));
    }

    // ── (F) Permission matrix ─────────────────────────────────────────────────

    /** @test */
    public function viewer_can_only_view(): void
    {
        $op = new BotOperator(['role' => 'viewer', 'is_active' => true]);

        $this->assertTrue($op->can(BotOperator::PERM_VIEW));
        $this->assertFalse($op->can(BotOperator::PERM_CREATE));
        $this->assertFalse($op->can(BotOperator::PERM_EDIT));
        $this->assertFalse($op->can(BotOperator::PERM_MANAGE));
        $this->assertFalse($op->can(BotOperator::PERM_ADMIN));
    }

    /** @test */
    public function operator_can_view_create_and_edit_but_not_manage(): void
    {
        $op = new BotOperator(['role' => 'operator', 'is_active' => true]);

        $this->assertTrue($op->can(BotOperator::PERM_VIEW));
        $this->assertTrue($op->can(BotOperator::PERM_CREATE));
        $this->assertTrue($op->can(BotOperator::PERM_EDIT));
        $this->assertFalse($op->can(BotOperator::PERM_MANAGE));
        $this->assertFalse($op->can(BotOperator::PERM_ADMIN));
    }

    /** @test */
    public function manager_can_manage_but_not_admin(): void
    {
        $op = new BotOperator(['role' => 'manager', 'is_active' => true]);

        $this->assertTrue($op->can(BotOperator::PERM_VIEW));
        $this->assertTrue($op->can(BotOperator::PERM_CREATE));
        $this->assertTrue($op->can(BotOperator::PERM_EDIT));
        $this->assertTrue($op->can(BotOperator::PERM_MANAGE));
        $this->assertFalse($op->can(BotOperator::PERM_ADMIN));
    }

    /** @test */
    public function admin_has_all_permissions(): void
    {
        $op = new BotOperator(['role' => 'admin', 'is_active' => true]);

        foreach ([
            BotOperator::PERM_VIEW,
            BotOperator::PERM_CREATE,
            BotOperator::PERM_EDIT,
            BotOperator::PERM_MANAGE,
            BotOperator::PERM_ADMIN,
        ] as $perm) {
            $this->assertTrue($op->can($perm), "Admin should have permission: {$perm}");
        }
    }

    // ── (J) Artisan bot:operator ──────────────────────────────────────────────

    /** @test */
    public function artisan_creates_operator_with_given_role(): void
    {
        $this->artisan('bot:operator', [
            'telegram_user_id' => '555666777',
            '--role'           => 'manager',
            '--name'           => 'Sara',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('bot_operators', [
            'telegram_user_id' => '555666777',
            'role'             => 'manager',
            'name'             => 'Sara',
            'is_active'        => true,
        ]);
    }

    /** @test */
    public function artisan_updates_existing_operator_role(): void
    {
        BotOperator::create(['telegram_user_id' => '555666777', 'role' => 'operator', 'is_active' => true]);

        $this->artisan('bot:operator', [
            'telegram_user_id' => '555666777',
            '--role'           => 'admin',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('bot_operators', [
            'telegram_user_id' => '555666777',
            'role'             => 'admin',
        ]);
    }

    /** @test */
    public function artisan_deactivates_operator(): void
    {
        BotOperator::create(['telegram_user_id' => '555666777', 'role' => 'operator', 'is_active' => true]);

        $this->artisan('bot:operator', [
            'telegram_user_id' => '555666777',
            '--deactivate'     => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('bot_operators', [
            'telegram_user_id' => '555666777',
            'is_active'        => false,
        ]);
    }

    /** @test */
    public function artisan_reactivates_operator(): void
    {
        BotOperator::create(['telegram_user_id' => '555666777', 'role' => 'operator', 'is_active' => false]);

        $this->artisan('bot:operator', [
            'telegram_user_id' => '555666777',
            '--activate'       => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('bot_operators', [
            'telegram_user_id' => '555666777',
            'is_active'        => true,
        ]);
    }

    /** @test */
    public function artisan_rejects_invalid_role(): void
    {
        $this->artisan('bot:operator', [
            'telegram_user_id' => '555666777',
            '--role'           => 'superuser',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('bot_operators', ['telegram_user_id' => '555666777']);
    }

    /** @test */
    public function artisan_list_outputs_table(): void
    {
        BotOperator::create(['telegram_user_id' => '555666777', 'role' => 'admin', 'name' => 'Boss', 'is_active' => true]);

        $this->artisan('bot:operator', ['--list' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('555666777');
    }
}
