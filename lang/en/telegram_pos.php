<?php

return [
    // Welcome and Authentication
    'welcome' => 'Welcome to POS System! 🏪',
    'welcome_back' => 'Welcome back, :name! 👋',
    'auth_required' => 'Please share your phone number to authenticate and access POS features.',
    'auth_success' => 'Authentication successful! Welcome :name ✅',
    'auth_failed' => 'Phone number not authorized. Please contact your manager.',
    'share_contact' => '📱 Share Phone Number',
    'session_expired' => 'Your session has expired. Please authenticate again.',
    
    // Main Menu
    'main_menu' => 'Main Menu',
    'select_action' => 'Please select an action:',
    'start_shift' => '🟢 Start Shift',
    'my_shift' => '📊 My Shift',
    'record_transaction' => '💰 Record Transaction',
    'close_shift' => '🔴 Close Shift',
    'help' => 'ℹ️ Help',
    'settings' => '⚙️ Settings',
    
    // Language
    'language' => 'Language',
    'language_changed' => 'Language changed successfully! 🌐',
    'select_language' => 'Select your language:',
    'lang_en' => '🇬🇧 English',
    'lang_ru' => '🇷🇺 Русский',
    'lang_uz' => '🇺🇿 O\'zbekcha',
    
    // Shift Management
    'no_open_shift' => 'You don\'t have an open shift.',
    'shift_already_open' => 'You already have an open shift on drawer :drawer',
    'shift_started' => '✅ Shift started successfully!',
    'shift_start_failed' => 'Failed to start shift. :reason',
    'shift_closed' => '✅ Shift closed successfully!',
    'shift_details' => "📊 Shift Details\n\nShift ID: :shift_id\nLocation: :location\nDrawer: :drawer\nStart Time: :start_time\nDuration: :duration",
    'no_location_assigned' => 'You are not assigned to any locations. Please contact your manager.',
    
    // Running Balance
    'running_balance' => '💰 Running Balance',
    'currency_balance' => ':currency: :amount',
    'total_transactions' => 'Total Transactions: :count',
    
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
    'confirm_transaction' => 'Confirm transaction?',
    
    // Categories
    'category_sale' => '🛍️ Sale',
    'category_refund' => '↩️ Refund',
    'category_expense' => '📤 Expense',
    'category_deposit' => '📥 Deposit',
    'category_change' => '💱 Change',
    'category_other' => '📝 Other',
    
    // Close Shift
    'close_shift_confirm' => 'Are you sure you want to close the shift?',
    'enter_counted_amount' => 'Enter counted amount for :currency:',
    'discrepancy_found' => '⚠️ Discrepancy found: :amount',
    'enter_discrepancy_reason' => 'Please explain the discrepancy:',
    'shift_under_review' => 'Shift closed and marked for review due to discrepancy.',
    
    // Buttons
    'confirm' => '✅ Confirm',
    'cancel' => '❌ Cancel',
    'cancelled' => 'Cancelled',
    'back' => '⬅️ Back',
    'next' => '➡️ Next',
    'done' => '✅ Done',
    
    // Errors
    'error_occurred' => 'An error occurred. Please try again.',
    'invalid_amount' => 'Invalid amount. Please enter a valid number.',
    'shift_not_open' => 'You need to start a shift first.',
    'unauthorized' => 'You are not authorized to perform this action.',
    
    // Help
    'help_text' => "📚 POS Bot Help\n\n🟢 Start Shift - Begin your cashier shift\n📊 My Shift - View current shift status and balance\n💰 Record Transaction - Record a cash transaction\n🔴 Close Shift - End your shift and count cash\n\nNeed assistance? Contact your manager.",
    
    // Notifications
    'manager_notified' => 'Manager has been notified.',
    'shift_approved' => '✅ Your shift has been approved by :manager',
    'shift_rejected' => '❌ Your shift has been rejected. Reason: :reason',
];

