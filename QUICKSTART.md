# Quick Start Guide - ERP Timbang Ayam Hidup MVP Phase 1

## Prerequisites
- PHP 8.3+
- Laravel 13
- MySQL/SQLite
- Python 3.8+ (untuk bridge reader)
- Node.js 18+ (untuk frontend assets)

## Installation & Setup

### 1. Clone & Install Dependencies
```bash
cd C:\laragon\www\Project-Erp
composer install
npm install
```

### 2. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 3. Database Setup
```bash
php artisan migrate
php artisan db:seed
```

Database sudah berisi:
- 3 Supplier (Barokah Jaya, Ternak Sejahtera, Plasma Unggul)
- 3 Keranjang (B01, B02, B03) dengan tare default
- 3 User (1 admin, 2 operator)
- 1 Setting record dengan default config

### 4. Run Application

**Terminal 1 - Laravel Server:**
```bash
php artisan serve
```
Akses: http://localhost:8000

**Terminal 2 - Frontend Build (dev):**
```bash
npm run dev
```

**Terminal 3 - Bridge Reader (Simulator mode untuk testing):**
```bash
# Install Python dependencies dulu
pip install pyserial requests

# Jalankan simulator
python bridge/reader.py --simulate --api-url http://localhost:8000/api/weighings/live
```

## Login Credentials

**Admin Account:**
- Email: `admin@rpa.test`
- Password: `password`
- Role: admin (akses semua fitur termasuk pengaturan & delete)

**Operator Accounts:**
- Email: `operator1@rpa.test` / `operator2@rpa.test`
- Password: `password`
- Role: operator (akses penimbangan & riwayat, TIDAK bisa delete & pengaturan)

## Testing the Flow

### Scenario 1: Simulator Mode (No Hardware)

1. **Login** dengan admin@rpa.test
2. **Go to Dashboard** - lihat overview
3. **Click "Mulai Penimbangan"** atau akses `/weighing/live`
4. **Setup Sesi:**
   - Pilih Supplier: "Barokah Jaya"
   - No. Mobil: "B 9482 FJA"
5. **Enable Simulator Mode** (checkbox di panel kanan)
6. **Adjust Noise Level** (slider) - simulasikan goyangan
7. **Click "TIMBANG 5 DETIK"** - akan sampling 50 data dalam 5 detik
8. **Hasil akan menampilkan:**
   - Bruto (trimmed mean)
   - MIN/MAX/Selisih/STDEV
   - Warning jika selisih > 0.8kg
9. **Pilih Keranjang** (B01/B02/B03) - tare otomatis terisi
10. **Click "SIMPAN"** - data tersimpan ke database
11. **Go to "Riwayat"** - lihat data yang baru disimpan
12. **Go to "Rekap"** - lihat summary dengan totals
13. **Export CSV** - download format Accurate

### Scenario 2: Live Scale (With Hardware)

1. **Hubungkan timbangan** via RS-232C/USB FTDI ke PC
2. **Go to Pengaturan** (admin only):
   - Verifikasi COM Port (e.g., COM3)
   - Verifikasi Baud Rate (9600)
   - Verifikasi Parsing Regex
3. **Jalankan bridge reader:**
   ```bash
   python bridge/reader.py --com-port COM3 --baud 9600 --api-url http://localhost:8000/api/weighings/live
   ```
4. **Go to Penimbangan Live**
   - Disable Simulator Mode
   - Letakkan keranjang kosong di timbangan
   - Angka akan real-time update
   - Mulai sampling dengan "TIMBANG 5 DETIK"

## File Locations

### Key Controllers
- `app/Http/Controllers/WeighingLiveController.php` - Halaman penimbangan live
- `app/Http/Controllers/HistoryController.php` - Riwayat penimbangan
- `app/Http/Controllers/SummaryController.php` - Rekap & export
- `app/Http/Controllers/SettingsController.php` - Pengaturan (admin)
- `app/Http/Controllers/api/WeighingController.php` - API endpoints

### Key Models
- `app/Models/Weighing.php` - Data penimbangan
- `app/Models/Supplier.php` - Data supplier
- `app/Models/Basket.php` - Data keranjang
- `app/Models/Setting.php` - Konfigurasi sistem
- `app/Models/User.php` - User & role

### Views
- `resources/views/layouts/app.blade.php` - Main layout (Accurate-style sidebar)
- `resources/views/weighing/live.blade.php` - Halaman timbang live (main UI)
- `resources/views/weighing/history.blade.php` - Riwayat tabel
- `resources/views/weighing/summary.blade.php` - Rekap & export
- `resources/views/settings/index.blade.php` - Pengaturan form
- `resources/views/auth/login.blade.php` - Login page

