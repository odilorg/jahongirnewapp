<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Success</title>
    <style>
        body {
            text-align: center;
            padding: 50px;
            font-family: Arial, sans-serif;
        }
        .success {
            color: green;
            font-size: 24px;
        }
        .error {
            color: red;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <h1 class="{{ $status === 'succeeded' ? 'success' : 'error' }}">
        {{ $message }}
    </h1>
    <a href="/">Go back to home</a>
</body>
</html>
