<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotOperator;
use Illuminate\Console\Command;

/**
 * Artisan command to bootstrap and manage @JahongirOpsBot operators.
 *
 * Usage examples:
 *   php artisan bot:operator 123456789 --role=admin --name="Jahongir"
 *   php artisan bot:operator 123456789 --role=manager --name="Sara"
 *   php artisan bot:operator 123456789 --deactivate
 *   php artisan bot:operator 123456789 --activate
 *   php artisan bot:operator --list
 */
class BotOperatorCommand extends Command
{
    protected $signature = 'bot:operator
        {telegram_user_id? : Telegram user ID (from.id) — omit when using --list}
        {--role=operator   : Role (admin|manager|operator|viewer)}
        {--name=           : Display name}
        {--username=       : Telegram @username (without @)}
        {--chat-id=        : Telegram chat ID for DMs (optional)}
        {--deactivate      : Deactivate this operator}
        {--activate        : Reactivate a deactivated operator}
        {--list            : List all registered operators}';

    protected $description = 'Create, update, activate, deactivate, or list @JahongirOpsBot operators';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listOperators();
        }

        $userId = $this->argument('telegram_user_id');

        if (! $userId) {
            $this->error('telegram_user_id is required unless --list is used.');
            return self::FAILURE;
        }

        if ($this->option('deactivate')) {
            return $this->setActive($userId, false);
        }

        if ($this->option('activate')) {
            return $this->setActive($userId, true);
        }

        return $this->upsertOperator($userId);
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    private function listOperators(): int
    {
        $operators = BotOperator::orderBy('role')->orderBy('name')->get();

        if ($operators->isEmpty()) {
            $this->info('No operators registered. Add the first one with:');
            $this->line('  php artisan bot:operator <telegram_user_id> --role=admin --name="Your Name"');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'User ID', 'Name', 'Username', 'Role', 'Active', 'Created'],
            $operators->map(fn (BotOperator $op) => [
                $op->id,
                $op->telegram_user_id,
                $op->name ?? '—',
                $op->username ? "@{$op->username}" : '—',
                $op->role,
                $op->is_active ? '✅' : '❌',
                $op->created_at?->format('Y-m-d') ?? '—',
            ]),
        );

        return self::SUCCESS;
    }

    private function setActive(string $userId, bool $active): int
    {
        $operator = BotOperator::where('telegram_user_id', $userId)->first();

        if (! $operator) {
            $this->error("Operator not found: {$userId}");
            return self::FAILURE;
        }

        $operator->update(['is_active' => $active]);
        $status = $active ? 'activated' : 'deactivated';
        $label  = $operator->name ?? $userId;
        $this->info("Operator '{$label}' ({$userId}) {$status}.");

        return self::SUCCESS;
    }

    private function upsertOperator(string $userId): int
    {
        $role = $this->option('role') ?? 'operator';

        if (! in_array($role, BotOperator::ROLES, true)) {
            $this->error("Invalid role '{$role}'. Allowed: " . implode(', ', BotOperator::ROLES));
            return self::FAILURE;
        }

        $data = ['role' => $role, 'is_active' => true];

        if ($name = $this->option('name')) {
            $data['name'] = $name;
        }
        if ($username = $this->option('username')) {
            $data['username'] = $username;
        }
        if ($chatId = $this->option('chat-id')) {
            $data['telegram_chat_id'] = $chatId;
        }

        $operator = BotOperator::updateOrCreate(
            ['telegram_user_id' => $userId],
            $data,
        );

        $action = $operator->wasRecentlyCreated ? 'Created' : 'Updated';
        $label  = $operator->name ?? $userId;
        $this->info("{$action} operator: '{$label}' ({$userId}) — role: {$role}");

        return self::SUCCESS;
    }
}
