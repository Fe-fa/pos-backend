<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="container">
        <h1>Welcome to your Dashboard</h1>
        <p>You are logged in successfully.</p>

        <!-- Example: show logged-in user -->
        @auth
            <p>Hello, {{ Auth::user()->name }}!</p>
        @endauth
    </div>
</body>
</html>
