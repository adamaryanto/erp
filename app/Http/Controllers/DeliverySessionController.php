<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\DeliveryOrder;
use App\Models\DeliverySession;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliverySessionController extends Controller
{
    /**
     * Daftar sesi per mobil + form buka sesi baru.
     */
    public function index(): View
    {
        return view('delivery.index', [
            'sessions' => DeliverySession::with(['supplier', 'basket', 'deliveryOrder'])
                ->orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(),
            'baskets' => Basket::orderBy('code')->get(),
            'openOrders' => DeliveryOrder::with('supplier')->where('status', 'open')
                ->orderByDesc('issued_at')->orderByDesc('id')->get(),
            // Supir yg pernah tercatat + plat terakhirnya (buat autofill plat).
            'knownDrivers' => DeliverySession::whereNotNull('driver_name')
                ->where('driver_name', '<>', '')
                ->orderByDesc('id')->get(['driver_name', 'vehicle_no'])
                ->unique(fn ($s) => strtoupper(trim($s->driver_name)))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'do_id' => 'nullable|exists:delivery_orders,id',
            'basket_id' => 'nullable|exists:baskets,id',
            'jenis_ayam' => 'nullable|string|max:20',
            'basket_deduction_manual' => 'nullable|numeric|min:0',
            'vehicle_no' => 'required|string|max:20',
            'driver_name' => 'nullable|string|max:100',
            'delivered_at' => 'required|date',
            'do_weight' => 'required|numeric|min:0',
            'total_ekor' => 'required|integer|min:0',
            'dead_count' => 'nullable|integer|min:0',
            'dead_kg' => 'nullable|numeric|min:0',
            'wet_kg' => 'nullable|numeric|min:0',
            'wet_count' => 'nullable|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $validated['vehicle_no'] = strtoupper(trim($validated['vehicle_no']));

        $session = DeliverySession::create($validated);

        return redirect()->route('delivery.show', $session)->with('success', 'Sesi dibuka');
    }

    /**
     * Rekap per mobil: angka timbangan + input DO/ekor/mati/basah + cetak DHK.
     */
    public function show(DeliverySession $session): View
    {
        $session->load(['supplier', 'basket', 'deliveryOrder']);
        $weighings = $session->matchedWeighings()->orderBy('weighed_at')->get();
        $baskets = Basket::orderBy('code')->get();
        $settings = Setting::config();

        return view('delivery.show', compact('session', 'weighings', 'baskets', 'settings'));
    }

    public function update(Request $request, DeliverySession $session): RedirectResponse
    {
        $validated = $request->validate([
            'basket_id' => 'nullable|exists:baskets,id',
            'do_id' => 'nullable|exists:delivery_orders,id',
            'jenis_ayam' => 'nullable|string|max:20',
            'basket_deduction_manual' => 'nullable|numeric|min:0',
            'vehicle_no' => 'required|string|max:20',
            'driver_name' => 'nullable|string|max:100',
            'do_weight' => 'required|numeric|min:0',
            'total_ekor' => 'required|integer|min:0',
            'dead_count' => 'nullable|integer|min:0',
            'dead_kg' => 'nullable|numeric|min:0',
            'wet_kg' => 'nullable|numeric|min:0',
            'wet_count' => 'nullable|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $validated['vehicle_no'] = strtoupper(trim($validated['vehicle_no']));

        $session->update($validated);

        return redirect()->route('delivery.show', $session)->with('success', 'Rekap diperbarui');
    }

    public function destroy(DeliverySession $session): RedirectResponse
    {
        $session->delete();

        return redirect()->route('delivery.index')->with('success', 'Sesi dihapus (data timbangan tetap ada)');
    }

    /**
     * Export file Excel (.xlsx) yang meniru 1:1 FORM PENGIRIMAN AYAM HIDUP
     * (kop perusahaan, grid No/Kg 6 kolom blok 25 baris + TTL, bar
     * Timbangan Kotor, footer potongan + susut/persentase).
     */
    public function excel(DeliverySession $session): StreamedResponse
    {
        $session->load(['supplier', 'basket']);
        $settings = Setting::config();
        $weighings = $session->matchedWeighings()->orderBy('weighed_at')->get()->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Form Pengiriman');
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

        // Kolom No sempit, kolom Kg lega. Label footer panjang ("POTONG ...")
        // digabung 2 kolom (merge A:B) supaya tidak kepotong kolom No.
        foreach ([1, 3, 5, 7, 9, 11] as $c) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(8);
        }
        foreach ([2, 4, 6, 8, 10, 12] as $c) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(14);
        }

        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF111111']]]];
        $center = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]];

        // Logo kiri atas (kalau dipasang di Pengaturan).
        $row = 1;
        if (! empty($settings->company_logo)) {
            $logoPath = storage_path('app/public/'.$settings->company_logo);
            if (is_file($logoPath)) {
                try {
                    $drawing = new Drawing;
                    $drawing->setPath($logoPath);
                    $drawing->setCoordinates('A1');
                    $drawing->setHeight(70);
                    $drawing->setWorksheet($sheet);
                } catch (\Throwable $e) {
                    // Logo rusak: lewati, jangan gagalkan export.
                }
            }
        }

        // Kop perusahaan.
        $sheet->mergeCells('A1:L1');
        $sheet->setCellValue('A1', strtoupper($settings->company_name ?: 'PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA'));
        $sheet->mergeCells('A2:L2');
        $sheet->setCellValue('A2', strtoupper($settings->company_address ?: ''));
        $sheet->mergeCells('A3:L3');
        $phone = trim((string) ($settings->company_phone ?? ''));
        if ($phone !== '' && ! preg_match('/^telepon\s*:/i', $phone)) {
            $phone = 'TELEPON : '.$phone;
        }
        $sheet->setCellValue('A3', $phone);
        $sheet->getStyle('A1:L3')->applyFromArray($center);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Judul form.
        $sheet->mergeCells('A4:L4');
        $sheet->setCellValue('A4', 'FORM PENGIRIMAN AYAM HIDUP');
        $sheet->getStyle('A4')->applyFromArray($center);
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(13);

        // Kepala data.
        $sheet->setCellValue('A5', 'TANGGAL PROD :');
        $sheet->setCellValue('B5', $session->delivered_at->format('d/m/Y'));
        $sheet->setCellValue('H5', 'RATA-RATA AYAM :');
        $sheet->setCellValue('I5', round($session->avgBird(), 2));
        $sheet->getStyle('I5')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('H5:I5')->getFont()->setBold(true);
        $sheet->setCellValue('A6', 'NAMA PENGIRIM :');
        $sheet->mergeCells('B6:D6');
        $sheet->setCellValue('B6', $session->supplier->name);
        $sheet->getStyle('B6')->getFont()->setBold(true);
        $sheet->setCellValue('A7', 'KG AYAM');
        $sheet->setCellValue('B7', (float) $session->do_weight);
        $sheet->setCellValue('C7', '/');
        $sheet->setCellValue('D7', (int) $session->total_ekor);
        $sheet->getStyle('B7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('B7:D7')->getFont()->setBold(true);

        // Grid No/Kg ngikutin data: ≤25 data = 1 kolom setinggi data (tanpa
        // baris kosong); sampai 150 = kolom-kolom blok 25 ala form kertas;
        // di atas 150 = 6 kolom, baris menyesuaikan.
        $count = $weighings->count();
        if ($count === 0) {
            $pairs = 1;
            $perCol = 5;
        } elseif ($count <= 25) {
            $pairs = 1;
            $perCol = $count;
        } elseif ($count <= 150) {
            $pairs = (int) ceil($count / 25);
            $perCol = 25;
        } else {
            $pairs = 6;
            $perCol = (int) ceil($count / 6);
        }
        $cols = [];
        for ($c = 0; $c < $pairs; $c++) {
            $cols[] = $weighings->slice($c * $perCol, $perCol)->values();
        }
        $gridW = $pairs * 2; // lebar grid terpakai

        $headRow = 8;
        for ($c = 0; $c < $pairs; $c++) {
            $sheet->setCellValue([$c * 2 + 1, $headRow], 'No');
            $sheet->setCellValue([$c * 2 + 2, $headRow], 'Kg');
        }
        $sheet->getStyle([1, $headRow, $gridW, $headRow])->applyFromArray($thin + $center);
        $sheet->getStyle([1, $headRow, $gridW, $headRow])->getFont()->setBold(true);
        $sheet->getStyle([1, $headRow, $gridW, $headRow])->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFEEEEEE');

        $rowNum = $headRow + 1;
        for ($i = 0; $i < $perCol; $i++) {
            for ($c = 0; $c < $pairs; $c++) {
                $w = $cols[$c]->get($i);
                if ($w) {
                    $sheet->setCellValue([$c * 2 + 1, $rowNum], $c * $perCol + $i + 1);
                    $sheet->setCellValue([$c * 2 + 2, $rowNum], (float) $w->gross_avg);
                    $sheet->getStyle([$c * 2 + 2, $rowNum])->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $rowNum++;
        }

        // Baris TTL (1 desimal ala form: 905.8).
        $sheet->setCellValue([1, $rowNum], 'TTL');
        for ($c = 0; $c < $pairs; $c++) {
            $sheet->setCellValue([$c * 2 + 2, $rowNum], round((float) $cols[$c]->sum('gross_avg'), 1));
            $sheet->getStyle([$c * 2 + 2, $rowNum])->getNumberFormat()->setFormatCode('#,##0.0');
        }
        $sheet->getStyle([1, $rowNum, $gridW, $rowNum])->applyFromArray($thin);
        $sheet->getStyle([1, $rowNum, $gridW, $rowNum])->getFont()->setBold(true);
        // Border seluruh grid dari header sampai TTL (hanya yg terpakai).
        $sheet->getStyle([1, $headRow, $gridW, $rowNum])->applyFromArray($thin);
        $rowNum++;

        // Bar Timbangan Kotor selebar grid (1 desimal tanpa pemisah ribuan: "4027.3").
        $sheet->mergeCells([1, $rowNum, $gridW, $rowNum]);
        $sheet->setCellValue([1, $rowNum], 'Timbangan Kotor : '.number_format((float) $session->grossTotal(), 1, '.', ''));
        $sheet->getStyle([1, $rowNum, $gridW, $rowNum])->applyFromArray($thin + $center);
        $rowNum += 2;

        // Footer: label kiri merge A:B (muat "POTONG KERANJANG :"), nilai C,
        // satuan D, cacah E, satuan2 F. Blok kanan (SUSUT/PERSENTASE):
        // label merge G:H, ":" I, nilai J, satuan K. Tak tergantung lebar grid.
        $fr = $rowNum;
        $sheet->mergeCells("A{$fr}:B{$fr}");
        $sheet->setCellValue("A{$fr}", 'POTONG KERANJANG :');
        $sheet->setCellValue("C{$fr}", (float) $session->basketDeduction());
        $sheet->getStyle("C{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("D{$fr}", 'Kg');
        $fr++;
        $sheet->mergeCells("A{$fr}:B{$fr}");
        $sheet->setCellValue("A{$fr}", 'POTONG AYAM MATI :');
        $sheet->setCellValue("C{$fr}", (float) $session->dead_kg);
        $sheet->getStyle("C{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("D{$fr}", 'Kg');
        $sheet->setCellValue("E{$fr}", (int) $session->dead_count);
        $sheet->setCellValue("F{$fr}", 'Ekor');
        $fr++;
        $sheet->mergeCells("A{$fr}:B{$fr}");
        $sheet->setCellValue("A{$fr}", 'BASAH :');
        $sheet->setCellValue("C{$fr}", (float) $session->wet_kg);
        $sheet->getStyle("C{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("D{$fr}", 'Kg');
        $sheet->setCellValue("E{$fr}", (int) ($session->wet_count ?? 0));
        $sheet->setCellValue("F{$fr}", 'Ekor');
        $fr++;
        $sheet->mergeCells("A{$fr}:B{$fr}");
        $sheet->setCellValue("A{$fr}", 'TIMBANG BERSIH :');
        $sheet->setCellValue("C{$fr}", (float) $session->netTotal());
        $sheet->getStyle("C{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("D{$fr}", 'Kg');
        $sheet->mergeCells("G{$fr}:H{$fr}");
        $sheet->setCellValue("G{$fr}", 'SUSUT');
        $sheet->setCellValue("I{$fr}", ':');
        $sheet->setCellValue("J{$fr}", round((float) $session->shrinkage(), 2));
        $sheet->getStyle("J{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("K{$fr}", 'Kg');
        $sheet->getStyle("G{$fr}:H{$fr}")->getFont()->setBold(true);
        $fr++;
        $sheet->mergeCells("A{$fr}:B{$fr}");
        $sheet->setCellValue("A{$fr}", 'DO ASAL :');
        $sheet->setCellValue("C{$fr}", (float) $session->do_weight);
        $sheet->getStyle("C{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("D{$fr}", 'Kg');
        $sheet->mergeCells("G{$fr}:H{$fr}");
        $sheet->setCellValue("G{$fr}", 'PERSENTASE');
        $sheet->setCellValue("I{$fr}", ':');
        $sheet->setCellValue("J{$fr}", round((float) $session->shrinkagePct(), 2));
        $sheet->getStyle("J{$fr}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue("K{$fr}", '%');
        $sheet->getStyle("G{$fr}:H{$fr}")->getFont()->setBold(true);
        $rowNum = $fr + 1;

        // Cetak landscape muat 1 halaman lebar.
        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        $filename = 'form-pengiriman-'.preg_replace('/[^A-Za-z0-9]+/', '-', $session->vehicle_no).'-'.$session->delivered_at->format('Y-m-d').'.xlsx';

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Tampilan cetak ala Form DHK (disimpan user sebagai PDF via dialog print).
     */
    public function pdf(DeliverySession $session): View
    {
        $session->load(['supplier', 'basket']);
        $weighings = $session->matchedWeighings()->orderBy('weighed_at')->get()->values();
        $settings = Setting::config();

        return view('delivery.pdf', compact('session', 'weighings', 'settings'));
    }
}
