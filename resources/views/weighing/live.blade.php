@extends('layouts.app')

@section('title', ($mode ?? 'mesin') === 'cam' ? 'Timbang Live Cam' : 'Timbang Live')

@section('content')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    {{-- Alpine + Chart dimuat lokal via @vite (resources/js/app.js) — jangan pakai CDN, PC pos timbang sering offline. --}}
    <style>
        .font-led { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        .text-led-green { color: #00FF88; }
        .glow-text-green { text-shadow: 0 0 12px rgba(0, 255, 136, 0.4); }
        .text-led-violet { color: #C4B5FD; }
        .glow-text-violet { text-shadow: 0 0 12px rgba(167, 139, 250, 0.45); }
        [x-cloak] { display: none !important; }
    </style>

    <div x-data="liveTimbang()" x-init="init()" x-cloak>
        @if(($mode ?? 'mesin') === 'cam')
            @include('weighing.partials.cam-header')
        @else
            @include('weighing.partials.mesin-header')
        @endif

        <div x-show="weightSource === 'usb'" x-transition class="mb-5 bg-slate-900 text-slate-200 px-4 py-3 rounded-lg border border-slate-700 shadow-sm text-xs font-mono">
            <div class="flex items-center justify-between mb-2">
                <span class="font-bold text-indigo-300">USB DEBUG <span class="text-slate-500 font-normal">— screenshot kalau angka tidak jalan</span></span>
                <div class="flex items-center gap-2">
                    <label class="text-slate-400">Baud:</label>
                    <select x-model.number="usbBaud" @change="onUsbBaudChange()" class="bg-slate-800 border border-slate-600 rounded px-1.5 py-1 text-xs">
                        <option value="4800">4800</option>
                        <option value="9600">9600</option>
                        <option value="19200">19200</option>
                        <option value="38400">38400</option>
                        <option value="115200">115200</option>
                    </select>
                    <button @click="usbDebugOpen = !usbDebugOpen" class="px-2 py-1 rounded border border-slate-600 text-slate-300" x-text="usbDebugOpen ? 'SEMBUNYIKAN' : 'DETAIL'"></button>
                </div>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px]">
                <span>baris: <strong class="text-emerald-400" x-text="usbLineCount"></strong></span>
                <span>bytes: <strong class="text-emerald-400" x-text="usbByteCount"></strong></span>
                <span>parse OK: <strong class="text-emerald-400" x-text="usbParseOk"></strong></span>
                <span>parse GAGAL: <strong class="text-rose-400" x-text="usbParseFail"></strong></span>
                <span>terakhir: <strong class="text-amber-300" x-text="usbLastRaw || '-'"></strong></span>
            </div>
            <div x-show="usbDebugOpen" class="mt-2 max-h-32 overflow-y-auto bg-black/50 rounded p-2 space-y-0.5">
                <template x-for="(l, i) in usbRawLines" :key="i">
                    <div><span :class="l.ok ? 'text-emerald-400' : 'text-rose-400'" x-text="l.ok ? 'OK   ' : 'FAIL '"></span><span class="text-slate-300" x-text="l.text"></span></div>
                </template>
                <div x-show="usbRawLines.length === 0" class="text-slate-500">belum ada data masuk...</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- LEFT: LED + CHART -->
            <div class="lg:col-span-7 space-y-5">
                <div id="ledPanel" data-mode="{{ $mode ?? 'mesin' }}" class="bg-[#050B07] border-2 border-slate-800 rounded-xl p-5 shadow-lg">
                    <div class="flex items-center justify-between border-b border-emerald-950 pb-2 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full" :class="canWeigh ? (isCamMode ? 'bg-violet-400 animate-pulse' : 'bg-emerald-400 animate-pulse') : 'bg-slate-700'"></span>
                            <span class="font-led text-xs font-bold uppercase tracking-wider" :class="canWeigh ? (isCamMode ? 'text-violet-300' : 'text-emerald-400') : 'text-slate-600'" x-text="isCamMode ? (svcRunning ? 'LIVE CAMERA OCR • ACTIVE' : 'CAMERA MODE • INPUT MANUAL') : 'LIVE DIGITAL INDICATOR • SENSOR SCALE 01'"></span>
                            <span id="ledMsg" class="font-led text-[11px] font-bold uppercase tracking-wider text-amber-400"></span>
                        </div>
                        <span class="text-[10px] font-mono text-slate-500" id="ledAge"></span>
                        <span class="text-[10px] font-mono text-slate-600" x-text="`• poll #${pollCount}`" title="Jumlah polling sejak halaman dibuka — harus naik tiap ~1.5 detik"></span>
                        <span id="extPoll" class="text-[10px] font-mono text-slate-600" title="Watchdog eksternal — harus naik tiap ~2 detik">• ext #0</span>
                        <span x-show="pollErr" class="text-[10px] font-mono text-rose-400" x-text="`ERR: ${pollErr}`"></span>
                    </div>

                    <div class="py-3 flex items-baseline justify-between">
                        <div class="font-led text-6xl sm:text-7xl lg:text-[80px] font-black tracking-tight leading-none select-none"
                             :class="canWeigh ? (isCamMode ? 'text-led-violet glow-text-violet' : 'text-led-green glow-text-green') : 'text-slate-700'">
                             <span id="ledValue">00.00</span>
                        </div>
                        <div class="font-led text-3xl font-bold uppercase" :class="canWeigh ? (isCamMode ? 'text-violet-400/80' : 'text-emerald-500/80') : 'text-slate-700'">KG</div>
                    </div>

                    <div class="mt-3 pt-3 border-t border-emerald-950/60 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 font-medium">Status Timbangan:</span>
                            <span id="ledStatus" class="inline-flex items-center gap-1"></span>
                        </div>
                        <div class="text-right text-slate-500 font-mono text-[11px]">Zero Tare: 0.00 kg</div>
                    </div>
                </div>

                <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold text-gray-700 uppercase">Fluktuasi Timbang 5 Detik Terakhir (50 Titik Sample)</span>
                        <span class="text-[10px] font-mono text-gray-400">100ms Streaming</span>
                    </div>
                    <div class="h-40 w-full"><canvas id="realtimeChart"></canvas></div>
                </div>
            </div>

            <!-- RIGHT: FORM + AUTO LOOP + RESULT -->
            <div class="lg:col-span-5 space-y-5">
                @if(($mode ?? 'mesin') === 'cam')
                    @include('weighing.partials.cam-form')
                @else
                    @include('weighing.partials.mesin-form')
                @endif

                <!-- SLOT STATUS LOOP (tinggi tetap): sampling / hasil / tunggu bergantian
                     mengisi slot ini — halaman tidak loncat saat panel ganti tiap siklus. -->
                <div x-show="autoLoop || isSampling || showResult" class="min-h-[320px]">
                <div x-show="isSampling" x-transition class="bg-blue-50 border border-blue-200 p-4 rounded-lg space-y-2">
                    <div class="flex justify-between text-xs font-bold text-blue-700">
                        <span x-text="'SAMPLING TRIMMED MEAN (' + samplingTotalSec + ' DETIK)'"></span>
                        <span class="font-mono text-sm" x-text="samplingCountdown + ' DETIK'"></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div class="bg-blue-600 h-full transition-all duration-100 ease-linear" :style="`width: ${samplingProgress}%`"></div>
                    </div>
                </div>

                <div x-show="!isSampling && showResult && resultData" x-transition class="bg-white p-5 rounded-lg border-2 border-blue-600 shadow-md space-y-4">
                    <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                        <span class="text-xs font-extrabold text-blue-700 uppercase">HASIL TRIMMED MEAN</span>
                        <span class="text-xs font-mono text-gray-500" x-text="resultData ? resultData.jam : ''"></span>
                    </div>
                    <div class="bg-blue-50/60 p-3 rounded border border-blue-100 text-center">
                        <span class="text-xs text-gray-500 font-semibold block">HASIL RATA-RATA NETTO</span>
                        <div id="resultNetto" class="font-mono text-4xl font-black text-blue-700" x-text="resultData ? resultData.netto.toFixed(2) + ' kg' : '0.00 kg'"></div>
                        <span class="text-xs font-mono text-gray-500 mt-0.5 block" x-text="resultData ? `Bruto: ${resultData.bruto.toFixed(2)} kg | Tare: ${resultData.tare.toFixed(2)} kg` : ''"></span>
                    </div>
                    <div class="flex items-center gap-2 bg-purple-50/60 p-3 rounded border border-purple-100">
                        <label class="text-xs text-gray-500 font-semibold whitespace-nowrap">Ekor/krj</label>
                        <input type="number" min="1" max="50" id="resultEkorInput" x-model.number="resultEkor" placeholder="cth: 8"
                               class="w-20 h-10 bg-white border border-purple-300 rounded-md px-2 font-mono font-bold text-base text-gray-800 text-center focus:ring-2 focus:ring-purple-500">
                        <div class="flex-1 text-center">
                            <span class="text-[10px] text-gray-400 block">RATA-RATA / EKOR</span>
                            <span id="resultAvgEkor" class="font-mono text-xl font-black text-purple-700">—</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center text-xs font-mono">
                        <div class="bg-gray-50 p-2 rounded border border-gray-200">
                            <span class="text-[10px] text-gray-400 block">MIN</span>
                            <span class="font-bold text-gray-700" x-text="resultData ? resultData.min.toFixed(2) + ' kg' : '-'"></span>
                        </div>
                        <div class="bg-gray-50 p-2 rounded border border-gray-200">
                            <span class="text-[10px] text-gray-400 block">MAX</span>
                            <span class="font-bold text-gray-700" x-text="resultData ? resultData.max.toFixed(2) + ' kg' : '-'"></span>
                        </div>
                        <div class="bg-gray-50 p-2 rounded border border-gray-200">
                            <span class="text-[10px] text-gray-400 block">SELISIH</span>
                            <span class="font-bold" :class="resultData && resultData.goyangWarning ? 'text-amber-600' : 'text-emerald-600'" x-text="resultData ? resultData.selisih.toFixed(2) + ' kg' : '-'"></span>
                        </div>
                    </div>
                    <template x-if="resultData && resultData.goyangWarning">
                        <div class="bg-amber-50 border border-amber-300 text-amber-900 p-3 rounded text-xs">
                            <span class="font-bold block">Goyangan Ekstrem!</span>
                            <p class="text-[11px]">Selisih fluktuasi &gt; 0.8 kg. Disarankan timbang ulang.</p>
                        </div>
                    </template>
                    <div x-show="!autoLoop" class="flex gap-2 pt-1">
                        <button @click="saveResult(false)" class="flex-1 h-11 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded shadow-sm">SIMPAN</button>
                        <button @click="resetResult()" class="h-11 px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-sm rounded border border-gray-300">ULANGI</button>
                    </div>
                </div>
                <!-- Status tunggu: mengisi slot saat loop jalan tapi tidak sampling,
                     jadi fase selalu kelihatan (penting saat hectic). -->
                <div x-show="autoLoop && !isSampling && !showResult" class="bg-emerald-50 border-2 border-emerald-300 rounded-lg p-5 text-center space-y-2">
                    <div class="text-[11px] font-bold text-emerald-600 tracking-widest">STATUS LOOP</div>
                    <div class="text-2xl font-black text-emerald-700 uppercase" x-text="autoStatusText"></div>
                    <div class="font-mono text-5xl font-black text-gray-900" x-text="liveDisplayFormatted + ' kg'"></div>
                    <div class="font-mono text-xs text-gray-600">Tercatat: <strong x-text="'#' + autoCount"></strong> • Total: <strong x-text="autoTotalNetto.toFixed(2) + ' kg'"></strong></div>
                </div>
                </div>
            </div>
        </div>

        <!-- TOAST -->
        <div x-show="showToast" x-transition class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-gray-900 text-white text-sm font-semibold px-5 py-3 rounded-lg shadow-xl" x-text="toastMessage"></div>
    </div>

    <script>
        // Chart.js instance disimpan di LUAR state Alpine. Class instance TIDAK
        // BOLEH masuk x-data: proxy reaktif Alpine + objek kompleks = infinite
        // recursion (RangeError: Maximum call stack size exceeded) yang terbukti
        // membekukan render setelah ~2x update. Ini biang "harus refresh manual".
        let chartInstance = null;
        // Data grafik JUGA di luar state Alpine (alasan sama: mutasi array
        // reaktif 10x/detik + Chart.js = stack overflow).
        let chartPoints = Array(50).fill(0);
        // Jendela 3 detik bobot terakhir (plain, bukan reaktif) buat deteksi
        // STABIL: trigger sampling hanya kalau sebaran <= 0.6 kg. Tanpa ini,
        // angka lagi NAIK/GUNCANG ikut ke-sampling → record dobel nonstop.
        let wWindow = [];
        // Cooldown 1 detik tiap habis sampling + jeda 1 detik tiap habis
        // tercatat (di-refresh di saveResult): biar tidak langsung sampling
        // lagi di tengah transisi (turun/naik) yang bikin hasil sampah.
        let loopCooldownUntil = 0;
        function __wStable() {
            // Hanya 10 sampel terakhir (±1 detik) yang dinilai. Kalau seluruh
            // jendela ikut dihitung, transisi penempatan (lonjakan belasan kg)
            // harus nunggu ke-flush ±2 detik padahal angka sudah pas — itu yang
            // bikin loading lama muncul. Gerak dikit (≤0.6 kg) tetap lolos.
            const n = Math.min(wWindow.length, 10);
            if (n < 2) return false;
            let mn = Infinity, mx = -Infinity;
            for (let i = wWindow.length - n; i < wWindow.length; i++) {
                const v = wWindow[i];
                if (v < mn) mn = v;
                if (v > mx) mx = v;
            }
            return (mx - mn) <= 0.6;
        }
        function liveTimbang() {
            return {
                dbOrders: @json($openOrders ?? []),
                dbBaskets: @json($baskets ?? []),
                dbStations: @json($stations ?? []),
                selectedStation: (@json($stations[0]->code ?? 'POS-01')),
                triggerKg: {{ $settings->auto_trigger_kg ?? 10 }},
                emptyKg: {{ $settings->auto_empty_kg ?? 2 }},
                unstableKg: {{ $settings->unstable_threshold_kg ?? 0.8 }},
                autoDurationSec: {{ (float) ($settings->auto_duration_sec ?? 2) }},
                manualDurationSec: {{ (int) ($settings->duration_sec ?? 5) }},
                mode: '{{ $mode ?? 'mesin' }}',

                isConnected: false,
                backendConnected: false,
                backendWeight: null,
                backendAge: null,
                pollCount: 0,
                pollErr: '',
                _pollBusy: false,
                _fastPollAt: 0,
                weightSource: 'bridge',
                usbSupported: (typeof navigator !== 'undefined' && 'serial' in navigator),
                usbConnected: false,
                usbPort: null,
                usbBaud: 9600,
                usbLastAt: 0,
                usbForwardAt: 0,
                usbDebugOpen: false,
                usbLineCount: 0,
                usbByteCount: 0,
                usbParseOk: 0,
                usbParseFail: 0,
                usbLastRaw: '',
                usbRawLines: [],
                liveRawWeight: 0,
                liveStatus: '—',
                selectedDo: '',

                noMobil: '',
                driverName: '',
                jenisAyam: 'biasa',

                selectedKeranjang: '',
                source: '{{ ($mode ?? 'mesin') === 'cam' ? 'cam' : 'mesin' }}',
                camManualKg: 0,
                ocrRunning: false,
                ocrMessage: '',
                ocrOk: false,
                svcRunning: false,
                svcStarting: false,
                svcPid: null,
                svcAge: null,
                svcWeight: null,
                svcFresh: null,
                svcRaw: '',
                svcDetail: '',
                svcBusy: false,
                camAuto: false,
                lastAutoTry: 0,
                lastManualStop: 0,
                
                // ROI Calibration (default = hasil ukur 27 Sep, font Arial)
                roiCalibrateOpen: false,
                roiX: 0.27,
                roiY: 0.50,
                roiW: 0.29,
                roiH: 0.16,
                roiSnapBusy: false,
                roiTestBusy: false,
                roiAutoBusy: false,
                roiCalBusy: false,
                roiSaveBusy: false,
                roiTestResult: '',
                roiTestWeight: null,
                
                autoLoop: false,
                autoArmed: true,
                resting: false,
                _discardStreak: 0,
                _saveFailStreak: 0,
                autoCount: 0,
                autoTotalNetto: 0,
                autoStart: false,
                autoStopped: false,
                lastBasketW: null,

                isSampling: false,
                samplingCountdown: 4,
                samplingTotalSec: 2,
                samplingProgress: 0,
                _sampleMaxAge: 0,
                _lastTransitToast: 0,
                sampleStartW: 0,
                samplesCollected: [],
                samplingTimer: null,
                showResult: false,
                resultData: null,
                resultEkor: '',
                toastMessage: '',
                showToast: false,

                get keranjangList() {
                    if (this.dbBaskets && this.dbBaskets.length) {
                        return this.dbBaskets.map(b => ({
                            id: b.code, dbId: b.id,
                            label: `K basket ${b.code}${b.size ? ' - ' + b.size : ''} - tare ${Number(b.tare_default_kg).toFixed(1)}kg`,
                            tare: Number(b.tare_default_kg),
                        }));
                    }
                    return [{ id: 'B01', dbId: null, label: 'K basket B01 - tare 2.8kg', tare: 2.8 }];
                },
                get orderLabel() {
                    const o = (this.dbOrders || []).find(x => String(x.id) === String(this.selectedDo));
                    return o ? `${o.number} — ${Number(o.target_kg).toFixed(2)} kg` : '—';
                },
                get bridgePillLabel() {
                    // CAM mode: status bridge (fallback mesin)
                    if (this.backendConnected) return `BRIDGE (${this.selectedStation})`;
                    return 'BRIDGE PUTUS';
                },
                get stations() {
                    if (this.dbStations && this.dbStations.length) return this.dbStations.map(s => s.code);
                    return ['POS-01'];
                },
                get isCamMode() {
                    return this.mode === 'cam';
                },
                get isCamStandalone() { 
                    // CAM mode: standalone kalau mesin putus, tapi CAM source jalan via OCR service
                    return this.isCamMode && (this.source === 'cam' || (!this.isConnected && this.svcRunning));
                },
                onStationChange() {
                    this.resetStream();
                    this.pollBackendLive();
                },
                setSource(next) {
                    // Di cam mode, source wajib 'cam' (disable toggle)
                    if (this.isCamMode) return;
                    if (next !== 'mesin' && next !== 'cam') return;
                    if (this.source === next) return;
                    this.source = next;
                    this.resetStream();
                    this.saveSession();
                    this.pollBackendLive();
                    this.triggerToast(next === 'cam' ? 'Sumber: CAM (kalibrasi)' : 'Sumber: MESIN');
                },
                resetStream() {
                    this.autoLoop = false;
                    this.autoStopped = false;
                    this.autoArmed = true;
                    this.resting = false;
                    this._discardStreak = 0;
                    this.autoCount = 0;
                    this.autoTotalNetto = 0;
                    this.isSampling = false;
                    this.showResult = false;
                    this.resultData = null;
                    this.liveRawWeight = 0;
                    this.liveStatus = '—';
                    chartPoints = Array(50).fill(0);
                    if (chartInstance) {
                        chartInstance.data.datasets[0].data = chartPoints;
                        chartInstance.update('none');
                    }
                },
                get activeTare() {
                    const k = this.keranjangList.find(x => x.id === this.selectedKeranjang);
                    return k ? k.tare : 2.8;
                },
                // CAM mode: bisa timbang kalau OCR service jalan ATAU ada input manual
                get isCamStandalone() { 
                    // CAM mode: standalone kalau mesin putus, tapi CAM source jalan via OCR service
                    return this.isCamMode && (this.source === 'cam' || (!this.isConnected && this.svcRunning));
                },
                get canWeigh() { 
                    // Mesin mode: bisa timbang kalau connected ke mesin
                    // Cam mode: bisa timbang kalau OCR service jalan ATAU ada input manual
                    if (this.isCamMode) {
                        return this.svcRunning || this.camManualKg > 0;
                    }
                    return this.isConnected || this.source === 'cam'; 
                },
                get effectiveRawWeight() { return this.isConnected ? this.liveRawWeight : (this.source === 'cam' ? this.camDisplayWeight : 0); },
                // LED cam: live backend (segar) ATAU input manual. TITIK.
                // Angka basi service JANGAN ditampilkan — kalau tidak ada data
                // ya tampil 00.00, biar jelas bedanya "aktif" vs "ada angka".
                get camDisplayWeight() {
                    if (this.isConnected) return this.liveRawWeight;
                    return Number(this.camManualKg) || 0;
                },
                get liveBruto() {
                    if (!this.canWeigh) return 0;
                    return Math.max(0, this.effectiveRawWeight + this.activeTare);
                },
                get liveDisplayFormatted() { return this.effectiveRawWeight.toFixed(2); },
                get connectionPillLabel() {
                    if (this.weightSource === 'usb') return this.usbConnected ? `USB DIRECT (${this.selectedStation})` : 'USB PUTUS';
                    return this.backendConnected ? `CONNECTED (${this.selectedStation})` : 'DISCONNECTED';
                },
                get autoStatusText() {
                    if (!this.canWeigh) return 'TIMBANGAN PUTUS';
                    if (this.isSampling) return `MENGHITUNG ${this.samplingCountdown} DETIK… JANGAN ANGKAT`;
                    if (this.resting) return 'JEDA SEBENTAR — SIAPKAN KERANJANG';
                    if (!this.autoArmed) return (this.lastBasketW === null && this.autoCount === 0)
                        ? 'MENUNGGU STABIL — TAHAN KERANJANG'
                        : 'TERCATAT — TARUH BERIKUTNYA';
                    // Sudah armed: kasih tahu KENAPA belum menghitung, biar
                    // operator tidak bengong (kasus kemarin: dikira nge-hang).
                    if (this.effectiveRawWeight <= this.triggerKg) return 'TARUH KERANJANG (>10 KG)';
                    if (!__wStable()) return 'MENUNGGU STABIL — TAHAN KERANJANG';
                    return 'SIAP — MENGHITUNG…';
                },

                async connectUsb() {
                    if (!('serial' in navigator)) {
                        this.triggerToast('Browser tidak mendukung USB langsung — pakai Chrome/Edge');
                        return;
                    }
                    try {
                        const port = await navigator.serial.requestPort();
                        await port.open({ baudRate: this.usbBaud || 9600, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' });
                        this.usbPort = port;
                        this.usbConnected = true;
                        this.weightSource = 'usb';
                        this.usbLastAt = Date.now();
                        this.isConnected = true;
                        this.liveStatus = 'STABIL';
                        this.triggerToast('USB tersambung — baca timbangan langsung, tanpa bridge');
                        this.readUsbLoop(port);
                        this.maybeAutoStart();
                    } catch (e) {
                        if (e && (e.name === 'NotFoundError' || e.name === 'AbortError')) return;
                        this.triggerToast('Gagal buka USB: ' + ((e && e.message) || e));
                    }
                },
                async disconnectUsb(silent) {
                    this.usbConnected = false;
                    this.autoStopped = false;
                    if (this.weightSource === 'usb') {
                        this.weightSource = 'bridge';
                        this.isConnected = false;
                    }
                    const port = this.usbPort;
                    this.usbPort = null;
                    try { if (port) await port.close(); } catch (e) {}
                    if (!silent) {
                        this.triggerToast('USB diputus — kembali ke mode bridge');
                        this.pollBackendLive();
                    }
                },
                onUsbBaudChange() {
                    if (this.weightSource === 'usb') {
                        this.triggerToast('Baud diganti — hubungkan ulang USB');
                        this.disconnectUsb();
                    }
                },
                parseUsbLine(line) {
                    if (!line) return null;
                    // BX1 asli kirim byte kontrol STX (0x02), BUKAN teks "STX".
                    // Buang semua karakter kontrol dulu, baru cari angka.
                    // Urutan: pola STX → pola "angka kg" → angka desimal terakhir.
                    // (disamakan dengan fallback bridge/reader.py)
                    const clean = line.replace(/[\x00-\x1F\x7F]/g, ' ').trim();
                    if (!clean) return null;
                    let m = clean.match(/STX\s+(-?[\d.]+)\s*kg/i);
                    if (!m) m = clean.match(/(-?[\d.]+)\s*kg/i);
                    if (!m) {
                        const all = clean.match(/-?\d+\.\d+/g);
                        if (all && all.length) m = [null, all[all.length - 1]];
                    }
                    if (!m) return null;
                    const v = Number(m[1]);
                    return Number.isFinite(v) ? v : null;
                },
                async forwardUsbWeight(w) {
                    const now = Date.now();
                    if (now - this.usbForwardAt < 500) return;
                    this.usbForwardAt = now;
                    try {
                        const params = new URLSearchParams();
                        params.append('weight', Number(w.toFixed(3)));
                        params.append('station', this.selectedStation);
                        const res = await fetch('/api/weighings/live', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: params.toString(),
                        });
                        if (!res.ok) console.warn('forward fail', res.status);
                    } catch (e) {
                        if (this.usbDebugOpen) console.warn('forward error', e);
                    }
                },
                stashUsbDebug() {
                    // Titipkan snapshot USB ke penyimpanan browser (max 1x/detik)
                    // agar halaman Bug Log bisa membacanya untuk AI.
                    const now = Date.now();
                    if (now - (this.usbStashAt || 0) < 1000) return;
                    this.usbStashAt = now;
                    try {
                        let portLabel = '';
                        try {
                            const info = this.usbPort && this.usbPort.getInfo ? this.usbPort.getInfo() : null;
                            if (info) portLabel = [(info.usbVendorId ? 'vid:' + Number(info.usbVendorId).toString(16) : ''), (info.usbProductId ? 'pid:' + Number(info.usbProductId).toString(16) : '')].filter(Boolean).join(' ');
                        } catch (e) {}
                        localStorage.setItem('timbang_usb_debug', JSON.stringify({
                            lines: this.usbLineCount,
                            bytes: this.usbByteCount,
                            parseOk: this.usbParseOk,
                            parseFail: this.usbParseFail,
                            lastRaw: this.usbLastRaw,
                            baud: this.usbBaud,
                            port: portLabel,
                            source: this.weightSource,
                            connected: this.usbConnected,
                            at: now,
                        }));
                    } catch (e) {}
                },
                async readUsbLoop(port) {
                    const decoder = new TextDecoder();
                    let buf = '';
                    try {
                        while (port.readable && this.usbPort === port) {
                            const reader = port.readable.getReader();
                            try {
                                for (;;) {
                                    if (this.usbPort !== port) break;
                                    const { value, done } = await reader.read();
                                    if (done) break;
                                    if (value) {
                                        this.usbByteCount += (value.byteLength || value.length || 0);
                                        buf += decoder.decode(value, { stream: true });
                                        const parts = buf.split(/\r?\n/);
                                        buf = parts.pop();
                                        for (const line of parts) {
                                            const t = line.trim();
                                            if (!t) continue;
                                            this.usbLineCount++;
                                            this.usbLastRaw = t.length > 60 ? t.slice(0, 60) + '...' : t;
                                            const w = this.parseUsbLine(t);
                                            this.usbRawLines.push({ text: this.usbLastRaw, ok: w !== null });
                                            if (this.usbRawLines.length > 8) this.usbRawLines.shift();
                                            if (w === null) { this.usbParseFail++; } else { this.usbParseOk++; }
                                            this.stashUsbDebug();
                                            if (w === null) continue;
                                            if (this.usbPort === port) {
                                                this.usbLastAt = Date.now();
                                                this.liveRawWeight = w;
                                                this.liveStatus = 'STABIL';
                                                this.isConnected = true;
                                                this.forwardUsbWeight(w);
                                            }
                                        }
                                    }
                                }
                            } finally {
                                reader.releaseLock();
                            }
                            await new Promise(r => setTimeout(r, 300));
                        }
                    } catch (e) {
                        if (this.usbPort === port) {
                            this.triggerToast('USB terputus — cek kabel');
                            this.disconnectUsb();
                        }
                    }
                },
                async pollBackendLive() {
                    if (this.weightSource === 'usb') return;
                    // Cegah request numpuk (penting saat fast-poll sampling):
                    // artisan serve single-thread, antrean = semua macet.
                    if (this._pollBusy) return;
                    this._pollBusy = true;
                    const was = this.isConnected;
                    // Timeout 10 dtk: fetch yang nyangkut selamanya bikin polling
                    // kelihatan mati (tidak sukses, tidak error). Abort biar ketahuan.
                    const ctl = new AbortController();
                    const to = setTimeout(() => ctl.abort(), 10000);
                    try {
                        const res = await fetch('/api/weighings/live?station=' + encodeURIComponent(this.selectedStation) + '&source=' + encodeURIComponent(this.source), { signal: ctl.signal });
                        if (!res.ok) throw new Error('http ' + res.status);
                        const data = await res.json();
                        this.backendConnected = !!data.connected;
                        this.backendWeight = (data.weight === null || data.weight === undefined) ? null : Number(data.weight);
                        this.backendAge = data.age_seconds;
                        this.pollCount++;
                        window.__pollN = (window.__pollN || 0) + 1;
                        this.pollErr = '';
                        // Titip payload + render LED langsung di tick yang sama:
                        // angka LED dan state loop selalu bareng, tanpa fetch dobel.
                        window.__lastLivePayload = data;
                        window.__lastAlpinePollAt = Date.now();
                        try { __renderLed(data); } catch (e) {}
                        this.isConnected = this.backendConnected;
                        if (!this.isConnected) {
                            this.autoStopped = false;
                        } else if (!was) {
                            this.maybeAutoStart();
                        }
                        if (this.backendWeight !== null) {
                            this.liveRawWeight = this.backendWeight;
                            this.liveStatus = (this.backendAge !== null && this.backendAge > 2) ? 'MACET' : 'STABIL';
                        } else {
                            this.liveRawWeight = 0;
                            this.liveStatus = '—';
                        }
                    } catch (e) {
                        this.backendConnected = false;
                        this.backendWeight = null;
                        this.pollErr = (e && e.name === 'AbortError') ? 'timeout 10s' : String((e && e.message) || e).slice(0, 80);
                        this.isConnected = false;
                        this.autoStopped = false;
                        this.liveRawWeight = 0;
                        this.liveStatus = '—';
                    } finally {
                        clearTimeout(to);
                        this._pollBusy = false;
                    }
                },
                async recheckConnection() {
                    if (this.weightSource === 'usb') {
                        this.triggerToast(this.usbConnected ? 'USB tersambung — baca timbangan langsung' : 'USB putus — klik HUBUNGKAN USB');
                        return;
                    }
                    await this.pollBackendLive();
                    if (this.backendConnected) {
                        this.triggerToast('Timbangan Terhubung (live)');
                    } else if (this.source === 'cam') {
                        this.triggerToast('Mesin putus — mode CAM mandiri aktif, isi angka manual');
                    } else {
                        this.triggerToast('Timbangan Terputus — cek kabel + bridge reader.py');
                    }
                },
                triggerToast(msg) {
                    this.toastMessage = msg;
                    this.showToast = true;
                    setTimeout(() => { this.showToast = false; }, 3000);
                },
                async captureOcr() {
                    if (this.ocrRunning) return;
                    this.ocrRunning = true;
                    this.ocrMessage = 'Mengambil foto + OCR… (10-30 detik, CPU)';
                    this.ocrOk = false;
                    try {
                        const res = await fetch('/api/cam/capture', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation }),
                        });
                        const data = await res.json();
                        if (data.ocr_weight !== null && data.ocr_weight !== undefined) {
                            this.camManualKg = Number(data.ocr_weight);
                            this.ocrOk = true;
                            this.ocrMessage = `OCR: ${Number(data.ocr_weight).toFixed(2)} kg → tersimpan (source=cam). Cek LED / Riwayat.`;
                            this.triggerToast(`OCR dapat: ${Number(data.ocr_weight).toFixed(2)} kg`);
                        } else {
                            this.ocrOk = false;
                            this.ocrMessage = 'OCR gagal dapat angka. ' + (data.log || '').split('\n').slice(-2).join(' ');
                        }
                        await this.pollBackendLive();
                    } catch (e) {
                        this.ocrOk = false;
                        this.ocrMessage = 'Gagal hubungi server OCR — coba lagi.';
                    }
                    this.ocrRunning = false;
                },
                async refreshServiceStatus() {
                    const ctl = new AbortController();
                    const to = setTimeout(() => ctl.abort(), 10000);
                    try {
                        const res = await fetch('/api/cam/service/status?station=' + encodeURIComponent(this.selectedStation), { signal: ctl.signal });
                        const data = await res.json();
                        this.svcRunning = !!data.running;
                        this.svcStarting = !!data.starting && !data.running;
                        this.svcPid = data.pid;
                        this.svcAge = data.last_age_seconds;
                        this.svcWeight = data.last_weight;
                        this.svcFresh = !!data.fresh;
                        this.svcRaw = data.last_raw || '';
                        this.svcDetail = data.svc_detail || '';
                        if (typeof data.cam_auto === 'boolean') this.camAuto = data.cam_auto;
                        // Mode AUTO: service mati -> nyalakan lagi diam-diam (max 1x per 30 dtk,
                        // jeda 120 dtk setelah STOP manual biar tidak rebutan).
                        if (this.camAuto && !this.svcRunning && !this.svcStarting) {
                            const now = Date.now();
                            if (now - this.lastAutoTry > 30000 && now - this.lastManualStop > 120000) {
                                this.lastAutoTry = now;
                                this.autoStartCamService();
                            }
                        }
                    } catch (e) {}
                    finally { clearTimeout(to); }
                },
                async autoStartCamService() {
                    // Seperti START tapi diam: tanpa busy/reload/toast sukses.
                    try {
                        const res = await fetch('/api/cam/service/start', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation, interval: 0.1 }),
                        });
                        const data = await res.json();
                        if (data.status === 'error') {
                            this.triggerToast('AUTO gagal start: ' + (data.message || 'cek log'));
                        }
                    } catch (e) {}
                    await this.refreshServiceStatus();
                },
                async toggleCamAuto() {
                    const next = !this.camAuto;
                    try {
                        const res = await fetch('/api/cam/service/auto', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ enabled: next }),
                        });
                        const data = await res.json();
                        this.camAuto = !!data.cam_auto;
                        this.triggerToast(this.camAuto ? 'AUTO nyala — service mati bakal dinyalakan lagi (selama halaman ini kebuka)' : 'AUTO mati');
                        if (this.camAuto) {
                            this.lastAutoTry = 0;
                            this.lastManualStop = 0;
                            await this.refreshServiceStatus();
                        }
                    } catch (e) {
                        this.triggerToast('Gagal ubah mode AUTO');
                    }
                },
                async startCamService() {
                    if (this.svcBusy) return;
                    this.svcBusy = true;
                    // Optimistic: tampilkan STARTING langsung, jangan tunggu network.
                    this.svcRunning = false;
                    this.svcStarting = true;
                    // Langsung tampilkan/refresh preview + cek kamera saat START diklik.
                    window.dispatchEvent(new CustomEvent('cam-retry'));
                    this.triggerToast('Starting OCR service… model di-load ±20 dtk');
                    try {
                        const res = await fetch('/api/cam/service/start', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation, interval: 0.1 }),
                        });
                        const data = await res.json();
                        if (data.status === 'running') {
                            this.triggerToast('Service JALAN (PID ' + data.pid + ') — tunggu angka ngalir');
                        } else if (data.status === 'starting') {
                            this.triggerToast('Service STARTING (PID ' + data.pid + ') — load model ±20 dtk, status dipoll otomatis');
                        } else {
                            this.triggerToast('Gagal start: ' + (data.message || 'cek log'));
                        }
                    } catch (e) {
                        this.triggerToast('Gagal hubungi server');
                    }
                    await this.refreshServiceStatus();
                    this.svcBusy = false;
                    // Reload biar status STARTING/JALAN kelihatan pasti (render
                    // kadang macet di tab ini). Sesi form tersimpan di localStorage.
                    this.triggerToast('Memuat ulang status…');
                    setTimeout(() => location.reload(), 800);
                },
                async stopCamService() {
                    if (this.svcBusy) return;
                    this.svcBusy = true;
                    // STOP manual = niat eksplisit: matikan juga mode AUTO + catat waktu
                    // biar auto-start tidak langsung menyalakan lagi.
                    this.lastManualStop = Date.now();
                    if (this.camAuto) {
                        try {
                            await fetch('/api/cam/service/auto', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ enabled: false }),
                            });
                        } catch (e) {}
                        this.camAuto = false;
                        this.triggerToast('Mode AUTO dimatikan');
                    }
                    // Optimistic: tampilkan BERHENTI langsung, jangan tunggu network.
                    // Poll status tiap 5 dtk akan koreksi kalau ternyata gagal.
                    this.svcRunning = false;
                    this.svcStarting = false;
                    this.triggerToast('Menghentikan service…');
                    try {
                        await fetch('/api/cam/service/stop', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation }),
                        });
                        this.triggerToast('Service STOP');
                    } catch (e) {}
                    await this.refreshServiceStatus();
                    this.svcBusy = false;
                    // Reload biar status BERHENTI kelihatan pasti.
                    this.triggerToast('Service STOP — memuat ulang…');
                    setTimeout(() => location.reload(), 800);
                },

                // ROI Calibration Functions
                openRoiCalibrate() {
                    this.roiCalibrateOpen = true;
                    this.roiTestResult = '';
                    this.roiTestWeight = null;
                    this.pullRoi();
                },
                async pullRoi() {
                    // Tarik ROI tersimpan dari server → isi form modal.
                    try {
                        const res = await fetch('/api/cam/service/status?station=' + encodeURIComponent(this.selectedStation));
                        const data = await res.json();
                        if (data.roi) {
                            const p = String(data.roi).split(',').map(Number);
                            if (p.length === 4 && p.every(Number.isFinite)) {
                                [this.roiX, this.roiY, this.roiW, this.roiH] = p;
                            }
                        }
                    } catch (e) {}
                },
                async captureRoiSnapshot() {
                    if (this.roiSnapBusy) return;
                    this.roiSnapBusy = true;
                    try {
                        // Call capture API to get fresh snapshot
                        const res = await fetch('/api/cam/capture', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                station: this.selectedStation,
                                roi: `${this.roiX},${this.roiY},${this.roiW},${this.roiH}`
                            }),
                        });
                        const data = await res.json();
                        if (data.ocr_weight) {
                            this.camManualKg = Number(data.ocr_weight);
                            this.roiTestResult = `Snapshot berhasil: ${Number(data.ocr_weight).toFixed(2)} kg`;
                            this.roiTestWeight = Number(data.ocr_weight);
                        } else {
                            this.roiTestResult = `Snapshot gagal: ${data.log || 'tidak ada angka'}`;
                            this.roiTestWeight = null;
                        }
                    } catch (e) {
                        this.roiTestResult = `Error: ${e.message}`;
                        this.roiTestWeight = null;
                    }
                    this.roiSnapBusy = false;
                },
                async autoRoi() {
                    if (this.roiAutoBusy) return;
                    this.roiAutoBusy = true;
                    this.roiTestResult = 'Mendeteksi display… (jangan tutup modal, ±20 detik)';
                    this.roiTestWeight = null;
                    try {
                        const res = await fetch('/api/cam/roi/auto', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation }),
                        });
                        const data = await res.json();
                        if (data.status === 'done' && data.roi) {
                            const p = String(data.roi).split(',').map(Number);
                            if (p.length === 4 && p.every(Number.isFinite)) {
                                [this.roiX, this.roiY, this.roiW, this.roiH] = p;
                            }
                            this.roiTestResult = `AUTO ROI: ${data.roi} (baca: ${data.text || '?'}) → klik TEST OCR, kalau pas klik SIMPAN.`;
                            this.triggerToast('ROI terisi otomatis — TEST dulu baru SIMPAN');
                        } else {
                            this.roiTestResult = `Deteksi gagal: ${data.message || ''} ${(data.log || '').split('\n').slice(-2).join(' ')}`;
                        }
                    } catch (e) {
                        this.roiTestResult = `Error: ${e.message}`;
                    }
                    this.roiAutoBusy = false;
                },
                async autoCalibrate() {
                    if (this.roiCalBusy) return;
                    if (!confirm('Kalibrasi full-mandiri: service di-STOP, deteksi 4 arah (±2-4 mnt, halaman jangan ditutup), ROI+Putar disimpan otomatis, service START lagi. Lanjut?')) return;
                    this.roiCalBusy = true;
                    this.roiTestResult = 'Kalibrasi mandiri jalan… JANGAN tutup halaman (±2-4 menit).';
                    this.roiTestWeight = null;
                    try {
                        const ctl = new AbortController();
                        const to = setTimeout(() => ctl.abort(), 450000);
                        const res = await fetch('/api/cam/roi/autocal', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ station: this.selectedStation }),
                            signal: ctl.signal,
                        });
                        clearTimeout(to);
                        const data = await res.json();
                        if (data.status === 'done' && data.roi) {
                            const p = String(data.roi).split(',').map(Number);
                            if (p.length === 4 && p.every(Number.isFinite)) {
                                [this.roiX, this.roiY, this.roiW, this.roiH] = p;
                            }
                            this.roiTestResult = `FULL AUTO OK: putar ${data.rotate}°, ROI ${data.roi} (baca: ${data.text || '?'})${data.restarted ? ' — service jalan lagi' : (data.was_running ? ' — service GAGAL restart, START manual!' : '')}`;
                            this.triggerToast('Kalibrasi mandiri selesai');
                            this.refreshServiceStatus();
                        } else {
                            this.roiTestResult = `Kalibrasi gagal: ${data.message || ''} ${(data.log || '').split('\n').slice(-3).join(' ')}`;
                        }
                    } catch (e) {
                        this.roiTestResult = `Error: ${e.message}`;
                    }
                    this.roiCalBusy = false;
                },
                async testRoiOcr() {                    if (this.roiTestBusy) return;
                    this.roiTestBusy = true;
                    try {
                        // Test ROI dengan OCR
                        const res = await fetch('/api/cam/capture', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                station: this.selectedStation,
                                roi: `${this.roiX},${this.roiY},${this.roiW},${this.roiH}`
                            }),
                        });
                        const data = await res.json();
                        if (data.ocr_weight) {
                            this.roiTestResult = `OCR test berhasil: ${Number(data.ocr_weight).toFixed(2)} kg (${data.log || ''})`;
                            this.roiTestWeight = Number(data.ocr_weight);
                            this.camManualKg = Number(data.ocr_weight);
                        } else {
                            this.roiTestResult = `OCR gagal: ${data.log || 'tidak ada angka'}`;
                            this.roiTestWeight = null;
                        }
                    } catch (e) {
                        this.roiTestResult = `Error: ${e.message}`;
                        this.roiTestWeight = null;
                    }
                    this.roiTestBusy = false;
                },
                async saveRoi() {
                    if (this.roiSaveBusy) return;
                    this.roiSaveBusy = true;
                    try {
                        // Save ROI to server settings (untuk pos ini)
                        const res = await fetch('/api/cam/service/roi', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                station: this.selectedStation,
                                roi: `${this.roiX},${this.roiY},${this.roiW},${this.roiH}`
                            }),
                        });
                        const data = await res.json();
                        if (data.status === 'saved') {
                            this.triggerToast(`ROI disimpan: ${this.roiX.toFixed(2)},${this.roiY.toFixed(2)},${this.roiW.toFixed(2)},${this.roiH.toFixed(2)}`);
                            this.roiCalibrateOpen = false;
                        } else {
                            this.triggerToast(`Gagal simpan ROI: ${data.message || 'unknown'}`);
                        }
                    } catch (e) {
                        this.triggerToast(`Error: ${e.message}`);
                    }
                    this.roiSaveBusy = false;
                },

                restoreSession() {
                    try {
                        const s = JSON.parse(localStorage.getItem('timbang_session') || '{}');
                        if (s.do) this.selectedDo = s.do;
                        if (s.vehicle) this.noMobil = s.vehicle;
                        if (s.driver) this.driverName = s.driver;
                        if (s.jenis) this.jenisAyam = s.jenis;
                        if (s.basket) this.selectedKeranjang = s.basket;
                        if (s.station && this.stations.includes(s.station)) this.selectedStation = s.station;
                        const a = localStorage.getItem('timbang_autostart_v2');
                        if (a !== null) this.autoStart = (a === '1');
                    } catch (e) {}
                },
                saveSession() {
                    try {
                        localStorage.setItem('timbang_session', JSON.stringify({
                            do: this.selectedDo,
                            vehicle: this.noMobil,
                            driver: this.driverName,
                            jenis: this.jenisAyam,
                            basket: this.selectedKeranjang,
                            station: this.selectedStation,
                        }));
                        localStorage.setItem('timbang_autostart_v2', this.autoStart ? '1' : '0');
                    } catch (e) {}
                },
                sessionComplete() {
                    // Cam: cukup DO + keranjang (plat/supir diisi sesi, bukan di sini).
                    return !!(this.selectedDo && this.selectedKeranjang && (this.isCamMode || this.noMobil));
                },
                startAuto(manual) {
                    if (!this.canWeigh) {
                        if (manual) this.triggerToast('Tidak ada sumber angka — konekkan mesin atau pindah ke CAM');
                        return false;
                    }
                    if (!this.sessionComplete()) {
                        if (manual) this.triggerToast(this.isCamMode ? 'Isi No. DO dan Keranjang dulu' : 'Isi No. DO, No. Mobil, dan Keranjang dulu');
                        return false;
                    }
                    this.autoLoop = true;
                    this.autoStopped = false;
                    this.autoArmed = true;
                    this.resting = false;
                    this._discardStreak = 0;
                    this._saveFailStreak = 0;
                    wWindow.length = 0;
                    loopCooldownUntil = 0;
                    this.autoCount = 0;
                    this.autoTotalNetto = 0;
                    this.saveSession();
                    this.triggerToast(manual ? 'Auto loop JALAN — taruh keranjang pertama' : 'Konek — auto jalan sendiri, taruh keranjang');
                    return true;
                },
                maybeAutoStart() {
                    if (!this.autoStart || this.autoLoop || this.autoStopped) return;
                    if (!this.canWeigh || !this.sessionComplete()) return;
                    this.startAuto(false);
                },
                init() {
                    // Hook buat watchdog eksternal (script polos di bawah): tetap bisa
                    // manggil poll walau timer Alpine bermasalah. DIPASANG PALING DULU.
                    window.__livePoll = () => { try { this.pollBackendLive(); } catch (e) {} };
                    // Timer DIPASANG PALING DULU: kalau ada error di initChart /
                    // restoreSession di bawah, polling tetap jalan.
                    this.pollBackendLive();
                    // Poll 1.5 dtk (BUKAN 500ms): php artisan serve itu single-thread,
                    // polling 2x/detik bikin antrean request — semua tombol (termasuk
                    // STOP) terasa macet. LED cam update tiap ~5 dtk, jadi 1.5 dtk cukup.
                    // Tab tidak aktif → skip polling biar antrean tidak numpuk.
                    setInterval(() => { if (!document.hidden) this.pollBackendLive(); }, 1000);
                    this.refreshServiceStatus();
                    setInterval(() => this.refreshServiceStatus(), 5000);
                    try { this.initChart(); } catch (e) { console.warn('chart off', e); }
                    try { this.restoreSession(); } catch (e) {}
                    this.isConnected = false;
                    window.addEventListener('beforeunload', () => { try { if (this.usbPort) this.usbPort.close(); } catch (e) {} });
                    setInterval(() => {
                        if (this.weightSource === 'usb' && this.usbConnected && Date.now() - this.usbLastAt > 3000) {
                            this.isConnected = false;
                            this.autoStopped = false;
                        }
                        if (!this.canWeigh) return;
                        const w = this.effectiveRawWeight;
                        if (chartInstance) {
                            chartPoints.shift();
                            chartPoints.push(parseFloat(Number(w).toFixed(2)));
                            chartInstance.data.datasets[0].data = chartPoints;
                            chartInstance.update('none');
                        }
                        if (this.isSampling) {
                            // Cam mandiri tanpa stream: beri goyangan kecil biar statistik hidup.
                            if (this.isCamStandalone) {
                                this.samplesCollected.push(w + (Math.random() - 0.5) * 0.06);
                            } else {
                                this.samplesCollected.push(this.liveRawWeight);
                            }
                            // Umur data tertua selama sampling: kalau daemon macet,
                            // semua sampel = angka basi yang sama → tolak di finish.
                            const _ag = Number(this.backendAge);
                            if (!isNaN(_ag)) this._sampleMaxAge = Math.max(this._sampleMaxAge || 0, _ag);
                            // Fast-poll khusus saat sampling: liveRawWeight normalnya
                            // refresh tiap 1.5 dtk (cuma ~2 data unik per sampling 2 dtk).
                            // Selama sampling, tarik tiap ~450ms -> ~5 data unik,
                            // trimmed mean + min/max/stddev jadi bermakna.
                            if (!document.hidden && !this._pollBusy && Date.now() - (this._fastPollAt || 0) > 100) {
                                this._fastPollAt = Date.now();
                                this.pollBackendLive();
                            }
                        }
                        this.tickAutoLoop();
                    }, 100);
                },

                initChart() {
                    const ctx = document.getElementById('realtimeChart');
                    if (!ctx || typeof Chart === 'undefined') return;
                    if (chartInstance) chartInstance.destroy();
                    chartInstance = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: Array.from({length: 50}, (_, i) => `${(i * 0.1).toFixed(1)}s`),
                            datasets: [{
                                label: 'Netto Live (KG)',
                                data: chartPoints,
                                borderColor: '#1976D2', borderWidth: 2,
                                backgroundColor: 'rgba(25, 118, 210, 0.06)',
                                fill: true, tension: 0.35, pointRadius: 0
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false, animation: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { display: false },
                                y: { grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', font: { size: 10 } } }
                            }
                        }
                    });
                },

                toggleAutoLoop() {
                    if (this.autoLoop) {
                        this.autoLoop = false;
                        this.autoStopped = true;
                        this.resting = false;
                        this.saveSession();
                        this.triggerToast(`Auto loop STOP — ${this.autoCount} keranjang, ${this.autoTotalNetto.toFixed(2)} kg`);
                        return;
                    }
                    this.startAuto(true);
                },
                tickAutoLoop() {
                    if (!this.autoLoop || this.isSampling) return;
                    const w = this.effectiveRawWeight;
                    // Lompatan >3 kg = transisi (baru ditaruh / baru diangkat):
                    // buang riwayat lama biar penilaian stabil mulai dari angka
                    // segar — trigger langsung jalan begitu angka pas, tanpa
                    // nunggu jendela lama ke-flush.
                    if (wWindow.length && Math.abs(w - wWindow[wWindow.length - 1]) > 3) wWindow.length = 0;
                    wWindow.push(w);
                    if (wWindow.length > 20) wWindow.shift();
                    if (this.autoArmed && w > this.triggerKg && __wStable() && Date.now() >= loopCooldownUntil
                        // Jangan trigger pas angka lagi TURUN (baru diangkat, lewat
                        // zona trigger): awal jendela > ujung 1kg = gerak turun.
                        && (wWindow.length < 2 || (wWindow[0] - w) <= 1.0)) {
                        // Anti-dobel DIMATIKAN (permintaan operator 9 Okt): tiap stabil
                        // >10 kg langsung sampling, walau beratnya sama persis
                        // dengan catatan terakhir. Jeda 2.5 dtk habis save + syarat
                        // stabil tetap jadi rem alami biar tidak beruntun.
                        this.autoArmed = false;
                        this.startSampling();
                    } else if (!this.autoArmed && !this.showResult
                        // Re-arm kalau: kosong (diangkat habis) ATAU turun >5 kg
                        // dari keranjang terakhir ATAU jeda habis (tanpa wajib NOL —
                        // operator boleh langsung taruh berikutnya).
                        && (w < this.emptyKg || (this.lastBasketW !== null && w < this.lastBasketW - 5)
                            || Date.now() >= loopCooldownUntil
                            // Belum pernah tercatat sesi ini + angka stabil tinggi:
                            // dead-end (batal/gagal) boleh coba lagi, bukan macet.
                            // Aman dari dobel: lastBasketW null = belum ada yg tersimpan.
                            || (this.lastBasketW === null && w > this.triggerKg && __wStable() && Date.now() >= loopCooldownUntil))) {
                        this.autoArmed = true;
                        wWindow.length = 0;
                    }
                },

                startSampling() {
                    if (this.isSampling || !this.canWeigh) return;
                    const w = this.effectiveRawWeight;
                    if (this.autoLoop && w <= this.triggerKg) return;
                    if (!this.selectedDo || !this.selectedKeranjang || (!this.isCamMode && !this.noMobil)) {
                        this.triggerToast(this.isCamMode ? 'Isi No. DO dan Keranjang dulu' : 'Isi No. DO, No. Mobil, dan Keranjang dulu');
                        return;
                    }
                    if (!this.autoLoop && w <= 0) {
                        this.triggerToast('Angka masih nol — tunggu LED ada angka dulu');
                        return;
                    }
                    this.isSampling = true;
                    this.showResult = false;
                    this.resultData = null;
                    this.resultEkor = '';
                    this._sampleMaxAge = 0;
                    // Tarik 1x langsung saat sampling mulai (biar t=0 segar,
                    // tidak nunggu jadwal poll 1.5 dtk berikutnya).
                    this.pollBackendLive();
                    this.sampleStartW = w;
                    this.samplesCollected = [];
                    // Durasi sampling ngikut Pengaturan (auto: auto_duration_sec,
                    // manual: duration_sec) — ganti di /settings tanpa edit kode.
                    const durSec = this.autoLoop ? this.autoDurationSec : this.manualDurationSec;
                    this.samplingCountdown = durSec;
                    this.samplingTotalSec = durSec;
                    this.samplingProgress = 0;
                    const totalTimeMs = durSec * 1000;
                    const startTime = Date.now();
                    this.samplingTimer = setInterval(() => {
                        const elapsed = Date.now() - startTime;
                        // Batal otomatis kalau keranjang diangkat tengah sampling
                        // (turun >5 kg dari awal): jangan simpan sampah transit.
                        if (Number(this.effectiveRawWeight) < this.sampleStartW - 5) {
                            clearInterval(this.samplingTimer);
                            this.isSampling = false;
                            this.samplesCollected = [];
                            if (Date.now() - (this._lastTransitToast || 0) > 5000) {
                                this._lastTransitToast = Date.now();
                                this.triggerToast('Batal — keranjang diangkat duluan');
                            }
                            if (this.autoLoop) this.autoArmed = false;
                            return;
                        }
                        this.samplingProgress = Math.min(100, (elapsed / totalTimeMs) * 100);
                        this.samplingCountdown = Math.max(0, Math.ceil((totalTimeMs - elapsed) / 1000));
                        if (elapsed >= totalTimeMs) {
                            clearInterval(this.samplingTimer);
                            this.finishSampling();
                        }
                    }, 50);
                },
                finishSampling() {
                    this.isSampling = false;
                    if (this.samplesCollected.length === 0) {
                        this.triggerToast('Tidak ada sampel — cek koneksi timbangan');
                        if (this.autoLoop) this.autoArmed = false;
                        return;
                    }
                    const sorted = [...this.samplesCollected].sort((a, b) => a - b);
                    const n = sorted.length;
                    const trimCount = Math.floor(n * 0.1);
                    const trimmed = sorted.slice(trimCount, n - trimCount);
                    const brutoMean = trimmed.reduce((s, v) => s + v, 0) / trimmed.length;
                    const tare = this.activeTare;
                    const minVal = Math.min(...this.samplesCollected);
                    const maxVal = Math.max(...this.samplesCollected);
                    const selisih = maxVal - minVal;
                    // Stabilitas dinilai dari data TRIMMED, bukan mentah: 1-2
                    // bacaan hantu (poll telat / 1x OCR ngaco di tengah set yang
                    // sudah anteng) tidak ikut mem-veto. Inilah yang bikin kemarin
                    // "LED sudah berhenti tapi tetap sampling ulang ±5 kg".
                    // min/max mentah tetap disimpan apa adanya untuk audit.
                    const tMin = trimmed.length ? Math.min(...trimmed) : minVal;
                    const tMax = trimmed.length ? Math.max(...trimmed) : maxVal;
                    const tSelisih = tMax - tMin;
                    const meanAll = this.samplesCollected.reduce((s, v) => s + v, 0) / n;
                    const stddev = Math.sqrt(this.samplesCollected.reduce((s, v) => s + Math.pow(v - meanAll, 2), 0) / n);
                    const transitLimit = Number(this.unstableKg) || 0.8;
                    const isStale = (this._sampleMaxAge || 0) > 4;
                    // Arah gerak: paruh awal vs paruh akhir (rata-rata, kebal jitter).
                    // Turun >0.5 = keranjang diangkat tengah sampling → buang walau
                    // selisih lolos (turun pelan < ambang tapi konsisten).
                    const half = Math.max(1, Math.floor(n / 2));
                    const meanFirst = this.samplesCollected.slice(0, half).reduce((s, v) => s + v, 0) / half;
                    const meanSecond = this.samplesCollected.slice(-half).reduce((s, v) => s + v, 0) / half;
                    const isFalling = (meanFirst - meanSecond) > 0.5;
                    // AUTO hectic: JANGAN buang hasil cuma karena goyang. Trimmed mean
                    // sudah kebal outlier — goyang ±1-2 kg tetap disimpan (dengan
                    // flag warning), karena "ke-record sedikit goyang" jauh lebih
                    // berguna daripada "tidak ke-record sama sekali". Yang dibuang
                    // hanya data benar-benar sampah: diangkat tengah sampling,
                    // data macet, atau ayunan >3 kg.
                    const hardLimit = Math.max(3.0, transitLimit);
                    if (this.autoLoop && (tSelisih > hardLimit || isStale || isFalling)) {
                        this.samplesCollected = [];
                        this.showResult = false;
                        this.resultData = null;
                        this.autoArmed = true;
                        wWindow.length = 0;
                        // Backoff: gagal beruntun (angka goyang terus) → jeda
                        // dilipatgandakan 0.5→1→2→4 dtk biar loading tidak kedip.
                        // Reset begitu ada yang tercatat / loop di-restart.
                        this._discardStreak = (this._discardStreak || 0) + 1;
                        const backoff = Math.min(4000, 500 * Math.pow(2, this._discardStreak - 1));
                        loopCooldownUntil = Date.now() + backoff;
                        this.resting = true;
                        setTimeout(() => { this.resting = false; }, backoff);
                        if (Date.now() - (this._lastTransitToast || 0) > 5000) {
                            this._lastTransitToast = Date.now();
                            this.triggerToast(isStale
                                ? 'Data macet — cek kamera/service'
                                : (isFalling ? 'Keburu diangkat — sampling ulang' : `Goyangan liar (±${tSelisih.toFixed(2)} kg) — sampling ulang`));
                        }
                        return;
                    }
                    this.resultData = {
                        jam: new Date().toTimeString().split(' ')[0],
                        doId: this.selectedDo ? Number(this.selectedDo) : null, doNumber: this.orderLabel, mobil: this.noMobil || '-', keranjang: this.selectedKeranjang,
                        bruto: brutoMean + tare, tare: tare, netto: brutoMean,
                        min: minVal, max: maxVal, selisih: selisih, stddev: stddev,
                        rawSamples: [...this.samplesCollected],
                        goyangWarning: tSelisih > transitLimit,
                    };
                    this.showResult = true;
                    loopCooldownUntil = Date.now() + 500;
                    // Auto: simpan LANGSUNG tanpa jeda 1 dtk (hasil tetap tampil
                    // sekilas; cooldown di-refresh lagi habis save selesai).
                    if (this.autoLoop) this.saveResult(true);
                },
                async saveResult(auto = false) {
                    if (!this.resultData) return;
                    // AUTO: angka lari jauh antara selesai sampling vs mau simpan
                    // (diangkat/diganti tepat sebelum save) → jangan simpan sampah.
                    if (auto && Math.abs(Number(this.effectiveRawWeight) - Number(this.resultData.netto)) > 2) {
                        this.showResult = false;
                        this.resultData = null;
                        this.autoArmed = true;
                        wWindow.length = 0;
                        loopCooldownUntil = Date.now() + 500;
                        if (Date.now() - (this._lastTransitToast || 0) > 5000) {
                            this._lastTransitToast = Date.now();
                            this.triggerToast('Angka lari sebelum simpan — sampling ulang');
                        }
                        return;
                    }
                    const bkt = (this.dbBaskets || []).find(b => b.code === this.resultData.keranjang);
                    if (!bkt || !this.resultData.doId) {
                        this.triggerToast(!bkt ? 'Master tidak cocok DB — cek Keranjang di /master' : 'Pilih No. DO dulu');
                        if (!auto) { this.showResult = false; this.resultData = null; }
                        else { this.showResult = false; this.resultData = null; }
                        return;
                    }
                    try {
                        const noteParts = [];
                        if (this.driverName) noteParts.push('Supir: ' + this.driverName);
                        if (this.jenisAyam && this.jenisAyam !== 'biasa') noteParts.push('Jenis: ' + this.jenisAyam);
                        const res = await fetch('/api/weighings/finalize', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                do_id: this.resultData.doId, basket_id: bkt.id,
                                station: this.selectedStation,
                                source: this.source,
                                vehicle_no: this.resultData.mobil,
                                gross_avg: Number(this.resultData.bruto.toFixed(2)),
                                tare_kg: Number(this.resultData.tare.toFixed(2)),
                                min_kg: Number((this.resultData.min + this.resultData.tare).toFixed(2)),
                                max_kg: Number((this.resultData.max + this.resultData.tare).toFixed(2)),
                                stddev: Number((this.resultData.stddev ?? 0).toFixed(3)),
                                raw_samples: (this.resultData.rawSamples || []).map(v => Number(Number(v).toFixed(3))),
                                is_manual_tare: false, note: noteParts.length ? noteParts.join(' | ') : null,
                            }),
                        });
                        if (!res.ok) {
                            let detail = 'http ' + res.status;
                            try {
                                const ej = await res.json();
                                const first = ej && ej.errors ? Object.values(ej.errors).flat()[0] : (ej.message || '');
                                if (first) detail += ': ' + first;
                            } catch (e2) {}
                            throw new Error(detail);
                        }
                        const saved = await res.json();
                        // lastBasketW WAJIB satu satuan dengan w (effectiveRawWeight
                        // = netto live). Dulu pakai gross_avg → selisih sebesar tare
                        // (6-7 kg) bikin syarat re-arm "w < last-5" langsung TRUE
                        // padahal keranjang belum diangkat → re-arm + sampling lagi
                        // dalam 1 detik = double input. Pakai net_kg sekarang.
                        const g = Number((saved.weighing.net_kg ?? saved.weighing.gross_avg) || 0);
                        if (g > 0) this.lastBasketW = g;
                        if (auto) {
                            this.autoCount += 1;
                            this.autoTotalNetto += Number(saved.weighing.net_kg || 0);
                            this.triggerToast(`Auto #${this.autoCount} tercatat: ${Number(saved.weighing.net_kg || 0).toFixed(2)} kg`);
                        } else {
                            this.triggerToast('Bukti Timbang Berhasil Disimpan (ID #' + saved.weighing.id + ')');
                        }
                    } catch (e) {
                        this.triggerToast('Gagal simpan ke server (' + String((e && e.message) || e).slice(0, 40) + ') — ulangi');
                        if (auto) {
                            this.showResult = false;
                            this.resultData = null;
                            this._discardStreak = (this._discardStreak || 0) + 1;
                            loopCooldownUntil = Date.now() + Math.min(4000, 500 * Math.pow(2, this._discardStreak - 1));
                            // Gagal SIMPAN beruntun (misal DO basi) = loop tidak akan
                            // pernah kecatat → STOP sekalian daripada muter selamanya.
                            this._saveFailStreak = (this._saveFailStreak || 0) + 1;
                            if (this._saveFailStreak >= 3) {
                                this.autoLoop = false;
                                this.autoStopped = true;
                                this._saveFailStreak = 0;
                                this.triggerToast('Auto STOP — save gagal 3x beruntun. Benerin (cek DO/keranjang), terus START lagi.');
                            }
                            return;
                        }
                    }
                    this.showResult = false;
                    this.resultData = null;
                    this.resultEkor = '';
                    if (auto) {
                        // Jeda 2.5 dtk SETELAH tercatat (dihitung dari save selesai):
                        // anti-dobel — keranjang yg sama jangan langsung ke-sampling
                        // lagi. wWindow dikosongkan biar status STABIL harus dibangun
                        // ulang dari angka segar (tidak langsung trigger).
                        loopCooldownUntil = Date.now() + 2500;
                        this.resting = true;
                        wWindow.length = 0;
                        setTimeout(() => { this.resting = false; }, 2500);
                        this._discardStreak = 0;
                        this._saveFailStreak = 0;
                    }
                },
                resetResult() {
                    this.showResult = false;
                    this.resultData = null;
                    this.resultEkor = '';
                },
            };
        }
    </script>
    <script>
        // DRIVER LED PLAIN-JS (di luar Alpine): tiap 1 detik tarik angka live
        // langsung dari API + tulis ke DOM. Terbukti tahan saat render Alpine
        // macet (counter ext# tetap jalan). Tidak butuh refresh browser.
        window.__extPollN = 0;
        function __ledSet(id, txt) { var el = document.getElementById(id); if (el) el.textContent = txt; }
        var __BADGE = {
            stabil: '<span class="px-2.5 py-0.5 rounded font-black bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>STABIL</span>',
            stale: '<span class="px-2.5 py-0.5 rounded font-black bg-amber-500/20 text-amber-400 border border-amber-500/40 inline-flex items-center gap-1">TAHAN — DATA LAMA</span>',
            cam: '<span class="px-2.5 py-0.5 rounded font-black bg-blue-500/20 text-blue-400 border border-blue-500/40 inline-flex items-center gap-1">CAM MANDIRI</span>',
            off: '<span class="px-2.5 py-0.5 rounded font-black bg-slate-800 text-slate-500 border border-slate-700 inline-flex items-center gap-1">DISCONNECTED</span>'
        };
        function __tareKg() {
            // Tare dari label opsi keranjang terpilih ("... - tare 6.9kg").
            try {
                var sel = document.getElementById('basketSelect');
                var txt = sel && sel.selectedOptions.length ? sel.selectedOptions[0].textContent : '';
                var m = /tare\s*([\d.]+)/i.exec(txt || '');
                if (m) return parseFloat(m[1]);
                var t = document.getElementById('tareValue');
                var m2 = /([\d.]+)/.exec(t ? t.textContent : '');
                if (m2) return parseFloat(m2[1]);
            } catch (e) {}
            return 0;
        }
        function __calcEkor() {
            // Rata-rata per ekor instan: netto kartu hasil ÷ ekor/keranjang.
            try {
                var netEl = document.getElementById('resultNetto');
                var inp = document.getElementById('resultEkorInput');
                var net = netEl ? parseFloat(netEl.textContent) : NaN;
                var ek = inp ? parseFloat(inp.value) : NaN;
                __ledSet('resultAvgEkor', (isFinite(net) && isFinite(ek) && ek > 0) ? ((net / ek).toFixed(2) + ' kg') : '—');
            } catch (e) {}
        }
        function __renderRk() {
            var arr = window.__rkSamples || [];
            if (!arr.length) {
                __ledSet('rkAvg', '—'); __ledSet('rkN', '0'); __ledSet('rkLast', '—');
                return;
            }
            var sum = 0;
            for (var i = 0; i < arr.length; i++) sum += arr[i];
            __ledSet('rkAvg', (sum / arr.length).toFixed(2) + ' kg/krj');
            __ledSet('rkN', String(arr.length));
            __ledSet('rkLast', arr[arr.length - 1].toFixed(2));
        }
        function __loopStatus() {
            // Baca STATE Alpine langsung (state-nya hidup, cuma render-nya yg
            // macet) → tampilkan status loop via DOM polos. Jadi indikator
            // tidak pernah hilang walau render Alpine beku.
            try {
                var el = document.getElementById('loopStatusPlain');
                if (!el) return;
                var root = document.querySelector('[x-data]');
                var st = (root && root._x_dataStack) ? root._x_dataStack[0] : null;
                if (!st || !st.autoLoop) { el.textContent = 'LOOP: MATI'; return; }
                var phase = st.isSampling ? ('MENGHITUNG ' + (st.samplingCountdown ?? '') + ' DETIK… JANGAN ANGKAT')
                    : (st.resting ? 'JEDA SEBENTAR — SIAPKAN KERANJANG'
                    : (!st.autoArmed ? ((st.lastBasketW === null && st.autoCount === 0) ? 'MENUNGGU STABIL — TAHAN KERANJANG' : 'TERCATAT — TARUH BERIKUTNYA') : 'SIAP — TARUH KERANJANG (>10 KG)'));
                el.textContent = 'LOOP: JALAN • #' + st.autoCount + ' • '
                    + Number(st.autoTotalNetto || 0).toFixed(2) + ' kg • ' + phase;
            } catch (e) {}
        }
        function __renderLed(d) {
            // Render 1 payload live ke LED + badge + ringkasan. Dipakai driver
            // 1-detik DAN poll Alpine — angka & indikator selalu dari data
            // yang sama di tick yang sama.
            try {
                var panel = document.getElementById('ledPanel');
                var mode = panel ? (panel.getAttribute('data-mode') || 'mesin') : 'mesin';
                var on = !!d.connected && d.weight !== null && d.weight !== undefined;
                var val = null;
                if (on) { val = Number(d.weight); }
                __ledSet('ledValue', val !== null ? val.toFixed(2) : '00.00');
                if (on) __ledSet('ledMsg', '');
                __ledSet('ledAge', (d.age_seconds !== null && d.age_seconds !== undefined) ? ('update ' + d.age_seconds + 's lalu') : '');
                var box = document.getElementById('ledStatus');
                // Umur >2 dtk = angka basi (nyangkut): jangan cap STABIL.
                var stale = on && d.age_seconds !== null && d.age_seconds !== undefined && d.age_seconds > 2;
                if (box) box.innerHTML = !on ? (mode === 'cam' ? __BADGE.cam : __BADGE.off) : (stale ? __BADGE.stale : __BADGE.stabil);
                // Rata-rata per keranjang: tiap angka BARU = 1 keranjang.
                // netto = bruto − tare (rumus DHK), rata-rata = mean netto.
                if (on && mode === 'cam') {
                    var tare = __tareKg();
                    var netto = val - tare;
                    if (!window.__rkSamples) window.__rkSamples = [];
                    if (window.__rkLast === undefined || Math.abs(netto - window.__rkLast) > 0.005) {
                        window.__rkSamples.push(netto);
                        if (window.__rkSamples.length > 50) window.__rkSamples.shift();
                        window.__rkLast = netto;
                    }
                    __renderRk();
                }
                __calcEkor();
            } catch (e) {}
        }
        async function __driveLed() {
            try {
                // Kalau poll Alpine baru saja sukses (<1.2 dtk), pakai payload
                // itu langsung — tanpa fetch dobel (server single-thread!) dan
                // tanpa jeda antara LED dan loop.
                var fresh = window.__lastLivePayload && (Date.now() - (window.__lastAlpinePollAt || 0) < 1200);
                if (fresh && !document.hidden) {
                    __renderLed(window.__lastLivePayload);
                    return;
                }
                var panel = document.getElementById('ledPanel');
                var mode = panel ? (panel.getAttribute('data-mode') || 'mesin') : 'mesin';
                var stEl = document.getElementById('stationSelect');
                var station = (stEl && stEl.value) || 'POS-01';
                var source = (mode === 'cam') ? 'cam' : 'mesin';
                var ctl = new AbortController();
                var to = setTimeout(function () { ctl.abort(); }, 10000);
                var res = await fetch('/api/weighings/live?station=' + encodeURIComponent(station) + '&source=' + source, { signal: ctl.signal });
                clearTimeout(to);
                var d = await res.json();
                window.__lastLivePayload = d;
                window.__lastAlpinePollAt = Date.now();
                __renderLed(d);
            } catch (e) {}
        }
        setInterval(function () {
            try {
                if (typeof window.__livePoll === 'function' && !document.hidden) { window.__livePoll(); }
            } catch (e) {}
            if (!document.hidden) { __driveLed(); }
            __loopStatus();
            window.__extPollN++;
            __ledSet('extPoll', '• ext #' + window.__extPollN + ' (a#' + (window.__pollN || 0) + ')');
        }, 1000);
        // Ekor/keranjang: hitung ulang tiap ketik + tiap LED update.
        // Pesan status LED: diklik START → langsung tampil (tidak nunggu server).
        (function () {
            var btns = document.querySelectorAll('[data-svc-start]');
            for (var i = 0; i < btns.length; i++) {
                (function (b) {
                    if (b.dataset.bound) return;
                    b.dataset.bound = '1';
                    b.addEventListener('click', function () {
                        __ledSet('ledMsg', 'MEMUAT MODEL… (±20 dtk)');
                    });
                })(btns[i]);
            }
            var stops = document.querySelectorAll('[data-svc-stop]');
            for (var j = 0; j < stops.length; j++) {
                (function (b) {
                    if (b.dataset.bound) return;
                    b.dataset.bound = '1';
                    b.addEventListener('click', function () {
                        __ledSet('ledMsg', '');
                    });
                })(stops[j]);
            }
            var inp = document.getElementById('resultEkorInput');
            if (inp && !inp.dataset.bound) {
                inp.dataset.bound = '1';
                inp.addEventListener('input', __calcEkor);
            }
            var rst = document.getElementById('rkReset');
            if (rst && !rst.dataset.bound) {
                rst.dataset.bound = '1';
                rst.addEventListener('click', function () {
                    window.__rkSamples = [];
                    window.__rkLast = undefined;
                    __renderRk();
                });
            }
        })();
    </script>
@endsection
