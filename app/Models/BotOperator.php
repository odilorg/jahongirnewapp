<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Telegram user authorized to operate @JahongirOpsBot.
 *
 * Keyed on telegram_user_id (from.id), which is stable across chat types.
 * NOT keyed on chat_id — a single user can message the bot from different
 * chats (group, DM, etc.) and should have the same permissions everywhere.
 *
 * Role → permission matrix:
 *   viewer   → view
 *   operator → view + create + edit
 *   manager  → view + create + edit + manage
 *   admin    → view + create + edit + manage + admin
 */
class BotOperator extends Model
{
    // ── Permission constants ─────────────────────────────────────────────────

    /** Browse and view booking details. */
    public const PERM_VIEW = 'view';

    /** Create new manual bookings. */
    public const PERM_CREATE = 'create';

    /** Edit booking details, assign driver/guide, set price/pickup. */
    public const PERM_EDIT = 'edit';

    /** Confirm and cancel bookings. */
    public const PERM_MANAGE = 'manage';

    /** Manage bot operators (artisan command only — not surfaced in the bot). */
    public const PERM_ADMIN = 'admin';

    // ── Role constants ───────────────────────────────────────────────────────

    public const ROLES = ['admin', 'manager', 'operator', 'viewer'];

    private const ROLE_PERMISSIONS = [
        'viewer'   => [self::PERM_VIEW],
        'operator' => [self::PERM_VIEW, self::PERM_CREATE, self::PERM_EDIT],
        'manager'  => [self::PERM_VIEW, self::PERM_CREATE, self::PERM_EDIT, self::PERM_MANAGE],
        'admin'    => [self::PERM_VIEW, self::PERM_CREATE, self::PERM_EDIT, self::PERM_MANAGE, self::PERM_ADMIN],
    ];

    // ── Eloquent ─────────────────────────────────────────────────────────────

    protected $fillable = [
        'telegram_user_id',
        'telegram_chat_id',
        'role',
        'is_active',
        'name',
        'username',
    ];

    protected $casts = ['is_active' => 'boolean'];

    // ── Permission check ─────────────────────────────────────────────────────

    public function can(string $permission): bool
    {
        $perms = self::ROLE_PERMISSIONS[$this->role] ?? [];

        return in_array($permission, $perms, true);
    }
}
