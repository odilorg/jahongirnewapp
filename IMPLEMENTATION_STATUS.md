# POS Telegram Bot - Manager Reports Implementation Status

## ✅ COMPLETED

### Phase 1: Core Services (DONE)
1. **TelegramReportService.php** - Created and uploaded ✅
   - Location: `/var/www/jahongirnewapp/app/Services/`
   - All report methods implemented:
     - `getTodaySummary()`
     - `getShiftPerformance()`
     - `getShiftDetail()`
     - `getTransactionActivity()`
     - `getMultiLocationSummary()`

2. **TelegramReportFormatter.php** - Created and uploaded ✅
   - Location: `/var/www/jahongirnewapp/app/Services/`
   - All formatting methods implemented:
     - `formatTodaySummary()`
     - `formatShiftPerformance()`
     - `formatShiftDetail()`
     - `formatTransactionActivity()`
     - `formatMultiLocationSummary()`

---

## 🚧 TODO - Remaining Steps

### Step 1: Update TelegramKeyboardBuilder.php
Add these methods to `/var/www/jahongirnewapp/app/Services/TelegramKeyboardBuilder.php`:

```php
/**
 * Build manager reports keyboard (for managers only)
 */
public function managerReportsKeyboard(string $language = 'en'): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => __('telegram_pos.today_summary', [], $language), 'callback_data' => 'report:today'],
            ],
            [
                ['text' => __('telegram_pos.shift_performance', [], $language), 'callback_data' => 'report:shifts'],
            ],
            [
                ['text' => __('telegram_pos.transaction_report', [], $language), 'callback_data' => 'report:transactions'],
            ],
            [
                ['text' => __('telegram_pos.multi_location', [], $language), 'callback_data' => 'report:locations'],
            ],
            [
                ['text' => __('telegram_pos.back', [], $language), 'callback_data' => 'report:back'],
            ],
        ],
    ];
}
```

### Step 2: Modify mainMenuKeyboard in Same File
Replace the `mainMenuKeyboard` method:

```php
public function mainMenuKeyboard(string $language = 'en', ?\App\Models\User $user = null): array
{
    $buttons = [
        [
            ['text' => __('telegram_pos.start_shift', [], $language)],
            ['text' => __('telegram_pos.my_shift', [], $language)],
        ],
        [
            ['text' => __('telegram_pos.record_transaction', [], $language)],
            ['text' => __('telegram_pos.close_shift', [], $language)],
        ],
    ];

    // Add Reports button for managers
    if ($user && $user->hasAnyRole(['manager', 'super_admin'])) {
        $buttons[] = [
            ['text' => __('telegram_pos.reports', [], $language)],
        ];
    }

    $buttons[] = [
        ['text' => __('telegram_pos.help', [], $language)],
        ['text' => __('telegram_pos.settings', [], $language)],
    ];

    return [
        'keyboard' => $buttons,
        'resize_keyboard' => true,
    ];
}
```

### Step 3: Update TelegramPosController.php
Add at top with other services:

```php
protected TelegramReportService $reportService;
protected TelegramReportFormatter $reportFormatter;
```

Update constructor:

```php
public function __construct(
    protected TelegramPosService $posService,
    protected TelegramMessageFormatter $formatter,
    protected TelegramKeyboardBuilder $keyboard,
    protected StartShiftAction $startShiftAction,
    protected CloseShiftAction $closeShiftAction,
    protected RecordTransactionAction $recordTransactionAction,
    TelegramReportService $reportService,  // ADD THIS
    TelegramReportFormatter $reportFormatter  // ADD THIS
) {
    $this->botToken = config('services.telegram_pos_bot.token');
    $this->reportService = $reportService;  // ADD THIS
    $this->reportFormatter = $reportFormatter;  // ADD THIS
}
```

Add to `processMessage()` method switch statement:

```php
case '📊 reports':
case '📊 hisobotlar':
case '📊 отчеты':
    return $this->showReportsMenu($chatId, $session);
```

Add to `handleCallbackQuery()` method:

