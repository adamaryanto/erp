<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\DeliverySession;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            'sessions' => DeliverySession::with(['supplier', 'basket'])
                ->orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(),
            'baskets' => Basket::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
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
        $session->load(['supplier', 'basket']);
        $weighings = $session->matchedWeighings()->orderBy('weighed_at')->get();
        $baskets = Basket::orderBy('code')->get();
        $settings = Setting::config();

        return view('delivery.show', compact('session', 'weighings', 'baskets', 'settings'));
    }

    public function update(Request $request, DeliverySession $session): RedirectResponse
    {
        $validated = $request->validate([
            'basket_id' => 'nullable|exists:baskets,id',
            'jenis_ayam' => 'nullable|string|max:20',
            'basket_deduction_manual' => 'nullable|numeric|min:0',
            'driver_name' => 'nullable|string|max:100',
            'do_weight' => 'required|numeric|min:0',
            'total_ekor' => 'required|integer|min:0',
            'dead_count' => 'nullable|integer|min:0',
            'dead_kg' => 'nullable|numeric|min:0',
            'wet_kg' => 'nullable|numeric|min:0',
            'wet_count' => 'nullable|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $session->update($validated);

        return redirect()->route('delivery.show', $session)->with('success', 'Rekap diperbarui');
    }

    public function destroy(DeliverySession $session): RedirectResponse
    {
        $session->delete();

        return redirect()->route('delivery.index')->with('success', 'Sesi dihapus (data timbangan tetap ada)');
    }

    /**
     * Export file Excel (.xlsx) beneran dengan layout mirip sheet timbangan:
     * grid No/Kg 6 kolom + blok ringkasan di kanan.
     */
    public function excel(DeliverySession $session): StreamedResponse
    {
        $session->load(['supplier', 'basket']);
        $weighings = $session->matchedWeighings()->orderBy('weighed_at')->get()->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Timbang');

        $sheet->fromArray(['Tanggal Potong :', null, null, $session->delivered_at->format('d/m/Y')], null, 'A1', true);
        $sheet->fromArray(['Nama Supir :', null, null, $session->driver_name ?: '-'], null, 'A2', true);
        $sheet->fromArray(['No Plat Mobil :', null, null, $session->vehicle_no, null, 'Jenis Ayam :', strtoupper($session->jenis_ayam ?? 'BIASA')], null, 'A3', true);
        $sheet->fromArray(['No', 'Kg', 'No', 'Kg', 'No', 'Kg', 'No', 'Kg', 'No', 'Kg', 'No', 'Kg'], null, 'A4', true);

        $count = $weighings->count();
        $perCol = max(1, (int) ceil($count / 6));
        $cols = [];
        for ($c = 0; $c < 6; $c++) {
            $cols[] = $weighings->slice($c * $perCol, $perCol)->values();
        }

        $summary = [
            ['Potong Keranjang', null, ':', $count, (float) $session->basketDeduction(), null],
            ['DO Asal', null, ':', (float) $session->do_weight, null, null],
            ['Total Ekor', null, ':', $session->total_ekor, null, null],
            ['Ayam Mati', null, ':', $session->dead_count, (float) $session->dead_kg, 'kg'],
            ['Rata2 Ayam', null, ':', (float) $session->avgBird(), null, null],
            ['Timbang Bersih', null, ':', (float) $session->grossTotal(), (float) $session->netTotal(), null],
            ['Susut', null, ':', null, (float) $session->shrinkage(), 'kg'],
            [null, null, null, null, $session->shrinkagePct() / 100, null],
            ['Persentase', null, null, null, $session->shrinkagePct() / 100, null],
        ];

        $rowNum = 5;
        for ($i = 0; $i < max($perCol, count($summary)); $i++) {
            $col = 1;
            for ($c = 0; $c < 6; $c++) {
                $w = $cols[$c]->get($i);
                if ($w) {
                    $sheet->setCellValue([$col, $rowNum], $c * $perCol + $i + 1);
                    $sheet->setCellValue([$col + 1, $rowNum], (float) $w->gross_avg);
                }
                $col += 2;
            }
            if (isset($summary[$i])) {
                foreach ($summary[$i] as $offset => $value) {
                    if ($value !== null) {
                        $sheet->setCellValue([13 + $offset, $rowNum], $value);
                    }
                }
            }
            $rowNum++;
        }

        $col = 1;
        foreach ($cols as $colData) {
            $sheet->setCellValue([$col + 1, $rowNum], (float) $colData->sum('gross_avg'));
            $col += 2;
        }

        $sheet->getStyle([17, 13])->getNumberFormat()->setFormatCode('0.00%');

        foreach (range(1, 18) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $filename = 'rekap-mobil-'.preg_replace('/[^A-Za-z0-9]+/', '-', $session->vehicle_no).'-'.$session->delivered_at->format('Y-m-d').'.xlsx';

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
