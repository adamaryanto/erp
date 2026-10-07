@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div x-data="dashMonitor()" x-init="init()" x-cloak>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 text-xs mb-1">
                    <span class="text-slate-400">Accurate ERP</span>
                    <span class="text-slate-300">/</span>
                    <span class="text-blue-700">Dashboard</span>
                </div>
                <h1 class="text-2xl text-slate-900">Dashboard Produksi</h1>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs px-2.5 py-1 rounded-full" :class="anyOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'" x-text="anyOnline ? 'LIVE' : 'OFFLINE'"></span>
                <span class="font-mono text-xs bg-white px-3 py-1.5 rounded-lg border border-slate-200 text-slate-900" x-text="clock"></span>
            </div>
        </div>

        <!-- KPI ROW -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-slate-500 text-sm">Total Netto Hari Ini</p>
                <p class="text-3xl text-slate-900 mt-2 font-mono">{{ number_format($totalNetKg ?? 0, 2) }} <span class="text-sm text-slate-400">kg</span></p>
                <p class="text-xs text-slate-500 mt-2"><span x-text="todayBaskets"></span> keranjang ditimbang</p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-slate-500 text-sm">Total Karkas Hari Ini</p>
                <p class="text-3xl text-slate-900 mt-2 font-mono"><span x-text="fmt(kg.karkas)"></span> <span class="text-sm text-slate-400">kg</span></p>
                <p class="text-xs text-slate-500 mt-2">Yield: <span class="font-mono text-emerald-600" x-text="yieldPct.toFixed(2) + '%'"></span></p>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <p class="text-slate-500 text-sm">Status Mesin</p>
                <div class="mt-2 space-y-1.5">
                    <template x-for="st in stations" :key="st.code">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-mono text-slate-700" x-text="st.code"></span>
                            <span class="text-xs px-2 py-0.5 rounded" :class="st.online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'" x-text="st.online ? 'Online' : 'Offline'"></span>
                        </div>
                    </template>
                    <p x-show="!stations.length" class="text-xs text-slate-400">Belum ada pos.</p>
                </div>
            </div>
        </div>

        <!-- LIVE PANELS -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-5">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-slate-900 text-sm uppercase">Timbang Hidup (Live)</h2>
                        <p class="text-xs text-slate-500">Angka berjalan per pos</p>
                    </div>
                    <span class="font-mono text-xs text-blue-700" x-text="fmt(kg.hidup) + ' kg'"></span>
                </div>
                <div class="space-y-2.5">
                    <template x-for="st in stationsOf('hidup')" :key="st.code">
                        <div class="border border-slate-200 rounded-lg p-3 flex items-center justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full" :class="st.online ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                                    <span class="text-xs text-slate-900 font-mono" x-text="st.code"></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5 font-mono" x-text="'Hari ini: ' + fmt(st.today_kg) + ' kg'"></div>
                            </div>
                            <div class="text-right font-mono">
                                <div class="text-lg text-slate-900" x-text="st.live_weight !== null ? fmt(st.live_weight) : '—'"></div>
                                <div class="text-[11px]" :class="st.online ? 'text-blue-600' : 'text-slate-400'" x-text="st.online ? 'LIVE' : 'PUTUS'"></div>
                            </div>
                        </div>
                    </template>
                    <p x-show="!stationsOf('hidup').length" class="text-xs text-slate-400 text-center py-3">Belum ada pos tahap hidup.</p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-5">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                    <div>
                        <h2 class="text-slate-900 text-sm uppercase">Timbang Karkas (Live)</h2>
                        <p class="text-xs text-slate-500">Post-chilling / grading</p>
                    </div>
                    <span class="font-mono text-xs text-emerald-700" x-text="fmt(kg.karkas) + ' kg'"></span>
                </div>
                <div class="space-y-2.5">
                    <template x-for="st in stationsOf('karkas')" :key="st.code">
                        <div class="border border-slate-200 rounded-lg p-3 flex items-center justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full" :class="st.online ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                                    <span class="text-xs text-slate-900 font-mono" x-text="st.code"></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5 font-mono" x-text="'Hari ini: ' + fmt(st.today_kg) + ' kg'"></div>
                            </div>
                            <div class="text-right font-mono">
                                <div class="text-lg text-slate-900" x-text="st.live_weight !== null ? fmt(st.live_weight) : '—'"></div>
                                <div class="text-[11px]" :class="st.online ? 'text-emerald-600' : 'text-slate-400'" x-text="st.online ? 'LIVE' : 'PUTUS'"></div>
                            </div>
                        </div>
                    </template>
                    <p x-show="!stationsOf('karkas').length" class="text-xs text-slate-400 text-center py-3">Belum ada pos tahap karkas.</p>
                </div>
            </div>
        </div>

        <!-- ANALYTICS -->
        <div class="bg-white rounded-lg shadow p-5 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h3 class="text-slate-900">Selisih & Yield</h3>
                    <p class="text-xs text-slate-500">Susut antar tahap + rendement karkas</p>
                </div>
                <span class="text-xs font-mono px-3 py-1.5 rounded-lg border" :class="yieldBadgeClass" x-text="yieldLabel"></span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <span class="text-xs text-slate-500 uppercase">Gantung → Karkas</span>
                    <div class="flex items-baseline justify-between mt-1">
                        <span class="text-xl font-mono text-slate-900" x-text="fmt(Math.max(0, kg.gantung - kg.karkas)) + ' Kg'"></span>
                        <span class="text-xs text-slate-500 font-mono">Darah/bulu/jeroan</span>
                    </div>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <span class="text-xs text-slate-500 uppercase">Total Susut (Hidup → Karkas)</span>
                    <div class="flex items-baseline justify-between mt-1">
                        <span class="text-xl font-mono text-rose-600" x-text="fmt(dropKg) + ' Kg'"></span>
                        <span class="text-xs text-slate-500 font-mono" x-text="'Yield: ' + yieldPct.toFixed(2) + '%'"></span>
                    </div>
                </div>
            </div>
            <div class="h-56 w-full"><canvas id="dashChart"></canvas></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        function dashMonitor() {
            return {
                stations: [],
                kg: { hidup: 0, gantung: 0, karkas: 0, parting: 0, gudang: 0 },
                ekorHidup: 0,
                todayBaskets: {{ $totalCount ?? 0 }},
                yieldPct: 0,
                dropKg: 0,
                yieldLabel: 'MENUNGGU DATA',
                yieldBadgeClass: 'bg-slate-100 text-slate-500 border-slate-200',
                anyOnline: false,
                clock: '',
                chart: null,
                async fetchSummary() {
                    try {
                        const res = await fetch('/api/monitoring/summary');
                        if (!res.ok) throw new Error('http ' + res.status);
                        const d = await res.json();
                        this.stations = d.stations || [];
                        const st = d.stages || {};
                        ['hidup', 'gantung', 'karkas', 'parting', 'gudang'].forEach(k => {
                            this.kg[k] = Number((st[k] || {}).kg || 0);
                        });
                        this.ekorHidup = Number(d.ekor_hidup || 0);
                        this.yieldPct = Number((d.yield || {}).pct || 0);
                        this.dropKg = Number((d.yield || {}).drop_kg || 0);
                        this.anyOnline = this.stations.some(s => s.online);
                        const hasData = this.kg.hidup > 0 || this.kg.karkas > 0;
                        if (!hasData) {
                            this.yieldLabel = 'MENUNGGU DATA';
                            this.yieldBadgeClass = 'bg-slate-100 text-slate-500 border-slate-200';
                        } else if (this.yieldPct >= 70 && this.yieldPct <= 79) {
                            this.yieldLabel = 'NORMAL YIELD (70–79%)';
                            this.yieldBadgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        } else if (this.yieldPct < 70) {
                            this.yieldLabel = 'LOW YIELD (periksa eviscerasi)';
                            this.yieldBadgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
                        } else {
                            this.yieldLabel = 'HIGH YIELD (cek kalibrasi)';
                            this.yieldBadgeClass = 'bg-blue-50 text-blue-700 border-blue-200';
                        }
                        if (this.chart) {
                            this.chart.data.datasets[0].data = [this.kg.hidup, this.kg.gantung, this.kg.karkas];
                            this.chart.update('none');
                        }
                    } catch (e) {}
                },
                stationsOf(stage) {
                    return this.stations.filter(s => (s.stage || 'hidup') === stage);
                },
                fmt(v) {
                    return Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                },
                initChart() {
                    const ctx = document.getElementById('dashChart');
                    if (!ctx || typeof Chart === 'undefined') return;
                    this.chart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: ['Ayam Hidup', 'Gantung', 'Karkas'],
                            datasets: [{
                                label: 'Kg',
                                data: [0, 0, 0],
                                backgroundColor: ['rgba(25,109,210,.7)', 'rgba(217,119,6,.7)', 'rgba(5,150,105,.7)'],
                                borderRadius: 8,
                            }],
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false, animation: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, ticks: { color: '#64748b' } },
                                x: { ticks: { color: '#334155' } },
                            },
                        },
                    });
                },
                tickClock() {
                    this.clock = new Date().toTimeString().split(' ')[0];
                },
                init() {
                    this.initChart();
                    this.tickClock();
                    setInterval(() => this.tickClock(), 1000);
                    this.fetchSummary();
                    setInterval(() => this.fetchSummary(), 2000);
                },
            };
        }
    </script>
@endsection
