@extends('layouts.app')
@section('title', 'Hitung Ayam Gantung')

@php
    $settingsHitung = \App\Models\Setting::config();
    $snapUrl = trim((string) ($settingsHitung->cam_snapshot_url ?? ''));
    $streamUrl = trim((string) ($settingsHitung->cam_stream_url ?? ''));
    $isStreamUrl = fn ($u) => (bool) preg_match('#^rtsp://|mjpe?g|videofeed|videostream|action=stream|nphmotion|getoneshot|\.mjpg\b#i', $u);
    $isRtspUrl = fn ($u) => (bool) preg_match('#^rtsp://#i', $u);
    // Seperti pratinjau Timbang Cam: MJPEG (videofeed) -> video langsung di <img>;
    // snapshot/RTSP -> snapshot polling lewat server.
    if ($streamUrl !== '' && ! $isRtspUrl($streamUrl)) {
        $hitungSrc = $streamUrl;
        $hitungStreamMode = true;
    } elseif ($snapUrl !== '') {
        $hitungSrc = route('cam.snapshot', ['embedded' => 1]);
        $hitungStreamMode = false;
    } else {
        $hitungSrc = '';
        $hitungStreamMode = false;
    }
@endphp

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between border-b border-slate-200 pb-3">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Hitung Ayam Gantung</h1>
            <p class="text-xs text-slate-500">Pakai IP CAM yang sama dengan Timbang Cam (Snapshot/Stream URL di Pengaturan). Aimed ke monitor/rail yang memutar video ayam gantung; alat menghitung ayam yang lewat pada titik yang Anda pilih.</p>
        </div>
        <a href="{{ route('cam.index') }}" class="text-xs text-blue-600 underline">Ke Timbang Cam →</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- KOLOM KIRI: snapshot IP cam + mask --}}
        <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200 shadow-sm p-3 space-y-2">
            <div class="flex gap-2 text-xs font-bold text-gray-600">
                <span class="text-emerald-700">Snapshot IP Cam</span> /
                <span class="text-slate-500">Mask HSV</span>
                <span id="camStatus" class="ml-auto text-[11px] font-mono text-slate-400">memuat…</span>
            </div>

            <div class="relative w-full bg-black rounded overflow-hidden select-none" id="stageWrap" style="aspect-ratio: 4 / 3;">
                <img id="camFrame" src="{{ $hitungSrc }}" alt="Kamera IP" class="absolute inset-0 w-full h-full object-fill" draggable="false" crossorigin="anonymous" referrerpolicy="no-referrer">
                <canvas id="maskCanvas" class="absolute inset-0 w-full h-full object-fill opacity-90 pointer-events-none"></canvas>
                <canvas id="overlayCanvas" class="absolute inset-0 w-full h-full object-fill cursor-crosshair"></canvas>
            </div>
            <p class="text-[11px] text-slate-400">Klik pada gambar di atas untuk set titik rel (titik hijau = deteksi). Titik harus berada di perjalanan ayam, tidak di background kosong. {{ $hitungStreamMode ? 'Mode video MJPEG (seperti Timbang Cam) — tanpa refresh.' : 'Mode snapshot JPG dari server (RTSP tidak bisa diputar langsung di browser).' }}</p>

            <div class="flex items-center justify-between bg-slate-900 rounded p-3 text-white">
                <div>
                    <div class="text-[10px] uppercase text-slate-400">Hitungan Ayam (batch)</div>
                    <div class="font-mono text-4xl font-black text-emerald-400 leading-none" id="counterBig">0</div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase text-slate-400">Titik terdeteksi ayam?</div>
                    <div class="font-mono text-xl font-black" id="chickenState">—</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 pt-1">
                <button id="btnStart" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded uppercase">START</button>
                <button id="btnStop" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded uppercase">STOP</button>
                <button id="btnReset" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded uppercase">RESET</button>
                <button id="btnSave" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded uppercase">SIMPAN BATCH</button>
                <button id="btnCam" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-bold rounded uppercase">Refresh Kamera</button>
            </div>
        </div>

        {{-- KOLOM KANAN: kalibrasi + history --}}
        <div class="space-y-4">
            <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-4 space-y-3">
                <h3 class="font-bold text-slate-800 text-sm uppercase border-b border-slate-100 pb-2">Kalibrasi HSV</h3>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <label class="block">H min <input type="range" id="hMin" min="0" max="179" value="0" class="w-full"><span id="hMinV" class="font-mono text-slate-500">0</span></label>
                    <label class="block">H max <input type="range" id="hMax" min="0" max="179" value="179" class="w-full"><span id="hMaxV" class="font-mono text-slate-500">179</span></label>
                    <label class="block">S min <input type="range" id="sMin" min="0" max="255" value="0" class="w-full"><span id="sMinV" class="font-mono text-slate-500">0</span></label>
                    <label class="block">S max <input type="range" id="sMax" min="0" max="255" value="30" class="w-full"><span id="sMaxV" class="font-mono text-slate-500">30</span></label>
                    <label class="block">V min <input type="range" id="vMin" min="0" max="255" value="220" class="w-full"><span id="vMinV" class="font-mono text-slate-500">220</span></label>
                    <label class="block">V max <input type="range" id="vMax" min="0" max="255" value="255" class="w-full"><span id="vMaxV" class="font-mono text-slate-500">255</span></label>
                </div>
                <div>
                    <label class="flex items-center justify-between text-xs font-semibold text-slate-600">Mask threshold (kotak putih)<span id="thrV" class="font-mono text-slate-500">0.45</span></label>
                    <input type="range" id="maskThr" min="0" max="100" value="45" class="w-full">
                </div>
                <button id="btnAutoHsv" class="w-full py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded uppercase">⚡ AUTO HSV (deteksi dari gambar)</button>
                <p id="hsvMsg" class="text-[11px] text-slate-400">Baca warna ayam dari area terang di jalur rel pada frame saat ini.</p>
                <div>
                    <label class="flex items-center justify-between text-xs font-semibold text-slate-600">Arah rel</label>
                    <div class="flex gap-2 mt-1 text-xs">
                        <label class="flex items-center gap-1"><input type="radio" name="dir" value="L2R" checked> Kiri → Kanan</label>
                        <label class="flex items-center gap-1"><input type="radio" name="dir" value="R2L"> Kanan → Kiri</label>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2 mb-2">
                    <h3 class="font-bold text-slate-800 text-sm uppercase">Riwayat Batch</h3>
                    <button id="btnReloadHist" class="text-[11px] text-blue-600 underline">Muat ulang</button>
                </div>
                <div class="max-h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="text-slate-400"><tr><th class="text-left py-1">Waktu</th><th class="text-right">Ayam</th><th class="text-right">Arah</th></tr></thead>
                        <tbody id="histBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const frameImg = document.getElementById('camFrame');
    const maskCanvas = document.getElementById('maskCanvas');
    const overlayCanvas = document.getElementById('overlayCanvas');
    const mctx = maskCanvas.getContext('2d', { willReadFrequently: true });
    const octx = overlayCanvas.getContext('2d');

    const counterBig = document.getElementById('counterBig');
    const chickenState = document.getElementById('chickenState');
    const camStatus = document.getElementById('camStatus');

    // Titik rel NORMALIZED [0..1, 0..1]
    let point = { x: 0.5, y: 0.6 };
    let running = false;
    let count = 0;
    let wasChicken = false;
    let lastCountAt = 0;
    let direction = 'L2R';

    const S = {
        hMin: 0, hMax: 179, sMin: 0, sMax: 30, vMin: 220, vMax: 255, maskThr: 0.45,
    };
    const $ = (id) => document.getElementById(id);
    ['hMin','hMax','sMin','sMax','vMin','vMax'].forEach((k) => {
        const el = $(k), lab = $(k + 'V');
        el.addEventListener('input', () => { S[k] = +el.value; lab.textContent = el.value; });
    });
    $('maskThr').addEventListener('input', (e) => { S.maskThr = +e.target.value / 100; $('thrV').textContent = S.maskThr.toFixed(2); });
    document.querySelectorAll('input[name=dir]').forEach((el) => el.addEventListener('change', () => { direction = el.value; }));

    const SNAP_URL = @json(route('cam.snapshot', ['embedded' => 1]));
    const STREAM_SRC = @json($hitungSrc);
    const IS_STREAM = @json($hitungStreamMode);

    function reloadFrame() {
        if (IS_STREAM) {
            // MJPEG: sambungkan ulang stream bila putus
            frameImg.src = STREAM_SRC;
            return;
        }
        frameImg.src = SNAP_URL + (SNAP_URL.includes('?') ? '&' : '?') + 'v=' + Date.now();
    }

    frameImg.addEventListener('load', () => {
        if (frameImg.naturalWidth) {
            camStatus.textContent = 'kamera OK';
            camStatus.className = 'ml-auto text-[11px] font-mono text-emerald-600';
        }
    });
    frameImg.addEventListener('error', () => {
        camStatus.textContent = 'kamera tidak terjangkau — cek IP + URL di Pengaturan';
        camStatus.className = 'ml-auto text-[11px] font-mono text-rose-600';
    });

    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
        const d = mx - mn;
        let h = 0;
        if (d === 0) h = 0;
        else if (mx === r) h = ((g - b) / d) % 6;
        else if (mx === g) h = (b - r) / d + 2;
        else h = (r - g) / d + 4;
        h = Math.round(h * 30); if (h < 0) h += 180;
        const s = mx === 0 ? 0 : Math.round((d / mx) * 255);
        const v = Math.round(mx * 255);
        return [h, s, v];
    }

    // Proses downscaled frame → bangun mask → sample titik
    const PROC_W = 320, PROC_H = 240;
    const proc = document.createElement('canvas');
    proc.width = PROC_W; proc.height = PROC_H;
    const pctx = proc.getContext('2d', { willReadFrequently: true });

    function frameProcess() {
        try {
            if (!frameImg.naturalWidth) return;
            pctx.drawImage(frameImg, 0, 0, PROC_W, PROC_H);
            const img = pctx.getImageData(0, 0, PROC_W, PROC_H);
            const data = img.data;
        } catch (e) {
            // Canvas tainted (CORS) atau frame rusak — jangan matikan loop.
            return;
        }

        // mask canvas display ukuran == gambar asli
        const vw = frameImg.naturalWidth, vh = frameImg.naturalHeight;
        if (maskCanvas.width !== vw) maskCanvas.width = vw;
        if (maskCanvas.height !== vh) maskCanvas.height = vh;
        if (overlayCanvas.width !== vw) overlayCanvas.width = vw;
        if (overlayCanvas.height !== vh) overlayCanvas.height = vh;
        mctx.clearRect(0, 0, vw, vh);

        // low-res mask build
        const mask = mctx.createImageData(PROC_W, PROC_H);
        const md = mask.data;
        for (let i = 0; i < data.length; i += 4) {
            const [h, s, v] = rgbToHsv(data[i], data[i + 1], data[i + 2]);
            const on = h >= S.hMin && h <= S.hMax && s >= S.sMin && s <= S.sMax && v >= S.vMin && v <= S.vMax;
            const o = (i / 4) * 4;
            md[o] = on ? 255 : 0; md[o + 1] = on ? 255 : 0; md[o + 2] = on ? 255 : 0; md[o + 3] = 255;
        }
        pctx.putImageData(mask, 0, 0);
        // up-scale ke tampilan
        mctx.imageSmoothingEnabled = false;
        mctx.drawImage(proc, 0, 0, PROC_W, PROC_H, 0, 0, vw, vh);

        // sample titik (radius ~6px low-res)
        const px = Math.floor(point.x * PROC_W), py = Math.floor(point.y * PROC_H);
        const R = 5;
        let onCnt = 0, tot = 0;
        for (let dy = -R; dy <= R; dy++) {
            for (let dx = -R; dx <= R; dx++) {
                const x = px + dx, y = py + dy;
                if (x < 0 || y < 0 || x >= PROC_W || y >= PROC_H) continue;
                tot++;
                if (md[(y * PROC_W + x) * 4] > 127) onCnt++;
            }
        }
        const frac = tot ? onCnt / tot : 0;

        // state deteksi ayam
        const isChicken = frac >= S.maskThr;
        chickenState.textContent = isChicken ? 'AYAM' : 'kosong';
        chickenState.className = 'font-mono text-xl font-black ' + (isChicken ? 'text-emerald-400' : 'text-slate-500');

        if (running) {
            const now = Date.now();
            if (isChicken && !wasChicken && now - lastCountAt > 300) {
                count += 1;
                lastCountAt = now;
                counterBig.textContent = count;
            }
            wasChicken = isChicken;
        } else {
            wasChicken = isChicken;
        }
    }

    function drawOverlay() {
        const vw = overlayCanvas.width || 1, vh = overlayCanvas.height || 1;
        octx.clearRect(0, 0, vw, vh);
        const px = point.x * vw, py = point.y * vh;
        // titik + ring
        octx.beginPath(); octx.arc(px, py, 9, 0, Math.PI * 2); octx.strokeStyle = '#22ff66'; octx.lineWidth = 2.5; octx.stroke();
        octx.beginPath(); octx.arc(px, py, 3, 0, Math.PI * 2); octx.fillStyle = '#22ff66'; octx.fill();
    }

    let lastTick = 0;
    function loopTick() {
        const now = performance.now();
        if (now - lastTick < 150) return; // ~6-7 fps
        lastTick = now;
        frameProcess();
        drawOverlay();
        if (!IS_STREAM) reloadFrame(); // MJPEG sudah mengalir sendiri
    }
    setInterval(loopTick, 50);

    // klik untuk set titik
    overlayCanvas.addEventListener('pointerdown', (e) => {
        const r = overlayCanvas.getBoundingClientRect();
        point.x = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width));
        point.y = Math.max(0, Math.min(1, (e.clientY - r.top) / r.height));
        drawOverlay();
    });

    $('btnStart').addEventListener('click', () => { running = true; });
    $('btnStop').addEventListener('click', () => { running = false; });
    $('btnReset').addEventListener('click', () => { running = false; count = 0; counterBig.textContent = '0'; wasChicken = false; });
    $('btnCam').addEventListener('click', reloadFrame);

    function setSlider(id, val) {
        const el = $(id);
        el.value = val;
        $(id + 'V').textContent = val;
        S[id] = val; // langsung update state (tanpa menunggu event input)
    }

    // AUTO HSV: ambil statistik HSV dari piksel terang (ayam) di jalur rel pada frame saat ini,
    // lalu set slider S/V otomatis (seperti AUTO ROI di timbangan).
    function autoHsv() {
        const msg = $('hsvMsg');
        if (!frameImg.naturalWidth) {
            msg.textContent = 'Tunggu gambar kamera muncul dulu.';
            return;
        }
        pctx.drawImage(frameImg, 0, 0, PROC_W, PROC_H);
        const img = pctx.getImageData(0, 0, PROC_W, PROC_H);
        const data = img.data;

        // 1) Cari terang maksimum di jalur rel (pita horizontal di sekitar titik)
        const cy = Math.floor(point.y * PROC_H);
        const bandH = 22;
        const y0 = Math.max(0, cy - bandH), y1 = Math.min(PROC_H - 1, cy + bandH);
        let maxV = 0;
        for (let y = y0; y <= y1; y++) {
            for (let x = 0; x < PROC_W; x++) {
                const i = (y * PROC_W + x) * 4;
                const v = Math.max(data[i], data[i + 1], data[i + 2]);
                if (v > maxV) maxV = v;
            }
        }
        if (maxV < 120) {
            msg.textContent = 'Tidak ada area terang di jalur rel — geser titik tepat di jalur ayam, lalu coba lagi.';
            return;
        }

        // 2) Kumpulkan piksel kandidat (terang & putih: V >= 75% maxV, S <= 120)
        const thr = maxV * 0.75;
        const ss = [], vs = [];
        for (let y = y0; y <= y1; y++) {
            for (let x = 0; x < PROC_W; x++) {
                const i = (y * PROC_W + x) * 4;
                const [h, s, v] = rgbToHsv(data[i], data[i + 1], data[i + 2]);
                if (v >= thr && s <= 120) { ss.push(s); vs.push(v); }
            }
        }
        if (ss.length < 80) {
            msg.textContent = 'Tidak cukup piksel ayam terdeteksi — tunggu ayam lewat titik, lalu tekan lagi.';
            return;
        }

        // 3) Persentil -> batas S/V (hue dibiarkan full karena ayam putih: S rendah, hue tidak stabil)
        const pct = (arr, p) => { const a = [...arr].sort((a, b) => a - b); return a[Math.floor(p * (a.length - 1))]; };
        const sMax = Math.max(20, Math.min(255, Math.round(pct(ss, 0.9)) + 20));
        const vMin = Math.max(0, Math.min(250, Math.round(pct(vs, 0.1)) - 25));

        setSlider('hMin', 0); setSlider('hMax', 179);
        setSlider('sMin', 0); setSlider('sMax', sMax);
        setSlider('vMin', vMin); setSlider('vMax', 255);
    }

    $('btnAutoHsv').addEventListener('click', autoHsv);

    $('btnSave').addEventListener('click', async () => {
        if (count <= 0) { alert('Belum ada ayam terhitung dalam batch ini.'); return; }
        const station = 'POS-01';
        try {
            const res = await fetch('/api/hitung/batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ station, count, direction }),
            });
            if (!res.ok) throw new Error('http ' + res.status);
            count = 0; counterBig.textContent = '0'; wasChicken = false;
            loadHistory();
        } catch (e) {
            alert('Gagal simpan batch: ' + (e && e.message || e));
        }
    });

    async function loadHistory() {
        try {
            const res = await fetch('/api/hitung/history?station=POS-01');
            const data = await res.json();
            const rows = (data.rows || []).map((r) => {
                const t = new Date(r.counted_at);
                const label = t.toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                return `<tr class="border-t border-slate-100"><td class="py-1">${label}</td><td class="text-right font-mono font-bold">${r.count}</td><td class="text-right font-mono text-slate-500">${r.direction}</td></tr>`;
            }).join('');
            $('histBody').innerHTML = rows || '<tr><td colspan="3" class="py-3 text-center text-slate-400">Belum ada data</td></tr>';
        } catch (e) {}
    }
    $('btnReloadHist').addEventListener('click', loadHistory);
    loadHistory();
    if (!IS_STREAM) reloadFrame();
})();
</script>
@endsection
