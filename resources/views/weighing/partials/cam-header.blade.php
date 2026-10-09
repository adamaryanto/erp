<!-- BREADCRUMB CAM -->
<div class="mb-5 flex items-center justify-between bg-white px-4 py-3 rounded-lg border border-gray-200 shadow-sm">
    <div class="flex items-center gap-2 text-xs">
        <span class="text-gray-400 font-medium">Accurate ERP</span>
        <span class="text-gray-300">/</span>
        <span class="text-gray-400 font-medium">Penimbangan</span>
        <span class="text-gray-300">/</span>
        <span class="text-purple-700 font-bold">Timbang Live Cam</span>
    </div>
    <div class="flex items-center gap-2">
    <button @click="recheckConnection()"
            :class="isConnected ? 'bg-emerald-50 text-emerald-700 border-emerald-300' : 'bg-rose-50 text-rose-700 border-rose-300'"
            title="Cek koneksi ke bridge (fallback mesin)"
            class="px-3 py-1.5 rounded-md border text-xs font-semibold flex items-center gap-2">
        <span class="w-2 h-2 rounded-full" :class="isConnected ? 'bg-emerald-500' : 'bg-rose-500'"></span>
        <span x-text="bridgePillLabel"></span>
    </button>
    <template x-if="svcRunning">
        <span class="px-3 py-1.5 rounded-md border border-emerald-300 bg-emerald-50 text-emerald-700 text-xs font-semibold flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>OCR JALAN (PID <span x-text="svcPid"></span>)</span>
        </span>
    </template>
    <template x-if="!svcRunning && !svcStarting">
        <span class="px-3 py-1.5 rounded-md border border-slate-300 bg-slate-50 text-slate-700 text-xs font-semibold flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
            <span>OCR BERHENTI</span>
        </span>
    </template>
    <a href="{{ route('buglog.index') }}?autocheck=1"
       title="Diagnosa otomatis: timbangan, laptop, atau web?"
       class="px-3 py-1.5 rounded-md border border-rose-300 bg-rose-50 text-rose-700 text-xs font-semibold flex items-center gap-2">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        <span>CEK ERROR</span>
    </a>
    </div>
</div>

