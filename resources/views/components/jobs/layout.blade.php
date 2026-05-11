<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Вакансии · Jahongir Hotel' }}</title>
    {{-- `?plugins=forms` enables @tailwindcss/forms which auto-applies
         sensible default styles (borders, padding, focus rings) to
         <input>, <select>, <textarea>. Without it the CDN's Preflight
         strips default browser borders and our `border-gray-300`
         class only sets the color, leaving inputs invisible. --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        input:focus, select:focus, textarea:focus { outline: none; }
        /* Belt-and-suspenders: guarantee visible 1px gray border on all
           form controls even if the forms plugin ever fails to load
           (CDN outage, future Tailwind class-purge change). Matches
           the design the @tailwindcss/forms plugin produces. */
        input[type="text"], input[type="tel"], input[type="number"],
        input[type="email"], input[type="date"], input[type="file"],
        select, textarea {
            border: 1px solid #d1d5db; /* gray-300 */
            border-radius: 0.5rem;     /* rounded-lg */
            padding: 0.5rem 0.75rem;
            background-color: #fff;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #3b82f6;     /* blue-500 */
            box-shadow: 0 0 0 1px #3b82f6;
        }
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
