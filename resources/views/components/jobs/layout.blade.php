<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Вакансии · Jahongir Hotel' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        input:focus, select:focus, textarea:focus { outline: none; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-md mx-auto p-4 sm:p-6">
        <div class="text-center mb-6 mt-2">
            <h1 class="text-2xl font-semibold text-gray-900">Jahongir Hotel</h1>
            <p class="text-sm text-gray-500">Подача заявки на работу</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
            {{ $slot }}
        </div>
        <div class="text-center text-xs text-gray-500 mt-6">
            Все данные обрабатываются конфиденциально
        </div>
    </div>
</body>
</html>
