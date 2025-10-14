<?php

return [
    // Welcome and Authentication
    'welcome' => 'POS tizimiga xush kelibsiz! 🏪',
    'welcome_back' => 'Xush kelibsiz, :name! 👋',
    'auth_required' => 'Iltimos, autentifikatsiya qilish va POS funksiyalariga kirish uchun telefon raqamingizni ulashing.',
    'auth_success' => 'Autentifikatsiya muvaffaqiyatli! Xush kelibsiz :name ✅',
    'auth_failed' => 'Telefon raqami avtorizatsiya qilinmagan. Iltimos, menejeringiz bilan bog\'laning.',
    'share_contact' => '📱 Telefon raqamini ulashish',
    'session_expired' => 'Sessiyangiz muddati tugadi. Iltimos, qaytadan autentifikatsiya qiling.',
    
    // Main Menu
    'main_menu' => 'Asosiy menyu',
    'select_action' => 'Iltimos, harakatni tanlang:',
    'start_shift' => '🟢 Smenani boshlash',
    'my_shift' => '📊 Mening smenaim',
    'record_transaction' => '💰 Tranzaksiyani yozish',
    'close_shift' => '🔴 Smenani yopish',
    'help' => 'ℹ️ Yordam',
    'settings' => '⚙️ Sozlamalar',
    
    // Language
    'language' => 'Til',
    'language_changed' => 'Til muvaffaqiyatli o\'zgartirildi! 🌐',
    'select_language' => 'Tilingizni tanlang:',
    'lang_en' => '🇬🇧 English',
    'lang_ru' => '🇷🇺 Русский',
    'lang_uz' => '🇺🇿 O\'zbekcha',
    
    // Shift Management
    'no_open_shift' => 'Sizda ochiq smena yo\'q.',
    'shift_already_open' => 'Sizda :drawer kassada allaqachon ochiq smena bor',
    'shift_started' => '✅ Smena muvaffaqiyatli boshlandi!',
    'shift_start_failed' => 'Smenani boshlash amalga oshmadi. :reason',
    'shift_closed' => '✅ Smena muvaffaqiyatli yopildi!',
    'shift_details' => "📊 Smena tafsilotlari\n\nSmena ID: :shift_id\nJoylashuv: :location\nKassa: :drawer\nBoshlanish vaqti: :start_time\nDavomiyligi: :duration",
    'no_location_assigned' => 'Siz hech qanday joyga tayinlanmagansiz. Iltimos, menejeringiz bilan bog\'laning.',
    
    // Running Balance
    'running_balance' => '💰 Joriy balans',
    'currency_balance' => ':currency: :amount',
    'total_transactions' => 'Jami tranzaksiyalar: :count',
    
    // Transactions
    'transaction_recorded' => '✅ Tranzaksiya muvaffaqiyatli yozildi!',
    'transaction_failed' => 'Tranzaksiyani yozish amalga oshmadi. :reason',
    'select_transaction_type' => 'Tranzaksiya turini tanlang:',
    'cash_in' => '💵 Kirim',
    'cash_out' => '💸 Chiqim',
    'complex_transaction' => '🔄 Murakkab (Ayirboshlash)',
    'enter_amount' => 'Summani kiriting:',
    'enter_out_amount' => 'Beriladigan summani kiriting (ayirboshlash):',
    'select_currency' => 'Valyutani tanlang:',
    'select_out_currency' => 'Beriladigan summa valyutasini tanlang:',
    'select_category' => 'Kategoriyani tanlang:',
    'add_notes' => 'Eslatma qo\'shing (ixtiyoriy):',
    'skip_notes' => 'O\'tkazib yuborish ⏭️',
    'confirm_transaction' => 'Tranzaksiyani tasdiqlaysizmi?',
    
    // Categories
    'category_sale' => '🛍️ Sotuv',
    'category_refund' => '↩️ Qaytarish',
    'category_expense' => '📤 Xarajat',
    'category_deposit' => '📥 Depozit',
    'category_change' => '💱 Qaytim',
    'category_other' => '📝 Boshqa',
    
    // Close Shift
    'close_shift_confirm' => 'Smenani yopishga aminmisiz?',
    'enter_counted_amount' => ':currency uchun hisoblangan summani kiriting:',
    'discrepancy_found' => '⚠️ Nomuvofiqlik topildi: :amount',
    'enter_discrepancy_reason' => 'Iltimos, nomuvofiqlikni tushuntiring:',
    'shift_under_review' => 'Smena yopildi va nomuvofiqlik tufayli ko\'rib chiqish uchun belgilandi.',
    
    // Buttons
    'confirm' => '✅ Tasdiqlash',
    'cancel' => '❌ Bekor qilish',
    'cancelled' => 'Bekor qilindi',
    'back' => '⬅️ Orqaga',
    'next' => '➡️ Keyingi',
    'done' => '✅ Tayyor',
    
    // Errors
    'error_occurred' => 'Xatolik yuz berdi. Iltimos, qaytadan urinib ko\'ring.',
    'invalid_amount' => 'Noto\'g\'ri summa. Iltimos, to\'g\'ri raqam kiriting.',
    'shift_not_open' => 'Avval smenani boshlashingiz kerak.',
    'unauthorized' => 'Sizda bu amalni bajarish huquqi yo\'q.',
    
    // Help
    'help_text' => "📚 POS bot yordami\n\n🟢 Smenani boshlash - Kassir smenangizni boshlang\n📊 Mening smenaim - Joriy smena holati va balansini ko\'ring\n💰 Tranzaksiyani yozish - Naqd tranzaksiyani yozing\n🔴 Smenani yopish - Smenangizni yakunlang va pulni hisoblang\n\nYordam kerakmi? Menejeringiz bilan bog\'laning.",
    
    // Notifications
    'manager_notified' => 'Menеjer xabardor qilindi.',
    'shift_approved' => '✅ Smenangiz :manager tomonidan tasdiqlandi',
    'shift_rejected' => '❌ Smenangiz rad etildi. Sabab: :reason',
];

