@extends('layouts.app')

@section('title', 'Pengaturan Timbangan')

@section('content')
    <div class="mb-8">
        <div class="flex items-center gap-2 text-xs mb-2">
            <span class="text-gray-400">Accurate ERP</span>
            <span class="text-gray-300">/</span>
            <span class="text-blue-700">Pengaturan</span>
        </div>
        <h1 class="text-2xl text-gray-900">Pengaturan Indikator Digital & Server</h1>
        <p class="text-gray-600 text-sm mt-1">Port serial COM timbangan, ambang goyang, mode auto, dan printer struk. Halaman ini punya URL sendiri (<span class="font-mono">/settings</span>) — aman di-refresh.</p>
    </div>

    <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- Company Header Settings (untuk Kop DHK) -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4">Kop Surat DHK</h2>
            <p class="text-gray-600 text-sm mb-4">Atur nama & alamat perusahaan yang akan muncul di form pengiriman.</p>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-700">Nama Perusahaan</label>
                    <input type="text" name="company_name" value="{{ $settings->company_name }}" placeholder="PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('company_name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-700">Alamat Perusahaan</label>
                    <input type="text" name="company_address" value="{{ $settings->company_address }}" placeholder="Jl. Industri Raya No. 88, Cikarang" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('company_address') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-700">Telepon / Fax (kop DHK)</label>
                    <input type="text" name="company_phone" value="{{ $settings->company_phone ?? '' }}" placeholder="021-59409214" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('company_phone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-700">Logo Perusahaan (kop DHK, kiri atas)</label>
                    @if(!empty($settings->company_logo))
                        <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Logo" class="h-16 mt-2 mb-1 border border-gray-200 rounded bg-white">
                    @endif
                    <input type="file" name="company_logo" accept=".png,.jpg,.jpeg" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:px-3 file:py-2 file:border file:border-gray-300 file:rounded-md file:bg-gray-50">
                    <p class="text-gray-500 text-xs mt-1">PNG/JPG max 2MB. Kosongkan = pakai logo lama.</p>
                    @error('company_logo') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- Serial Port Settings -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4">Pengaturan Serial Port</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-700">COM Port</label>
                    <select name="com_port" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono">
                        <option value="AUTO" @selected($settings->com_port === 'AUTO')>AUTO — deteksi otomatis (disarankan)</option>
                        @for($i = 1; $i <= 20; $i++)
                            <option value="COM{{ $i }}" @selected($settings->com_port === 'COM'.$i)>COM{{ $i }}</option>
                        @endfor
                    </select>
                    <p class="text-gray-600 text-xs mt-2"><code>AUTO</code> = bridge menemukan port sendiri. Pilih COM manual hanya bila AUTO gagal.</p>
                    @error('com_port') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Baud Rate</label>
                    <select name="baud_rate" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="9600" @selected($settings->baud_rate == 9600)>9600</option>
                        <option value="19200" @selected($settings->baud_rate == 19200)>19200</option>
                        <option value="38400" @selected($settings->baud_rate == 38400)>38400</option>
                        <option value="115200" @selected($settings->baud_rate == 115200)>115200</option>
                    </select>
                    @error('baud_rate') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Parity</label>
                    <select name="parity" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="N" @selected($settings->parity == 'N')>None (N)</option>
                        <option value="E" @selected($settings->parity == 'E')>Even (E)</option>
                        <option value="O" @selected($settings->parity == 'O')>Odd (O)</option>
                    </select>
                    @error('parity') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm text-gray-700">Regex Parsing</label>
                <input type="text" name="parsing_regex" value="{{ $settings->parsing_regex }}" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono text-xs">
                <p class="text-gray-600 text-xs mt-2">Contoh: <code>/STX\s+([\d.]+)\s+kg/</code></p>
                @error('parsing_regex') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Sampling Settings -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4">Pengaturan Sampling</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-700">Frekuensi Sampling (Hz)</label>
                    <input type="number" name="sampling_hz" value="{{ $settings->sampling_hz }}" min="5" max="100" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('sampling_hz') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Durasi Sampling (detik)</label>
                    <input type="number" name="duration_sec" value="{{ $settings->duration_sec }}" min="1" max="60" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('duration_sec') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Trim Percent (%)</label>
                    <input type="number" name="trim_percent" value="{{ $settings->trim_percent }}" min="0" max="50" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-600 text-xs mt-2">Buang X% dari atas dan X% dari bawah</p>
                    @error('trim_percent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm text-gray-700">Threshold Goyangan Ekstrem (kg)</label>
                <input type="number" name="unstable_threshold_kg" value="{{ $settings->unstable_threshold_kg }}" step="0.1" min="0.1" max="5" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                <p class="text-gray-600 text-xs mt-2">Jika selisih max-min lebih dari nilai ini, tampilkan warning</p>
                @error('unstable_threshold_kg') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Auto Mode Settings -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                Pengaturan Mode Auto Cepat
            </h2>
            
            <div class="flex items-center mb-6">
                <input type="hidden" name="auto_mode_enabled" value="0">
                <input type="checkbox" name="auto_mode_enabled" value="1" id="auto_mode_enabled" @checked($settings->auto_mode_enabled) class="rounded">
                <label for="auto_mode_enabled" class="ml-2 text-sm text-gray-900">Aktifkan Mode Auto Loop</label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-700">Auto Trigger (kg)</label>
                    <input type="number" name="auto_trigger_kg" value="{{ $settings->auto_trigger_kg }}" step="0.1" min="5" max="20" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-600 text-xs mt-2">Berat threshold untuk mulai sampling otomatis</p>
                    @error('auto_trigger_kg') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Auto Empty (kg)</label>
                    <input type="number" name="auto_empty_kg" value="{{ $settings->auto_empty_kg }}" step="0.1" min="0.5" max="5" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-600 text-xs mt-2">Berat threshold untuk kembali ke IDLE</p>
                    @error('auto_empty_kg') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Auto Duration (detik)</label>
                    <input type="number" name="auto_duration_sec" value="{{ $settings->auto_duration_sec }}" step="0.5" min="0.5" max="60" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-600 text-xs mt-2">Durasi sampling di mode auto. Di bawah 1 dtk = praktis 1x jepret (statistik + tolak-gerak lumpuh).</p>
                    @error('auto_duration_sec') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4 flex items-center">
                <input type="hidden" name="beep_enabled" value="0">
                <input type="checkbox" name="beep_enabled" value="1" id="beep_enabled" @checked($settings->beep_enabled) class="rounded">
                <label for="beep_enabled" class="ml-2 text-sm text-gray-900">Aktifkan Beep Sound saat Tersimpan</label>
            </div>
        </div>

        <!-- Printer Struk Thermal -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4H7a2 2 0 01-2-2v-4a2 2 0 012-2h10a2 2 0 012 2v4a2 2 0 01-2 2h-2zm-4-4a2 2 0 100-4 2 2 0 000 4z"></path></svg>
                Printer Struk Thermal
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-700">Nama Printer</label>
                    <input type="text" name="printer_name" value="{{ $settings->printer_name }}" placeholder="cth: XPrinter 58mm" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-gray-600 text-xs mt-2">Kosongkan bila belum ada printer</p>
                    @error('printer_name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Lebar Kertas</label>
                    <select name="receipt_paper" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="58mm" @selected($settings->receipt_paper === '58mm')>58mm</option>
                        <option value="80mm" @selected($settings->receipt_paper === '80mm')>80mm</option>
                    </select>
                    @error('receipt_paper') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-700">Footer Struk</label>
                    <input type="text" name="receipt_footer" value="{{ $settings->receipt_footer }}" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    @error('receipt_footer') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- Kamera -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg text-gray-900 mb-4">Kamera Pos Timbang</h2>
            <div>
                <label class="block text-sm text-gray-700">Snapshot URL (MJPEG/snapshot IP cam)</label>
                <input type="text" name="cam_snapshot_url" value="{{ $settings->cam_snapshot_url }}" placeholder="cth: http://192.168.1.50/shot.jpg" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono text-xs">
                <p class="text-gray-600 text-xs mt-2">Dipakai service OCR (wajib JPEG/snapshot, BUKAN video stream).</p>
                @error('cam_snapshot_url') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-700">Video URL (live MJPEG, khusus pratinjau)</label>
                <input type="text" name="cam_stream_url" value="{{ $settings->cam_stream_url ?? '' }}" placeholder="cth: http://192.168.1.50:8080/videofeed" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono text-xs">
                <p class="text-gray-600 text-xs mt-2">Pratinjau jadi video live tanpa refresh. Kosongkan = pakai Snapshot URL (refresh 2 detik).</p>
                @error('cam_stream_url') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-700">Putar Gambar Kamera</label>
                <select name="cam_rotate" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <option value="0" @selected((int) ($settings->cam_rotate ?? 0) === 0)>0° — normal (HP landscape)</option>
                    <option value="90" @selected((int) ($settings->cam_rotate ?? 0) === 90)>90° — HP miring kanan</option>
                    <option value="180" @selected((int) ($settings->cam_rotate ?? 0) === 180)>180° — HP terbalik</option>
                    <option value="270" @selected((int) ($settings->cam_rotate ?? 0) === 270)>270° — HP miring kiri</option>
                </select>
                <p class="text-gray-600 text-xs mt-2">Kalau HP miring, gambar diputar balik oleh server sebelum OCR — ROI & kalibrasi tetap dalam posisi tegak. Ganti ini → STOP + START ulang service.</p>
                @error('cam_rotate') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-700">Batas keyakinan OCR (0–1)</label>
                <input type="number" name="ocr_min_conf" value="{{ $settings->ocr_min_conf ?? 0.4 }}" step="0.05" min="0" max="1" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono text-xs">
                <p class="text-gray-600 text-xs mt-2">Bacaan di bawah ini dibuang (nggak masuk LED/DB). Kecilkan kalau sering gagal, besarkan kalau sering ngawur.</p>
                @error('ocr_min_conf') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-4">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-6 rounded">
                Simpan Pengaturan
            </button>
            <a href="{{ route('dashboard') }}" class="bg-gray-600 hover:bg-gray-700 text-white py-2 px-6 rounded">
                Batal
            </a>
        </div>
    </form>

    <!-- Info Panel -->
    <div class="mt-8 space-y-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
            <h3 class="text-lg text-blue-900 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                Manual Mode
            </h3>
            <ul class="text-blue-800 text-sm space-y-2">
                <li>• Untuk testing & quality control</li>
                <li>• Manual isi supplier, mobil, keranjang per penimbangan</li>
                <li>• Klik "TIMBANG 5 DETIK" untuk mulai sampling</li>
                <li>• Klik "SIMPAN" untuk menyimpan hasil</li>
            </ul>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
            <h3 class="text-lg text-yellow-900 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                Auto Mode (Fast Loop)
            </h3>
            <ul class="text-yellow-800 text-sm space-y-2">
                <li>• Untuk produksi cepat (ratusan keranjang/hari)</li>
                <li>• Setup 1x sesi: supplier, mobil, keranjang, tara</li>
                <li>• State Machine: IDLE → TRIGGER → COUNTING → SAVED → LOCK → IDLE</li>
                <li>• Letakkan keranjang → Auto trigger saat berat > {{ $settings->auto_trigger_kg }}kg</li>
                <li>• Sampling {{ $settings->auto_duration_sec }} detik otomatis</li>
                <li>• Beep + counter increment saat tersimpan</li>
                <li>• Tunggu kosong (< {{ $settings->auto_empty_kg }}kg) untuk cycle berikutnya</li>
            </ul>
        </div>

        <div class="bg-green-50 border border-green-200 rounded-lg p-6">
            <h3 class="text-lg text-green-900 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Sampling & Algoritma
            </h3>
            <ul class="text-green-800 text-sm space-y-2">
                <li>• Total sampel = {{ $settings->sampling_hz }}Hz × {{ $settings->duration_sec }} detik = {{ $settings->sampling_hz * $settings->duration_sec }} sampel</li>
                <li>• Trimmed Mean: buang {{ $settings->trim_percent }}% top + {{ $settings->trim_percent }}% bottom</li>
                <li>• Warning jika selisih max-min > {{ $settings->unstable_threshold_kg }}kg</li>
                        <li>• Raw samples disimpan sebagai JSON untuk audit</li>
                    </ul>
                </div>
            </div>
@endsection

