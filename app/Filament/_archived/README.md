# Archived Filament Resources

This folder holds Filament resources that are no longer used in the admin panel.

**Status:** Files moved out of `app/Filament/Resources/` so Filament no longer auto-discovers them. Models still exist at their original paths and may still be referenced by policies, jobs, or legacy code — do NOT delete the models until that code is cleaned up.

## How to restore a resource

```bash
git mv app/Filament/_archived/Resources/FooResource.php app/Filament/Resources/FooResource.php
git mv app/Filament/_archived/Resources/FooResource/ app/Filament/Resources/FooResource/
php artisan filament:cache-components
```

## Currently archived

| Resource | Archived | Model | Reason |
|---|---|---|---|
| ChatResource | 2026-04-17 | `App\Models\Chat` (kept, used by Booking.php + ChatPolicy) | Unused UI, 0 rows in table |
| ScheduledMessageResource | 2026-04-17 | `App\Models\ScheduledMessage` (kept, used by SendTelegramMessageJob + SendScheduledMessagesCommand) | Unused UI, 0 rows |
| TerminalCheckResource | 2026-04-17 | `App\Models\TerminalCheck` (kept, used by TerminalCheckPolicy) | Unused UI, 0 rows |

## Future cleanup

Full removal requires:
1. Delete Filament Resource files from `_archived/`
2. Delete Model files from `app/Models/`
3. Delete Policy files from `app/Policies/`
4. Rewrite or remove any jobs/commands/relations that still reference the model
5. Drop the DB tables via migration