<!-- CAMERA PREVIEW + SERVICE CONTROL -->
<div class="mb-5 bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-4 py-2.5 border-b border-gray-100 flex items-center justify-between gap-2 flex-wrap">
        <span class="text-xs text-gray-600">Kamera Pos — pratinjau (refresh tiap 2 detik)</span>
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-[10px] font-mono text-gray-400">{{ $settings->cam_snapshot_url ? 'sumber terisi' : 'belum ada URL' }}</span>
            <button @click="captureOcr()" :disabled="ocrRunning"
                    :class="ocrRunning ? 'bg-slate-300 text-slate-500 cursor-wait' : 'bg-blue-600 hover:bg-blue-700 text-white'"
                    class="px-3 py-1.5 rounded-md text-xs font-bold uppercase transition"
                    x-text="ocrRunning ? 'MEMBACA…' : 'BACA ANGKA (OCR)'"></button>
            <button @click="openRoiCalibrate()" :disabled="svcRunning"
                    class="px-3 py-1.5 rounded-md text-xs font-bold uppercase transition bg-amber-600 hover:bg-amber-700 text-white">
                KALIBRASI ROI
            </button>
        </div>
    </div>
    <div x-show="ocrMessage" class="px-4 py-2 text-xs font-mono border-b border-gray-100"
         :class="ocrOk ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"
         x-text="ocrMessage"></div>
    
    <!-- SERVICE CONTROL -->
    <div class="px-4 py-2.5 bg-slate-50 border-b border-gray-100 flex flex-wrap items-center gap-2">
        <span class="w-2 h-2 rounded-full" :class="svcRunning ? 'bg-emerald-500 animate-pulse' : (svcStarting ? 'bg-amber-500 animate-pulse' : 'bg-slate-400')"></span>
        <span class="text-xs font-bold" :class="svcRunning ? 'text-emerald-700' : (svcStarting ? 'text-amber-700' : 'text-gray-500')"
              x-text="svcRunning ? ('SERVICE JALAN' + (svcAge !== null ? ' • ' + svcAge + 's lalu' : '')) : (svcStarting ? 'LOADING MODEL…' : 'SERVICE OCR BERHENTI')"></span>
        <span x-show="svcRunning && svcWeight !== null" class="text-xs font-mono text-gray-600"
              x-text="'terakhir: ' + Number(svcWeight).toFixed(2) + ' kg'"></span>
        <span x-show="svcRunning && svcRaw" class="text-xs font-mono text-purple-700 bg-purple-50 border border-purple-200 rounded px-2 py-0.5"
              x-text="'kamera lihat: \'' + svcRaw.slice(0, 40) + '\''"></span>
        <span x-show="svcRunning && svcFresh === false" class="text-[11px] font-bold text-amber-700 bg-amber-100 border border-amber-300 rounded px-2 py-0.5"
              x-text="'⚠ jalan tapi tidak ada bacaan baru' + (svcAge !== null ? ' (' + svcAge + 's)' : '') + ' — cek kamera/ROI'"></span>
        <span x-show="svcDetail" class="text-[11px] font-mono text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-0.5"
              x-text="'ℹ ' + svcDetail"></span>
        <div class="ml-auto flex items-center gap-2">
            <button @click="toggleCamAuto()" title="Otomatis nyalakan lagi kalau service mati (selama halaman ini kebuka)"
                    :class="camAuto ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-white hover:bg-gray-50 text-gray-600 border-gray-300'"
                    class="px-3 py-1.5 rounded-md border text-xs font-bold uppercase transition"
                    x-text="camAuto ? 'AUTO: ON' : 'AUTO: OFF'"></button>
            <button x-show="!svcRunning && !svcStarting" @click="startCamService()" :disabled="svcBusy" data-svc-start
                    :class="svcBusy ? 'bg-slate-300 text-slate-500 cursor-wait' : 'bg-emerald-600 hover:bg-emerald-700 text-white'"
                    class="px-3 py-1.5 rounded-md text-xs font-bold uppercase transition"
                    x-text="svcBusy ? 'STARTING…' : 'START SERVICE'"></button>
            <button x-show="svcRunning || svcStarting" @click="stopCamService()" :disabled="svcBusy" data-svc-stop
                    class="px-3 py-1.5 rounded-md text-xs font-bold uppercase bg-rose-600 hover:bg-rose-700 text-white transition"
                    x-text="svcBusy ? 'STOPPING…' : 'STOP'"></button>
        </div>
    </div>
    
    <!-- PREVIEW SINGLE: Video URL (live) diutamakan, fallback ke Snapshot URL -->
    @if($settings->cam_stream_url ?? $settings->cam_snapshot_url)
        <div x-data="camPreview()" x-init="init()" data-url="{{ ($settings->cam_stream_url ?? '') !== '' ? $settings->cam_stream_url : $settings->cam_snapshot_url }}">
            <div class="relative bg-slate-900 h-[420px] flex items-center justify-center overflow-hidden">
                <img x-show="!failed" :src="src" x-on:load="onLoad()" x-on:error="onError()" alt="Kamera pos timbang" class="max-w-full max-h-full object-contain">
                <div x-show="!failed && !loaded" class="p-6 text-center text-sm text-slate-300">
                    <p class="animate-pulse">Menghubungi kamera…</p>
                    <p class="text-[11px] text-slate-500 mt-1 font-mono break-all" x-text="base"></p>
                </div>
                <div x-show="!failed && loaded && failCount > 0" class="px-4 py-1.5 text-[11px] text-amber-300 bg-amber-950/60">
                    Kamera sempat tidak merespons (<span x-text="failCount"></span>x) — cek kamera nyala &amp; satu WiFi/LAN. Mencoba lagi otomatis…
                </div>
                <div x-show="!failed && loaded && !isStream && frameAge !== null && frameAge > 6" class="px-4 py-1.5 text-[11px] font-bold text-amber-300 bg-amber-950/60">
                    Gambar tidak update (frame terakhir <span x-text="frameAge"></span> dtk lalu) — koneksi kamera macet: cek HP sleep / IP berubah / aplikasi tertutup.
                </div>
            </div>
            <div x-show="failed" class="p-6 text-center text-sm">
                <p class="text-rose-600 font-bold">⚠ KAMERA OFFLINE / TIDAK TERHUBUNG</p>
                <p class="text-amber-600 mt-1">Kemungkinan: kamera mati, beda WiFi/LAN, URL salah, atau butuh login.</p>
                <p class="text-xs text-gray-400 mt-1 font-mono break-all" x-text="base"></p>
                <button @click="retry()" class="mt-2 px-4 py-1.5 text-xs rounded bg-blue-600 text-white font-bold hover:bg-blue-700">COBA LAGI</button>
            </div>
            <p class="px-4 py-1.5 text-[11px] text-gray-400 border-t border-gray-100 flex items-center justify-between gap-2">
                <span x-text="isStream ? 'Mode: stream langsung (tanpa refresh)' : 'Mode: snapshot (refresh tiap 2 detik)'"></span>
                <button @click="retry()" class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 text-[11px] font-bold hover:bg-gray-50">↻ REFRESH MANUAL</button>
            </p>
        </div>
        <script>
            function camPreview() {
                return {
                    base: '',
                    src: '',
                    failed: false,
                    failCount: 0,
                    loaded: false,
                    pending: false,
                    lastOkAt: null,
                    nowTs: Date.now(),
                    timer: null,
                    get isStream() {
                        return /mjpg|mjpeg|videofeed|\/video(\/|$|\?)|mjpegfeed|nphmotion|getoneshot|videostream|cgi-bin/i.test(this.base);
                    },
                    get frameAge() {
                        return this.lastOkAt ? Math.round((this.nowTs - this.lastOkAt) / 1000) : null;
                    },
                    init() {
                        this.base = (this.$el && this.$el.dataset && this.$el.dataset.url) ? this.$el.dataset.url : '';
                        this.src = this.base;
                        if (this.timer) { clearInterval(this.timer); this.timer = null; }
                        if (!this.isStream) {
                            this.tick();
                            this.timer = setInterval(() => this.tick(), 2000);
                        } else {
                            // Stream MJPEG: paksa koneksi BARU tiap init (query acak
                            // diabaikan server). Tanpa ini, retry dengan src yang sama
                            // diabaikan browser → nyangkut di "Menghubungi" selamanya.
                            this.src = this.base + (this.base.includes('?') ? '&' : '?') + 't=' + Date.now();
                            this.pending = true;
                            this.watchdog(12000);
                            this.timer = setInterval(() => { this.nowTs = Date.now(); }, 2000);
                        }
                        // Tombol START SERVICE → preview ikut refresh + cek ulang kamera.
                        window.addEventListener('cam-retry', () => this.retry());
                    },
                    tick() {
                        if (this.failed) return;
                        this.nowTs = Date.now();
                        const sep = this.base.includes('?') ? '&' : '?';
                        this.src = this.base + sep + 't=' + Date.now();
                        // Watchdog: browser kadang TIDAK menembak event error
                        // kalau koneksi HANG (IP kamera ganti / HP sleep) → tanpa ini
                        // preview macet di "Menghubungi kamera…" selamanya.
                        this.pending = true;
                        this.watchdog(8000);
                    },
                    watchdog(ms) {
                        const stamp = this.src;
                        setTimeout(() => {
                            if (!this.failed && this.pending && this.src === stamp) this.onError();
                        }, ms || 8000);
                    },
                    onLoad() {
                        this.loaded = true;
                        this.pending = false;
                        this.lastOkAt = Date.now();
                        this.nowTs = this.lastOkAt;
                        this.failCount = 0;
                    },
                    onError() {
                        this.pending = false;
                        // Kamera HP kadang nolak 1 request (rebutan dgn service
                        // OCR yg jepret tiap 2 dtk) — itu normal, coba lagi.
                        // Baru nyerah setelah 5x gagal beruntun (±10 dtk).
                        this.failCount++;
                        if (this.failCount < 5) return;
                        this.failed = true;
                        if (this.timer) { clearInterval(this.timer); this.timer = null; }
                    },
                    retry() {
                        this.failed = false;
                        this.failCount = 0;
                        this.loaded = false;
                        this.lastOkAt = null;
                        this.nowTs = Date.now();
                        this.init();
                    },
                };
            }
        </script>
    @else
        <div class="p-8 text-center text-sm text-gray-400">
            Kamera belum dipasang. Isi <span class="font-mono">Snapshot URL</span> di halaman Pengaturan.
        </div>
    @endif
