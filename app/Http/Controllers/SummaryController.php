<?php

namespace App\Http\Controllers;

use App\Models\DeliverySession;
use App\Models\Supplier;
use App\Models\Weighing;
use Illuminate\Http\Response;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SummaryController extends Controller
{
    /**
     * Show the daily summary dashboard.
     */
    public function index(): View
    {
        $weighings = Weighing::whereDate('weighed_at', today())
            ->with(['supplier', 'basket'])
            ->orderByDesc('weighed_at')
            ->get();

        $totalNetKg = $weighings->sum('net_kg');
        $totalCount = $weighings->count();
        $suppliers = Supplier::withCount(['weighings' => function ($query) {
            $query->whereDate('weighed_at', today());
        }])->get();

        // Balanced harian: hanya sesi yang SUDAH ada timbangannya.
        // Sesi kosong (DO diisi tapi belum nimbang) tidak ikut, biar tidak
        // muncul angka ngaco seperti susut 200% tanpa data.
        $sessions = DeliverySession::whereDate('delivered_at', today())->get();
        $filledSessions = $sessions->filter(fn (DeliverySession $s) => $s->basketCount() > 0);
        $dayDo = (float) $filledSessions->sum('do_weight');
        $dayBersih = (float) $filledSessions->sum(fn (DeliverySession $s) => $s->netTotal());
        $daySusut = $dayDo - $dayBersih;
        $dayPct = $dayDo > 0 ? $daySusut / $dayDo * 100 : 0;
        $hasEmptySessions = $sessions->isNotEmpty() && $filledSessions->isEmpty();

        return view('weighing.summary', compact('weighings', 'totalNetKg', 'totalCount', 'suppliers', 'dayDo', 'dayBersih', 'daySusut', 'dayPct', 'hasEmptySessions'));
    }

    /**
     * Export file Excel (.xlsx) beneran: tabel per supplier + susut harian.
     */
    public function excel(): StreamedResponse
    {
        $weighings = Weighing::whereDate('weighed_at', today())->orderBy('weighed_at')->get();
        $suppliers = Supplier::withCount(['weighings' => fn ($q) => $q->whereDate('weighed_at', today())])->orderBy('name')->get();
        $sessions = DeliverySession::whereDate('delivered_at', today())->get();
        $filled = $sessions->filter(fn (DeliverySession $s) => $s->basketCount() > 0);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Harian');

        $sheet->fromArray(['Rekap Harian :', null, today()->format('d/m/Y')], null, 'A1', true);
        $sheet->fromArray(['Supplier', 'Keranjang', 'Total Netto (kg)', 'Rata-rata (kg)'], null, 'A2', true);

        $rowNum = 3;
        foreach ($suppliers->where('weighings_count', '>', 0) as $supplier) {
            $sw = $weighings->where('supplier_id', $supplier->id);
            $count = $sw->count();
            $total = (float) $sw->sum('net_kg');
            $sheet->fromArray([$supplier->name, $count, $total, $count > 0 ? $total / $count : 0], null, "A{$rowNum}", true);
            $rowNum++;
        }

        $sheet->fromArray(['GRAND TOTAL', $weighings->count(), (float) $weighings->sum('net_kg'), null], null, "A{$rowNum}", true);
        $rowNum += 2;

        $dayDo = (float) $filled->sum('do_weight');
        $dayBersih = (float) $filled->sum(fn (DeliverySession $s) => $s->netTotal());
        $daySusut = $dayDo - $dayBersih;

        $sheet->fromArray(['DO Harian', null, ':', $dayDo], null, "A{$rowNum}", true);
        $rowNum++;
        $sheet->fromArray(['Bersih Harian', null, ':', $dayBersih], null, "A{$rowNum}", true);
        $rowNum++;
        $sheet->fromArray(['Susut', null, ':', $daySusut, 'kg'], null, "A{$rowNum}", true);
        $rowNum++;
        $sheet->fromArray(['Persentase', null, null, $dayDo > 0 ? $daySusut / $dayDo : 0], null, "A{$rowNum}", true);
        $sheet->getStyle("E{$rowNum}")->getNumberFormat()->setFormatCode('0.00%');

        foreach (range(1, 5) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, 'rekap-harian-'.today()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Tampilan cetak rekap harian (disimpan user sebagai PDF via dialog print).
     */
    public function pdf(): View
    {
        $weighings = Weighing::whereDate('weighed_at', today())->with(['supplier'])->orderBy('weighed_at')->get();
        $suppliers = Supplier::withCount(['weighings' => fn ($q) => $q->whereDate('weighed_at', today())])->orderBy('name')->get();
        $sessions = DeliverySession::whereDate('delivered_at', today())->get();
        $filled = $sessions->filter(fn (DeliverySession $s) => $s->basketCount() > 0);

        return view('summary.pdf', [
            'weighings' => $weighings,
            'suppliers' => $suppliers,
            'dayDo' => (float) $filled->sum('do_weight'),
            'dayBersih' => (float) $filled->sum(fn (DeliverySession $s) => $s->netTotal()),
        ]);
    }

    /**
     * Export today's weighings to CSV format for Accurate.
     */
    public function exportCsv(): Response
    {
        $weighings = Weighing::whereDate('weighed_at', today())
            ->with(['supplier', 'basket', 'operator'])
            ->orderBy('weighed_at')
            ->get();

        $csv = "No Bukti,Tanggal,Jam,Supplier,Mobil,Bruto,Tara,Netto,Operator\n";

        foreach ($weighings as $w) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%.2f,%.2f,%.2f,%s\n",
                $w->id,
                $w->weighed_at->format('Y-m-d'),
                $w->weighed_at->format('H:i:s'),
                $w->supplier->name,
                $w->vehicle_no,
                $w->gross_avg,
                $w->tare_kg,
                $w->net_kg,
                $w->operator->name
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=weighings_'.today()->format('Y-m-d').'.csv',
        ]);
    }
}