```php
// Handle report callbacks
if (str_starts_with($callbackData, 'report:')) {
    return $this->handleReportCallback($session, $callbackData, $chatId);
}
```

Add new methods at end of controller:

```php
/**
 * Show reports menu (manager only)
 */
protected function showReportsMenu(int $chatId, $session)
{
    if (!$session || !$session->user_id) {
        $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.unauthorized'), 'en'));
        return response('OK');
    }

    $lang = $session->language;
    $user = $session->user;

    // Check if user is manager
    if (!$user->hasAnyRole(['manager', 'super_admin'])) {
        $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.manager_only'), $lang));
        return response('OK');
    }

    $this->sendMessage(
        $chatId,
        __('telegram_pos.select_report_type', [], $lang),
        $this->keyboard->managerReportsKeyboard($lang),
        'inline'
    );

    return response('OK');
}

/**
 * Handle report callback
 */
protected function handleReportCallback($session, string $callbackData, int $chatId)
{
    $lang = $session->language;
    $user = $session->user;

    $reportType = substr($callbackData, 7); // Remove 'report:'

    switch ($reportType) {
        case 'today':
            return $this->handleTodayReport($chatId, $user, $lang);
        case 'shifts':
            return $this->handleShiftsReport($chatId, $user, $lang);
        case 'transactions':
            return $this->handleTransactionsReport($chatId, $user, $lang);
        case 'locations':
            return $this->handleLocationsReport($chatId, $user, $lang);
        case 'back':
            $this->sendMessage($chatId, __('telegram_pos.main_menu', [], $lang), $this->keyboard->mainMenuKeyboard($lang, $user));
            break;
    }

    return response('OK');
}

protected function handleTodayReport(int $chatId, $user, string $lang)
{
    $data = $this->reportService->getTodaySummary($user);

    if (isset($data['error'])) {
        $this->sendMessage($chatId, $this->formatter->formatError($data['error'], $lang));
        return response('OK');
    }

    $message = $this->reportFormatter->formatTodaySummary($data, $lang);
    $this->sendMessage($chatId, $message);

    return response('OK');
}

protected function handleShiftsReport(int $chatId, $user, string $lang)
{
    $data = $this->reportService->getShiftPerformance($user, \Carbon\Carbon::today());

    if (isset($data['error'])) {
        $this->sendMessage($chatId, $this->formatter->formatError($data['error'], $lang));
        return response('OK');
    }

    $message = $this->reportFormatter->formatShiftPerformance($data, $lang);
    $this->sendMessage($chatId, $message);

    return response('OK');
}

protected function handleTransactionsReport(int $chatId, $user, string $lang)
{
    $data = $this->reportService->getTransactionActivity(
        $user,
        \Carbon\Carbon::today(),
        \Carbon\Carbon::today()
    );

    if (isset($data['error'])) {
        $this->sendMessage($chatId, $this->formatter->formatError($data['error'], $lang));
        return response('OK');
    }

    $message = $this->reportFormatter->formatTransactionActivity($data, $lang);
    $this->sendMessage($chatId, $message);

    return response('OK');
}

protected function handleLocationsReport(int $chatId, $user, string $lang)
{
    $data = $this->reportService->getMultiLocationSummary($user);

    if (isset($data['error'])) {
        $this->sendMessage($chatId, $this->formatter->formatError($data['error'], $lang));
        return response('OK');
    }

    $message = $this->reportFormatter->formatMultiLocationSummary($data, $lang);
    $this->sendMessage($chatId, $message);

    return response('OK');
}
```

### Step 4: Add Translations to Language Files

#### English (`lang/en/telegram_pos.php`)
Add these lines:

