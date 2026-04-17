# Archived Filament Resources

This folder holds Filament resources that are no longer used in the admin panel.

**Status:** Files moved out of `app/Filament/Resources/` so Filament no longer auto-discovers them. Models still exist at their original paths and may still be referenced by policies, jobs, services, or legacy code — do NOT delete the models until that code is cleaned up.

## How to restore a resource

```bash
git mv app/Filament/_archived/Resources/FooResource.php app/Filament/Resources/FooResource.php
git mv app/Filament/_archived/Resources/FooResource/ app/Filament/Resources/FooResource/
php artisan filament:cache-components
```

## Currently archived

| Resource | Archived | Model | Notes |
|---|---|---|---|
| ChatResource | 2026-04-17 | `App\Models\Chat` | 0 rows, unused UI. Model referenced by ChatPolicy + Booking model. |
| ScheduledMessageResource | 2026-04-17 | `App\Models\ScheduledMessage` | 0 rows. Model used by SendTelegramMessageJob, SendScheduledMessagesCommand. |
| TerminalCheckResource | 2026-04-17 | `App\Models\TerminalCheck` | 0 rows. Model used by TerminalCheckPolicy. |
| BookingResource | 2026-04-17 | `App\Models\Booking` | 0 rows. Superseded by BookingInquiryResource. Legacy tour-booking system. Model heavily referenced — 16 files (controllers, services, observers, policies). |
| GuestResource | 2026-04-17 | `App\Models\Guest` | 0 rows. Superseded by customer fields on booking_inquiries. Legacy. |
| TourResource | 2026-04-17 | `App\Models\Tour` | 0 rows. Superseded by TourProductResource. Legacy. |
| ZayavkaResource | 2026-04-17 | `App\Models\Zayavka` | 0 rows. Russian booking form, superseded by BookingInquiryResource. |
| TourExpenseResource | 2026-04-17 | `App\Models\TourExpense` | 0 rows. Superseded by SupplierPaymentResource. |

## Also hidden (not archived — kept in Resources/ because model refs exist)

These are still in `app/Filament/` but hidden via `shouldRegisterNavigation = false`:
- `RatingResource` — depends on Booking model
- `BookingsReport` Filament Page — reports on legacy bookings table

## Future full cleanup (separate session)

1. Delete archived Filament Resource files (from `_archived/`)
2. Delete Model files from `app/Models/`
3. Delete Policy files from `app/Policies/`
4. Rewrite or remove every service/controller/job/relation that references each model
5. Drop DB tables via migration
6. Remove `use App\Models\Foo;` imports everywhere

Key files to audit when deep-cleaning:

**Booking model references:**
- `app/Policies/BookingPolicy.php`
- `app/Observers/BookingObserver.php`
- `app/Http/Controllers/WebhookController.php`
- `app/Http/Controllers/TelegramController.php`
- `app/Http/Controllers/OctoCallbackController.php`
- `app/Filament/Pages/BookingsReport.php`
- `app/Jobs/GenerateBookingPdf.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/OctoPaymentService.php`
- `app/Services/WebsiteBookingService.php`
- `app/Services/OperatorBookingFlow.php`
- `app/Services/StaffNotificationService.php`
- `app/Services/BookingOpsService.php`
- `app/Services/BookingBrowseService.php`
- `app/Models/GygInboundEmail.php` (belongsTo relation)
- `app/Filament/Resources/RatingResource.php`

**Guest model references:**
- `app/Policies/GuestPolicy.php`
- `app/Http/Controllers/WebhookController.php`
- `app/Http/Controllers/TelegramController.php`
- `app/Services/WebsiteBookingService.php`
- 3 BookingsRelationManager files (on Driver, Guest, Guide)

**Tour model references:**
- `app/Policies/TourPolicy.php`
- `app/Http/Controllers/TelegramController.php`
- `app/Filament/Resources/DriverResource.php`

**Zayavka references:**
- `app/Policies/ZayavkaPolicy.php`
- `app/Filament/Widgets/StatsOverview.php`
- `app/Filament/Tourfirm/Resources/ZayavkaResource.php` (separate Tourfirm panel)

**TourExpense references:**
- `app/Policies/TourExpensePolicy.php` (minimal)
