<?php

return [
    // Welcome and Authentication
    'welcome' => 'Добро пожаловать в систему POS! 🏪',
    'welcome_back' => 'С возвращением, :name! 👋',
    'auth_required' => 'Пожалуйста, поделитесь своим номером телефона для авторизации и доступа к функциям POS.',
    'auth_success' => 'Авторизация успешна! Добро пожаловать :name ✅',
    'auth_failed' => 'Номер телефона не авторизован. Пожалуйста, свяжитесь с вашим менеджером.',
    'share_contact' => '📱 Поделиться номером телефона',
    'session_expired' => 'Ваша сессия истекла. Пожалуйста, авторизуйтесь снова.',
    
    // Main Menu
    'main_menu' => 'Главное меню',
    'select_action' => 'Пожалуйста, выберите действие:',
    'start_shift' => '🟢 Начать смену',
    'my_shift' => '📊 Моя смена',
    'record_transaction' => '💰 Записать транзакцию',
    'close_shift' => '🔴 Закрыть смену',
    'help' => 'ℹ️ Помощь',
    'settings' => '⚙️ Настройки',
    
    // Language
    'language' => 'Язык',
    'language_changed' => 'Язык успешно изменен! 🌐',
    'select_language' => 'Выберите ваш язык:',
    'lang_en' => '🇬🇧 English',
    'lang_ru' => '🇷🇺 Русский',
    'lang_uz' => '🇺🇿 O\'zbekcha',
    
    // Shift Management
    'no_open_shift' => 'У вас нет открытой смены.',
    'shift_already_open' => 'У вас уже есть открытая смена на кассе :drawer',
    'shift_started' => '✅ Смена успешно начата!',
    'shift_start_failed' => 'Не удалось начать смену. :reason',
    'shift_closed' => '✅ Смена успешно закрыта!',
    'shift_details' => "📊 Детали смены\n\nID смены: :shift_id\nЛокация: :location\nКасса: :drawer\nВремя начала: :start_time\nПродолжительность: :duration",
    'no_location_assigned' => 'Вы не назначены ни на одну локацию. Пожалуйста, свяжитесь с вашим менеджером.',
    
    // Running Balance
    'running_balance' => '💰 Текущий баланс',
    'currency_balance' => ':currency: :amount',
    'total_transactions' => 'Всего транзакций: :count',
    
    // Transactions
    'transaction_recorded' => '✅ Транзакция успешно записана!',
    'transaction_failed' => 'Не удалось записать транзакцию. :reason',
    'select_transaction_type' => 'Выберите тип транзакции:',
    'cash_in' => '💵 Приход',
    'cash_out' => '💸 Расход',
    'complex_transaction' => '🔄 Сложная (Обмен)',
    'enter_amount' => 'Введите сумму:',
    'enter_out_amount' => 'Введите сумму для выдачи (обмен):',
    'select_currency' => 'Выберите валюту:',
    'select_out_currency' => 'Выберите валюту для выдаваемой суммы:',
    'select_category' => 'Выберите категорию:',
    'add_notes' => 'Добавить заметки (необязательно):',
    'skip_notes' => 'Пропустить ⏭️',
    'confirm_transaction' => 'Подтвердить транзакцию?',
    
    // Transaction Display
    'recent_transactions' => '📝 Последние транзакции (10 шт.)',
    'no_transactions' => 'Транзакций пока нет',
    'txn_in' => 'ПРИХОД',
    'txn_out' => 'РАСХОД',
    'txn_exchange' => 'ОБМЕН',
    
    // Categories
    'category_sale' => '🛍️ Продажа',
    'category_refund' => '↩️ Возврат',
    'category_expense' => '📤 Расход',
    'category_deposit' => '📥 Депозит',
    'category_change' => '💱 Сдача',
    'category_other' => '📝 Другое',
    
    // Close Shift
    'close_shift_confirm' => 'Вы уверены, что хотите закрыть смену?',
    'enter_counted_amount' => 'Введите подсчитанную сумму для :currency:',
    'discrepancy_found' => '⚠️ Найдено расхождение: :amount',
    'enter_discrepancy_reason' => 'Пожалуйста, объясните расхождение:',
    'shift_under_review' => 'Смена закрыта и помечена на проверку из-за расхождения.',
    
    // Buttons
    'confirm' => '✅ Подтвердить',
    'cancel' => '❌ Отмена',
    'cancelled' => 'Отменено',
    'back' => '⬅️ Назад',
    'next' => '➡️ Далее',
    'done' => '✅ Готово',
    
    // Errors
    'error_occurred' => 'Произошла ошибка. Пожалуйста, попробуйте снова.',
    'invalid_amount' => 'Неверная сумма. Пожалуйста, введите корректное число.',
    'shift_not_open' => 'Сначала нужно начать смену.',
    'unauthorized' => 'У вас нет прав для выполнения этого действия.',
    
    // Help
    'help_text' => "📚 Помощь POS бота\n\n🟢 Начать смену - Начать вашу кассовую смену\n📊 Моя смена - Посмотреть статус текущей смены и баланс\n💰 Записать транзакцию - Записать денежную транзакцию\n🔴 Закрыть смену - Завершить смену и подсчитать деньги\n\nНужна помощь? Свяжитесь с вашим менеджером.",
    
    // Notifications
    'manager_notified' => 'Менеджер уведомлен.',
    'shift_approved' => '✅ Ваша смена была одобрена :manager',
    'shift_rejected' => '❌ Ваша смена была отклонена. Причина: :reason',
];