### Bridge Reader
- `bridge/reader.py` - Python script untuk baca serial port

### Database
- `database/migrations/` - Schema definitions
- `database/seeders/` - Sample data (suppliers, baskets, users)

## Trimmed Mean Algorithm

Input: 50 sampel (10Hz × 5 detik)

1. Sort ascending: [20.1, 20.2, ..., 24.9]
2. Hitung trim count: 50 × 10% = 5
3. Buang 5 dari bawah + 5 dari atas = sisa 40 sampel
4. Hitung rata-rata dari 40 sampel tersebut = **Bruto**
5. Hitung STDEV dari original 50 sampel
6. Jika (Max - Min) > 0.8kg → warning

Formula:
```
Bruto = Mean(sorted[5:45])
Netto = Bruto - Tare
```

## CSV Export Format (Accurate)

Dihasilkan dari menu "Rekap" → "Export CSV"

```csv
No Bukti,Tanggal,Jam,Supplier,Mobil,Bruto,Tara,Netto,Operator
1,2026-09-17,08:30:00,Barokah Jaya,B 9482 FJA,23.45,2.80,20.65,Admin User
```

Format ini kompatibel untuk import ke Accurate Online.

## API Documentation

### 1. POST `/api/weighings/live`
Menerima berat real-time dari bridge reader

**Request:**
```json
{
  "weight": 23.450
}
```

**Response:**
```json
{
  "status": "received",
  "weight": 23.450
}
```

### 2. POST `/api/weighings/finalize`
Menyimpan hasil penimbangan ke database

**Request:**
```json
{
  "supplier_id": 1,
  "basket_id": 1,
  "vehicle_no": "B 9482 FJA",
  "gross_avg": 23.45,
  "tare_kg": 2.8,
  "min_kg": 22.1,
  "max_kg": 24.8,
  "stddev": 0.654,
  "raw_samples": [23.1, 23.2, 23.4, ...],
  "is_manual_tare": false,
  "note": "Keranjang basah"
}
```

**Response:**
```json
{
  "status": "saved",
  "weighing": {
    "id": 1,
    "supplier_id": 1,
    "basket_id": 1,
    "vehicle_no": "B 9482 FJA",
    "gross_avg": 23.45,
    "tare_kg": 2.8,
    "net_kg": 20.65,
    ...
  }
}
```

### 3. GET `/api/weighings/today`
Ambil semua penimbangan hari ini

**Response:**
```json
{
  "weighings": [...],
  "total_count": 45,
  "total_net_kg": 920.35
}
```

### 4. DELETE `/api/weighings/{id}`
Hapus penimbangan (admin only)

## Troubleshooting

### Bridge tidak terhubung ke API
- Pastikan Laravel server running di http://localhost:8000
- Cek firewall/antivirus tidak memblock
- Verifikasi API URL di pengaturan

### Serial port error
- Pastikan COM port benar (Device Manager → Ports)
- Cek baud rate sesuai timbangan (biasanya 9600)
- Coba mode simulator dulu

### Tombol tidak responsif
- Jalankan `npm run dev` untuk build assets
- Refresh browser (Ctrl+F5)

### Data tidak tersimpan
- Cek database running (SQLite atau MySQL)
- Pastikan sudah login (session valid)
- Lihat Laravel logs: `tail -f storage/logs/laravel.log`

## Development Notes

### Adding New Fields to Weighing
1. Buat migration: `php artisan make:migration add_field_to_weighings_table`
2. Update model `Weighing.php` di `#[Fillable(...)]`
3. Run migration: `php artisan migrate`
4. Update API controller validation

### Adding New Supplier/Basket
- Gunakan UI Master Data (TODO: belum dibuat di Phase 1)
- Atau buat seeder baru & run `php artisan db:seed`

### Changing Sampling Parameters
- Edit di halaman Settings (admin only)
- Atau langsung di database table `settings`
- Bridge reader akan membaca dari settings saat startup

## Next Steps (Phase 2+)

- Master Data management UI (Supplier, Basket, User CRUD)
- Cetak struk thermal 58mm
- Diagnostik page (COM status, raw logs)
- Export raw_samples untuk analisis
- Integration dengan Accurate Online API
- Role-based dashboard customization
- Offline mode dengan queue
- Multi-cabang support
- Analytics & reporting dashboard

## Support

Untuk issues atau pertanyaan:
1. Cek MVP_DOCUMENTATION.md
2. Lihat Laravel logs: `storage/logs/laravel.log`
3. Debug di browser console (F12)
4. Test bridge dengan `python bridge/reader.py --simulate`
