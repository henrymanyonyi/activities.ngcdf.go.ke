<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Submit an activity' }} · NG-CDF</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ngcdf-logo.png') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full font-sans antialiased text-slate-900">
    <header class="bg-emerald-900 text-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-3">
            <div class="bg-white p-1 rounded-lg w-10 h-10 flex items-center justify-center"><img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-8 w-auto"></div>
            <div><p class="font-bold text-sm uppercase tracking-tight">NG-CDF Board</p><p class="text-[11px] text-emerald-300">Office of the CEO · activity submission</p></div>
        </div>
    </header>
    @include('layouts.partials.toast')
    <main class="max-w-4xl mx-auto px-4 sm:px-6 py-8">{{ $slot }}</main>
    @livewireScripts
</body>
</html>
