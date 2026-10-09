<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ERP Timbang Ayam Hidup')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
    </style>
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased text-sm">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-60 bg-white border-r border-slate-200 flex flex-col select-none">
            <div class="p-6 border-b border-slate-200">
                <h1 class="text-xl text-[#1976D2]">ERP RPA</h1>
                <p class="text-xs text-slate-500">Timbang Ayam Hidup</p>
            </div>

            <nav class="flex-1 p-3 space-y-6 overflow-y-auto">
                <div>
                    <div class="px-3 mb-2 text-[10px] text-slate-400 uppercase tracking-wider">UTAMA</div>
                    <div class="space-y-1">
                        <a href="{{ route('dashboard') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('dashboard') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                                <span>Dashboard</span>
                        </a>
                    </div>
                </div>

                <div>
                    <div class="px-3 mb-2 text-[10px] text-slate-400 uppercase tracking-wider">TRANSAKSI TIMBANG</div>
                    <div class="space-y-1">
                        <a href="{{ route('home') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('home') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                <span>Penimbangan (Mesin)</span>
                            </div>
                        </a>
                        <a href="{{ route('cam.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('cam.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-.546.894L15 18M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                <span>Penimbangan (Live Cam)</span>
                            </div>
                        </a>
                        <a href="{{ route('history.index') }}" class="w-full flex items-center justify-between px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('history.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                                <div class="flex items-center gap-3">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span>Riwayat Timbang</span>
                                </div>
                                @if(($todayCount ?? 0) > 0)
                                    <span class="bg-blue-100 text-[#1976D2] text-[10px] px-1.5 py-0.2 rounded-full">{{ $todayCount }}</span>
                                @endif
                            </a>
                        <a href="{{ route('summary.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('summary.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            <span>Rekap Harian</span>
                        </a>
                        <a href="{{ route('delivery.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('delivery.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            <span>Rekap per Mobil</span>
                        </a>
                    </div>
                </div>

                <div>
                    <div class="px-3 mb-2 text-[10px] text-slate-400 uppercase tracking-wider">DATA MASTER</div>
                    <div class="space-y-1">
                        <a href="{{ route('master.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('master.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                            <span>Master Supplier & Tare</span>
                        </a>
                        <a href="{{ route('do.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('do.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            <span>Data DO / Faktur</span>
                        </a>
                    </div>
                </div>

                <div>
                    <div class="px-3 mb-2 text-[10px] text-slate-400 uppercase tracking-wider">SISTEM</div>
                    <div class="space-y-1">
                        @if(!auth()->check() || auth()->user()->isAdmin())
                            <a href="{{ route('settings.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('settings.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                <span>Pengaturan Indikator</span>
                            </a>
                        @endif
                        <a href="{{ route('buglog.index') }}" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-l-md text-xs transition-colors {{ request()->routeIs('buglog.*') ? 'bg-blue-50 text-[#1976D2] border-r-4 border-[#1976D2]' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <span>Bug Log</span>
                        </a>
                    </div>
                </div>
            </nav>


        </div>

        <!-- Main Content -->
        <div class="flex-1 p-4 lg:p-6 overflow-y-auto min-w-0 max-w-7xl mx-auto w-full">
            @if ($errors->any())
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (session('success'))
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded text-sm">
                    {{ session('success') }}
                </div>
            @endif

            @yield('content')
        </div>
    </div>
</body>
</html>
