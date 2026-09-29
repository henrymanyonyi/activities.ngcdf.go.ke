<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ngcdf-logo.png') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="h-full font-sans antialiased text-slate-900"
    x-data="{
        sidebarOpen: (() => { try { return localStorage.getItem('sidebarOpen') !== 'false' } catch (e) { return true } })(),
        sidebarMobile: false,
    }"
    x-init="$watch('sidebarOpen', value => { try { localStorage.setItem('sidebarOpen', value) } catch (e) {} })">

    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:bg-white focus:px-3 focus:py-2 focus:rounded-lg">Skip to content</a>

    <div class="flex h-screen overflow-hidden">

        {{-- Sidebar --}}
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
            class="hidden md:flex flex-col flex-shrink-0 bg-emerald-900 text-white transition-all duration-300 overflow-hidden shadow-xl z-30">
            <div class="flex items-center h-20 px-5 border-b border-emerald-800/50 flex-shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="bg-white p-1.5 rounded-xl w-11 h-11 flex items-center justify-center flex-shrink-0">
                        <img src="{{ asset('images/ngcdf-logo.png') }}" alt="NG-CDF" class="h-8 w-auto">
                    </div>
                    <div x-show="sidebarOpen" class="flex flex-col min-w-0">
                        <span class="font-bold text-sm tracking-tight truncate uppercase">NG-CDF FAPM</span>
                        <span class="text-[10px] text-emerald-300/80 truncate">Field Activity Planning &amp; Monitoring</span>
                    </div>
                </div>
            </div>

            <div class="p-3 border-b border-emerald-800/50 flex-shrink-0">
                <button type="button" @click="sidebarOpen = !sidebarOpen" title="Toggle sidebar"
                    class="w-full flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-emerald-400 hover:text-white hover:bg-emerald-950/40 transition border border-emerald-800/30">
                    <i :class="sidebarOpen ? 'fa-solid fa-chevron-left' : 'fa-solid fa-chevron-right'" class="text-[10px]"></i>
                    <span x-show="sidebarOpen" class="text-[11px] font-semibold">Collapse</span>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto py-5 space-y-1 px-3" aria-label="Main">
                @include('layouts.partials.sidebar-nav')
            </nav>
        </aside>

        {{-- Mobile sidebar --}}
        <div x-show="sidebarMobile" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-emerald-900/80 md:hidden backdrop-blur-sm"
            @click="sidebarMobile = false"></div>
        <div x-show="sidebarMobile" x-cloak
            x-transition:enter="transition ease-in-out duration-300 transform" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in-out duration-300 transform" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
            class="fixed inset-y-0 z-50 w-64 bg-emerald-900 text-white flex flex-col md:hidden">
            <div class="flex items-center justify-between h-20 px-5 border-b border-emerald-800/50">
                <span class="font-bold text-sm tracking-tight uppercase">NG-CDF FAPM</span>
                <button type="button" @click="sidebarMobile = false" class="text-emerald-400 hover:text-white" aria-label="Close menu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto py-5 space-y-1 px-3" aria-label="Main">
                <div x-data="{ sidebarOpen: true }">@include('layouts.partials.sidebar-nav')</div>
            </nav>
        </div>

        {{-- Main --}}
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
            <header class="relative flex items-center justify-between h-16 bg-white/80 backdrop-blur-md border-b border-slate-200 px-6 flex-shrink-0 z-30">
                <div class="flex items-center gap-4">
                    <button type="button" @click="sidebarMobile = !sidebarMobile" class="md:hidden text-slate-500 hover:text-emerald-700 transition" aria-label="Open menu">
                        <i class="fa-solid fa-bars-staggered"></i>
                    </button>
                    <span class="hidden sm:inline text-sm font-bold text-emerald-900 uppercase tracking-wide truncate max-w-[40vw]">{{ $title ?? 'Dashboard' }}</span>
                    <x-ui.restricted />
                </div>

                @php
                    $menuUser = auth()->user();
                    $menuItem = 'flex items-center gap-3 w-full px-3 py-2 rounded-lg text-[13px] font-medium transition';
                @endphp

                <div class="flex items-center gap-3">
                <livewire:alerts-bell />
                <div class="h-6 w-px bg-slate-200"></div>
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
                        class="flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/30"
                        :class="open ? 'bg-slate-100' : 'hover:bg-slate-100/70'">
                        <img src="{{ $menuUser->profile_photo_url }}" alt="" class="w-9 h-9 rounded-xl object-cover ring-2 ring-emerald-100">
                        <span class="hidden sm:flex flex-col items-start leading-tight min-w-0">
                            <span class="text-[13px] font-semibold text-slate-700 truncate max-w-[10rem]">{{ $menuUser->name }}</span>
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $menuUser->roleLabel() }}</span>
                        </span>
                        <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform" :class="open ? 'rotate-180 text-emerald-600' : ''"></i>
                    </button>

                    <div x-show="open" x-cloak @click.outside="open = false" role="menu"
                        x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                        class="absolute right-0 mt-2 w-64 origin-top-right bg-white rounded-xl shadow-2xl border border-slate-200 z-50 overflow-hidden">
                        <div class="px-4 py-3.5 bg-slate-50/60 border-b border-slate-100">
                            <p class="text-[13px] font-semibold text-slate-700 truncate">{{ $menuUser->name }}</p>
                            <p class="text-[11px] text-slate-400 truncate">{{ $menuUser->email }}</p>
                        </div>
                        <div class="p-1.5">
                            <a href="{{ route('profile.show') }}" role="menuitem" class="{{ $menuItem }} text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-regular fa-user w-4 text-center text-slate-400"></i> Profile and security
                            </a>
                        </div>
                        <div class="p-1.5 border-t border-slate-100">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" role="menuitem" class="{{ $menuItem }} text-red-600 hover:bg-red-50">
                                    <i class="fa-solid fa-right-from-bracket w-4 text-center"></i> Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                </div>
            </header>

            <main id="main" class="flex-1 overflow-y-auto p-8 bg-slate-50">
                @include('layouts.partials.toast')
                {{ $slot }}
            </main>
        </div>
    </div>

    @stack('modals')
    @livewireScripts
    @stack('scripts')
</body>

</html>
