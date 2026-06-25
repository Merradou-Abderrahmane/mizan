<!DOCTYPE html>
<html lang="en" data-theme="corporate">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Mizan' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 text-base-content">
    <div class="navbar bg-base-100 border-b border-base-300">
        <div class="flex-1">
            <a href="{{ route('runs.index') }}" class="btn btn-ghost text-xl font-semibold">Mizan</a>
            <span class="text-sm text-base-content/60 hidden sm:inline">bootcamp autograder · operator panel</span>
        </div>
        <div class="flex-none">
            <a href="{{ route('runs.create') }}" class="btn btn-primary btn-sm">New run</a>
        </div>
    </div>

    <main class="max-w-6xl mx-auto p-4 sm:p-6">
        @if (session('status'))
            <div class="alert alert-info mb-4">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