</div>

<!-- ROI CALIBRATE MODAL (simplified) -->
<div x-show="roiCalibrateOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full mx-4 max-h-[80vh] flex flex-col">
        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-bold text-gray-800">Kalibrasi ROI — Atur area display 7-segmen</h3>
            <button @click="roiCalibrateOpen = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
        </div>
        <div class="p-4 flex-1 overflow-auto">
            <div class="space-y-3">
                <p class="text-xs text-gray-600 font-bold">Atur ROI (0-1 = persen dari ukuran frame):</p>
                <div class="grid grid-cols-4 gap-2">
                    <label class="text-xs">X: <input type="number" step="0.01" min="0" max="1" x-model.number="roiX" class="w-full h-8 border rounded px-2 font-mono text-sm"></label>
                    <label class="text-xs">Y: <input type="number" step="0.01" min="0" max="1" x-model.number="roiY" class="w-full h-8 border rounded px-2 font-mono text-sm"></label>
                    <label class="text-xs">W: <input type="number" step="0.01" min="0.01" max="1" x-model.number="roiW" class="w-full h-8 border rounded px-2 font-mono text-sm"></label>
                    <label class="text-xs">H: <input type="number" step="0.01" min="0.01" max="1" x-model.number="roiH" class="w-full h-8 border rounded px-2 font-mono text-sm"></label>
                </div>
                <p class="text-xs text-gray-500">Contoh: X=0.35 Y=0.30 W=0.30 H=0.20 = area tengah 30% lebar × 20% tinggi</p>
                <div class="flex gap-2 flex-wrap">
                    <button @click="autoRoi()" :disabled="roiAutoBusy"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold rounded transition"
                            x-text="roiAutoBusy ? 'MENDETEKSI… (±20 dtk)' : '🤖 AUTO ROI'"></button>
                    <button @click="autoCalibrate()" :disabled="roiCalBusy"
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold rounded transition"
                            x-text="roiCalBusy ? 'KALIBRASI… (±4 mnt)' : '⚡ FULL AUTO (putar+ROI+restart)'"></button>
                    <button @click="testRoiOcr()" :disabled="roiTestBusy"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded transition"
                            x-text="roiTestBusy ? 'TESTING…' : 'TEST OCR DENGAN ROI INI'"></button>
                    <button @click="saveRoi()" :disabled="roiSaveBusy"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded transition"
                            x-text="roiSaveBusy ? 'MENYIMPAN…' : '💾 SIMPAN ROI'"></button>
                </div>
                <div x-show="roiTestResult" class="p-2 text-xs font-mono rounded border"
                     :class="roiTestWeight ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'"
                     x-text="roiTestResult"></div>
            </div>
        </div>
        <div class="px-4 py-3 border-t border-gray-200 flex justify-end gap-2">
            <button @click="roiCalibrateOpen = false" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold rounded">TUTUP</button>
        </div>
    </div>
</div>