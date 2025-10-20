<?php

return [
    // Basic
    'welcome' => 'Welcome to the POS system!',
    'main_menu' => 'Main Menu',
    'unauthorized' => 'Unauthorized. Please share your contact first.',
    'share_contact' => '📱 Share Contact',
    
    // Main Menu Buttons
    'start_shift' => '🟢 Start Shift',
    'my_shift' => '📊 My Shift',
    'record_transaction' => '💵 Record Transaction',
    'close_shift' => '🔴 Close Shift',
    'help' => '❓ Help',
    'settings' => '⚙️ Settings',
    'cancel' => '❌ Cancel',
    'back' => '◀️ Back',
    'confirm' => '✅ Confirm',
    
    // Language
    'language_set' => 'Language has been set successfully!',
    'language_changed' => 'Language changed successfully! 🌐',
    'select_language' => 'Select your language:',

    // Shift Management
    'no_open_shift' => 'You don\'t have an open shift.',
    'shift_already_open' => 'You already have an open shift on drawer :drawer',
    'shift_started' => '✅ Shift started successfully!',
    'shift_start_failed' => 'Failed to start shift. :reason',
    'shift_closed' => '✅ Shift closed successfully!',
    'shift_not_found' => 'Shift not found.',
    'shift_not_open' => 'You need to start a shift first.',
    'enter_counted_amount' => 'Enter counted amount for :currency:',

    // Running Balance
    'running_balance' => '💰 Running Balance',

    // Transactions
    'transaction_recorded' => '✅ Transaction recorded successfully!',
    'transaction_failed' => 'Failed to record transaction. :reason',
    'select_transaction_type' => 'Select transaction type:',
    'cash_in' => '💵 Cash In',
    'cash_out' => '💸 Cash Out',
    'complex_transaction' => '🔄 Complex (Exchange)',
    'enter_amount' => 'Enter amount:',
    'enter_out_amount' => 'Enter amount to give out (exchange):',
    'select_currency' => 'Select currency:',
    'select_out_currency' => 'Select currency for amount to give out:',
    'select_category' => 'Select category:',
    'add_notes' => 'Add notes (optional):',
    'skip_notes' => 'Skip ⏭️',

    // Categories
    'category_sale' => '🛍️ Sale',
    'category_refund' => '↩️ Refund',
    'category_expense' => '📤 Expense',
    'category_deposit' => '📥 Deposit',
    'category_change' => '💱 Change',
    'category_other' => '📝 Other',

    // Buttons
    'cancelled' => 'Cancelled',

    // Errors
    'error_occurred' => 'An error occurred. Please try again.',
    'invalid_amount' => 'Invalid amount. Please enter a valid number.',

    // Help
    'help_text' => "📚 POS Bot Help\n\n🟢 Start Shift - Begin your cashier shift\n📊 My Shift - View current shift status and balance\n💵 Record Transaction - Record a cash transaction\n🔴 Close Shift - End your shift and count cash\n\nNeed assistance? Contact your manager.",

    // Reports
    'reports' => '📊 Reports',
    'select_report_type' => 'Select report type:',
    'today_summary' => '📅 Today\'s Summary',
    'shift_performance' => '👥 Shift Performance',
    'transaction_report' => '💰 Transactions',
    'multi_location' => '🏢 All Locations',
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
    'active' => 'Active',
    
    // Report menu items
    'financial_range' => 'Financial Range',
    'executive_dashboard' => 'Executive Dashboard',
    'currency_exchange' => 'Currency Exchange',
    'transaction_report' => 'Transaction Report',
    'multi_location' => 'Multi-Location Summary',
];
