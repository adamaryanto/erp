# ⚙️ Panduan Pengaturan ERP Timbang

Halaman **Pengaturan** (`/settings`) berisi semua parameter yang mengatur cara sistem membaca timbangan, menghitung rata-rata, dan menjalankan mode auto. Yang wajib benar cuma 2: **COM port + baud**. Sisanya default-nya sudah pas untuk ayam hidup.

---

## 1. Pengaturan Serial Port (koneksi kabel)

| Setting | Default | Artinya |
|---|---|---|
| COM Port | `COM3` | Colokan USB timbangan di PC pos timbang (cek di Device Manager → Ports) |
| Baud Rate | `9600` | Kecepatan data — **harus sama** dengan setting di indikator BX1 |
| Parity | `N` | Pengecekan error data — umumnya `N` (None) |
| Regex Parsing | `/STX\s+([\d.]+)\s+kg/` | Pola untuk menemukan angka berat di dalam teks mentah kabel |

**Contoh kerja regex:** kabel mengirim `STX 23.450 kg` → pola ini mengambil `23.450` sebagai berat.

**Kapan diubah:** hanya kalau timbangan diganti / format datanya beda. Lihat output `[DEBUG] Baris tak dikenali` di bridge untuk tahu format aslinya. Bridge juga punya fallback otomatis (ambil angka terakhir di baris), jadi biasanya tidak perlu disentuh.

---

## 2. Pengaturan Sampling (cara menghitung rata-rata)

| Setting | Default | Artinya |
|---|---|---|
| Frekuensi Sampling | `10` Hz | Mencatat angka 10x per detik |
| Durasi Sampling | `5` detik | Lama mencatat per keranjang → total **50 sampel** |
| Trim Percent | `10` % | Buang 10% sampel terkecil + 10% terbesar (outlier tendangan ayam), sisanya dirata-rata |
| Threshold Goyangan | `0,8` kg | Kalau selisih max−min sampel > ini → warning *"Goyangan Ekstrem, sarankan ulangi"* |

**Kapan diubah:**
- Warning terlalu sering padahal hasil oke → naikkan threshold ke `1,0`
- Mau lebih ketat → turunkan ke `0,5`
- Frekuensi/durasi/trim praktis jangan diubah (10 Hz × 5 detik × 10% sudah pas)

---

## 3. Mode Auto Cepat (timbang berulang tanpa klik)

| Setting | Default | Artinya |
|---|---|---|
| Aktifkan Mode Auto Loop | ✓ | Nyalakan/matikan fitur auto |
| Auto Trigger | `10` kg | Berat di atas ini (+ stabil 0,5 detik) = keranjang masuk → otomatis mulai hitung |
| Auto Empty | `2` kg | Berat di bawah ini (+ jeda 2 detik) = keranjang diangkat → siap untuk berikutnya |
| Auto Duration | `5` detik | Lama hitung per keranjang di mode auto (samakan dengan Durasi Sampling) |
| Beep | ✓ | Bunyi "tit" tiap 1 keranjang ke-record (tanda buat operator: boleh angkat) |

**Alur auto:** taruh keranjang → hitung 5 detik → ke-record + beep → angkat (display ikut NOL) → taruh berikutnya. Counter `#1, #2, …` + total kg sesi tampil di layar.

---

## 4. Printer Struk Thermal

| Setting | Default | Artinya |
|---|---|---|
| Nama Printer | _(kosong)_ | Nama printer di Windows (cth: `XPrinter 58mm`). Kosongkan bila belum ada |
| Lebar Kertas | `58mm` | Ukuran kertas struk (`58mm` / `80mm`) |
| Footer Struk | `Terima kasih` | Teks penutup di bawah struk |

---

## Catatan teknis

- **Checkbox yang dimatikan tetap tersimpan** (ada hidden input `0` di baliknya).
- Kolom `simulate_mode` / `simulate_noise_level` masih ada di database tapi sudah tidak dipakai di UI (simulator dihapus).
- Perubahan di sini langsung berlaku untuk penimbangan berikutnya — tidak perlu restart, kecuali **COM port & baud** (bridge `reader.py` harus dijalankan ulang dengan argumen baru).
- Semua halaman punya URL sendiri (`/settings`, `/master`, `/history`, …) — aman di-refresh.
