@extends('layouts.app')

@section('title', 'Bug Log')

@section('content')
    <div x-data="buglog()" x-init="init()" x-cloak>
        <div class="mb-6">
            <div class="flex items-center gap-2 text-xs mb-2">
                <span class="text-slate-400">Accurate ERP</span>
                <span class="text-slate-300">/</span>
                <span class="text-slate-400">Sistem</span>
                <span class="text-slate-300">/</span>
                <span class="text-blue-700">Bug Log</span>
            </div>
            <h1 class="text-2xl text-slate-900">Bug Log — Cek Error Otomatis</h1>
            <p class="text-slate-500 text-sm mt-1">Tombol di bawah memeriksa browser, koneksi, dan server, lalu AI menjelaskan biang keroknya: timbangan, laptop, atau web.</p>
        </div>

        <div class="bg-white rounded-lg shadow p-5 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Pos yang dicek</label>
                    <select x-model="station" class="h-11 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-blue-500">
                        @foreach($stations as $st)
                            <option value="{{ $st->code }}">{{ $st->code }} — {{ $st->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button @click="runCheck()" :disabled="checking"
                        :class="checking ? 'bg-slate-300 text-slate-500 cursor-wait' : 'bg-rose-600 hover:bg-rose-700 text-white'"
                        class="h-11 px-6 rounded-md text-sm transition flex items-center gap-2">
                    <svg x-show="!checking" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <svg x-show="checking" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    <span x-text="checking ? 'MEMERIKSA…' : 'CEK ERROR'"></span>
                </button>
                <span class="text-xs text-slate-400" x-text="status"></span>
            </div>

            <div x-show="result" class="mt-4 border-t border-slate-100 pt-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-xs px-2.5 py-1 rounded" :class="badgeClass" x-text="badgeText"></span>
                    <span class="text-slate-900" x-text="result ? result.title : ''"></span>
                </div>
                <p class="text-sm text-slate-600 mb-3" x-text="result ? result.explanation : ''"></p>
                <ol class="list-decimal ml-5 space-y-1.5 text-sm text-slate-700">
                    <template x-for="(s, i) in (result ? (result.steps || []) : [])" :key="i">
                        <li x-text="s"></li>
                    </template>
                </ol>
                <details class="mt-3 text-xs" x-show="result && result.payload && ((result.payload.trace && result.payload.trace.length) || result.payload.evidence)">
                    <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Bukti yang diperiksa otomatis (klik)</summary>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <template x-for="(t, i) in (result && result.payload && result.payload.trace ? result.payload.trace : [])" :key="i">
                            <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-mono" x-text="'✓ ' + t"></span>
                        </template>
                    </div>
                    <pre class="mt-2 p-3 bg-slate-900 text-slate-200 rounded-lg overflow-x-auto text-[11px] font-mono" x-text="result && result.payload && result.payload.evidence ? JSON.stringify(result.payload.evidence, null, 1).slice(0, 2000) : ''"></pre>
                </details>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-slate-900 text-sm uppercase">Riwayat vonis (20 terakhir)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                            <th class="px-5 py-2.5">Waktu</th>
                            <th class="px-3 py-2.5">Pos</th>
                            <th class="px-3 py-2.5">Vonis</th>
                            <th class="px-3 py-2.5">Judul</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($diagnoses as $d)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-5 py-2.5 font-mono text-xs whitespace-nowrap">{{ $d->created_at->format('d-m-Y H:i:s') }}</td>
                                <td class="px-3 py-2.5 font-mono">{{ $d->station }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="text-xs px-2 py-0.5 rounded
                                        {{ $d->verdict === 'scale' ? 'bg-amber-100 text-amber-800' : '' }}
                                        {{ $d->verdict === 'laptop' ? 'bg-blue-100 text-blue-800' : '' }}
                                        {{ $d->verdict === 'web' ? 'bg-rose-100 text-rose-800' : '' }}
                                        {{ $d->verdict === 'unknown' ? 'bg-slate-200 text-slate-600' : '' }}">
                                        {{ strtoupper($d->verdict) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-slate-800">{{ $d->title }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-6 text-center text-slate-400 text-sm">Belum ada vonis. Tekan CEK ERROR.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        function buglog() {
            return {
                station: @json($stations[0]->code ?? 'POS-01'),
                checking: false,
                status: '',
                result: null,
                get badgeText() {
                    if (!this.result) return '';
                    const v = this.result.verdict || 'unknown';
                    return { scale: 'TIMBANGAN', laptop: 'LAPTOP', web: 'WEB', unknown: 'BELUM JELAS' }[v] || v.toUpperCase();
                },
                get badgeClass() {
                    if (!this.result) return '';
                    const v = this.result.verdict || 'unknown';
                    return {
                        scale: 'bg-amber-100 text-amber-800',
                        laptop: 'bg-blue-100 text-blue-800',
                        web: 'bg-rose-100 text-rose-800',
                        unknown: 'bg-slate-200 text-slate-600',
                    }[v] || 'bg-slate-200 text-slate-600';
                },
                async runCheck() {
                    if (this.checking) return;
                    this.checking = true;
                    this.result = null;
                    this.status = 'Mengumpulkan data browser…';
                    let stash = null;
                    try { stash = JSON.parse(localStorage.getItem('timbang_usb_debug') || 'null'); } catch (e) {}
                    if (stash && (Date.now() - (stash.at || 0)) > 60000) stash = null;
                    const client = {
                        usb_supported: (typeof navigator !== 'undefined' && 'serial' in navigator),
                        weight_source: (stash && stash.source === 'usb') ? 'usb' : 'bridge',
                        page_connected: false,
                        backend_connected: false,
                        usb_lines: stash ? Number(stash.lines || 0) : 0,
                        usb_bytes: stash ? Number(stash.bytes || 0) : 0,
                        usb_parse_ok: stash ? Number(stash.parseOk || 0) : 0,
                        usb_parse_fail: stash ? Number(stash.parseFail || 0) : 0,
                        usb_baud: stash ? Number(stash.baud || 0) : 0,
                        usb_port: stash ? String(stash.port || '').slice(0, 60) : '',
                        usb_connected: stash ? !!stash.connected : false,
                        usb_last_raw: stash ? String(stash.lastRaw || '').slice(0, 120) : '',
                        api_reachable: false,
                    };
                    try {
                        const live = await fetch('/api/weighings/live?station=' + encodeURIComponent(this.station));
                        if (live.ok) {
                            const d = await live.json();
                            client.api_reachable = true;
                            client.backend_connected = !!d.connected;
                            client.page_connected = !!d.connected;
                        }
                        const st = await fetch('/api/bridge/status?station=' + encodeURIComponent(this.station));
                        if (st.ok) {
                            const d = await st.json();
                            client.bridge_reported = !!d.reported;
                            client.bridge_fresh = !!d.fresh;
                        }
                    } catch (e) {
                        client.api_reachable = false;
                    }
                    this.status = 'Bertanya ke AI…';
                    try {
                        const res = await fetch('/api/diagnose', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.station, client }),
                        });
                        if (!res.ok) throw new Error('http ' + res.status);
                        const d = await res.json();
                        this.result = d.diagnosis;
                        this.status = '';
                    } catch (e) {
                        this.status = 'Gagal menghubungi server diagnosa.';
                    }
                    this.checking = false;
                },
                init() {
                    const params = new URLSearchParams(window.location.search);
                    if (params.get('autocheck') === '1') {
                        // Hapus param dari URL SEKARANG juga: refresh tidak boleh
                        // dianggap perintah baru (biar tidak dobel vonis + dobel token).
                        window.history.replaceState({}, '', window.location.pathname);
                        this.runCheck();
                    }
                },
            };
        }
    </script>
@endsection