```php
// Reports
'reports' => '📊 Reports',
'select_report_type' => 'Select report type:',
'today_summary' => '📅 Today\'s Summary',
'shift_performance' => '👥 Shift Performance',
'transaction_report' => '💰 Transactions',
'multi_location_summary' => '🏢 All Locations',
'manager_only' => 'This feature is only available for managers.',

// Report Content
'date' => 'Date',
'location' => 'Location',
'shifts' => 'SHIFTS',
'open_shifts' => 'Open',
'closed_shifts' => 'Closed',
'under_review' => 'Under Review',
'total_shifts' => 'Total',
'transactions' => 'transactions',
'cash_in' => 'Cash In',
'cash_out' => 'Cash Out',
'exchanges' => 'Exchanges',
'totals_by_currency' => 'TOTALS BY CURRENCY',
'net' => 'Net',
'active_cashiers' => 'ACTIVE CASHIERS',
'currently_working' => 'currently working',
'discrepancies' => 'DISCREPANCIES',
'shifts_flagged_review' => 'shifts flagged for review',
'top_performer' => 'TOP PERFORMER',
'summary' => 'SUMMARY',
'total_transactions' => 'Total Transactions',
'avg_shift_duration' => 'Avg Shift Duration',
'no_shifts_found' => 'No shifts found for this period.',
'shift' => 'Shift',
'cashier' => 'Cashier',
'drawer' => 'Drawer',
'opened' => 'Opened',
'closed' => 'Closed',
'duration' => 'Duration',
'ongoing' => 'ongoing',
'status' => 'Status',
'status_open' => 'Open',
'status_closed' => 'Closed',
'status_under_review' => 'Under Review',
'and_more' => 'and more',
'shift_detail' => 'SHIFT DETAIL',
'shift_id' => 'Shift ID',
'balances' => 'BALANCES',
'discrepancy' => 'DISCREPANCY',
'expected' => 'Expected',
'counted' => 'Counted',
'reason' => 'Reason',
'recent_transactions' => 'RECENT TRANSACTIONS',
'period' => 'Period',
'total' => 'Total',
'txns' => 'txns',
'by_currency' => 'BY CURRENCY',
'top_cashiers' => 'TOP CASHIERS',
'total_locations' => 'Total Locations',
'open' => 'Open',
'active' => 'Active',
```

#### Russian (`lang/ru/telegram_pos.php`)
Add these lines:

```php
// Reports
'reports' => '📊 Отчеты',
'select_report_type' => 'Выберите тип отчета:',
'today_summary' => '📅 Сводка за сегодня',
'shift_performance' => '👥 Производительность смен',
'transaction_report' => '💰 Транзакции',
'multi_location_summary' => '🏢 Все филиалы',
'manager_only' => 'Эта функция доступна только менеджерам.',
// (add all other translations in Russian)
```

#### Uzbek (`lang/uz/telegram_pos.php`)
Add these lines:

```php
// Reports
'reports' => '📊 Hisobotlar',
'select_report_type' => 'Hisobot turini tanlang:',
'today_summary' => '📅 Bugungi xulosa',
'shift_performance' => '👥 Smena natijalari',
'transaction_report' => '💰 Tranzaksiyalar',
'multi_location_summary' => '🏢 Barcha filiallar',
'manager_only' => 'Bu funksiya faqat menejerlar uchun mavjud.',
// (add all other translations in Uzbek)
```

### Step 5: Test

1. SSH to manager account in Telegram bot
2. Check that "📊 Reports" button appears
3. Click Reports and verify menu appears
4. Test each report type
5. Verify authorization (cashiers shouldn't see Reports)

---

## Files Created:
- ✅ `/var/www/jahongirnewapp/app/Services/TelegramReportService.php`
- ✅ `/var/www/jahongirnewapp/app/Services/TelegramReportFormatter.php`

## Files To Modify:
- ⏳ `/var/www/jahongirnewapp/app/Services/TelegramKeyboardBuilder.php`
- ⏳ `/var/www/jahongirnewapp/app/Http/Controllers/TelegramPosController.php`
- ⏳ `/var/www/jahongirnewapp/lang/en/telegram_pos.php`
- ⏳ `/var/www/jahongirnewapp/lang/ru/telegram_pos.php`
- ⏳ `/var/www/jahongirnewapp/lang/uz/telegram_pos.php`
