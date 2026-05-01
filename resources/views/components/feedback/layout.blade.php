<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Feedback · Jahongir Travel' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        /* Bigger tap targets on mobile, no native focus rings on the stars */
        .star-btn:focus { outline: none; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-md mx-auto p-4 sm:p-6">
        <div class="text-center mb-6 mt-2">
            <h1 class="text-2xl font-semibold text-gray-900">Jahongir Travel</h1>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 sm:p-6">
            {{ $slot }}
        </div>

        <div class="text-center text-xs text-gray-400 mt-6">
            Thank you for choosing Jahongir Travel
        </div>
    </div>
</body>
</html>
