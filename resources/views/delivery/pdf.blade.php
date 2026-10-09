<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
        <title>Form Pengiriman Ayam Hidup - {{ $session->vehicle_no }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1e293b; padding: 16px 16px; }
        .kop { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #0f172a; padding-bottom: 10px; }
        .kop h1 { font-size: 20px; letter-spacing: 1px; color: #0f172a; }
        .kop h2 { font-size: 15px; letter-spacing: 1px; color: #334155; margin-top: 2px; }
        .kop p { font-size: 11px; color: #64748b; margin-top: 2px; font-family: 'Courier New', monospace; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; font-size: 12.5px; font-family: 'Courier New', monospace; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .head .left div { margin-bottom: 3px; }
        .head .right { text-align: left; }
        .head .right div { margin-bottom: 3px; }
        .head .lbl-l { display: inline-block; min-width: 118px; white-space: nowrap; }
        .head .lbl-r { display: inline-block; min-width: 208px; white-space: nowrap; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.grid th, table.grid td { border: 1px solid #94a3b8; padding: 3px 7px; font-size: 12px; }
        table.grid th { background: #f1f5f9; color: #334155; }
        td.no { text-align: center; width: 5%; color: #64748b; }
        td.kg { text-align: right; width: 11.6%; font-weight: bold; color: #0f172a; }
        tr.ttl td { font-weight: bold; background: #e2e8f0; color: #0f172a; }
        .foot { display: flex; margin-top: 14px; font-size: 12px; font-family: 'Courier New', monospace; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .foot .col { flex: 1; white-space: nowrap; }
        .foot .col div { margin-bottom: 4px; }
        .foot .lbl { display: inline-block; min-width: 185px; white-space: nowrap; }
        .foot .right { padding-left: 16px; }
        .c-ember { color: #047857; font-weight: bold; }
        .c-amber { color: #b45309; font-weight: bold; }
        .c-rose { color: #e11d48; font-weight: bold; }
        .c-navy { color: #0f172a; font-weight: bold; }
        .no-print { margin-bottom: 16px; display: flex; gap: 8px; }
        .no-print button, .no-print a { font-family: inherit; font-size: 13px; font-weight: bold; padding: 8px 18px; border-radius: 6px; border: 1px solid #999; background: #f1f5f9; color: #111; text-decoration: none; cursor: pointer; }
        .no-print button.primary { background: #1976D2; border-color: #1976D2; color: #fff; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="primary" onclick="window.print()">CETAK / SIMPAN PDF</button>
        <a href="{{ route('delivery.show', $session) }}">← Kembali</a>
    </div>

    <div class="kop">
        <h1>{{ strtoupper($settings->company_name ?? 'PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA') }}</h1>
        <h2>FORM PENGIRIMAN AYAM HIDUP (DHK)</h2>
        <p>{{ strtoupper($settings->company_address ?? '') }} • DHK ERP SYSTEM</p>
    </div>

    <div class="head">
        <div class="left">
            <div><span class="lbl-l">No DO/Faktur :</span><strong>{{ $session->deliveryOrder->number ?? '—' }}</strong></div>
            <div><span class="lbl-l">Do asal :</span></div>
            <div style="padding-left:16px;"><span class="lbl-l">Kg ayam :</span><strong>{{ number_format($session->do_weight, 2, '.', ',') }} Kg</strong></div>
            <div style="padding-left:16px;"><span class="lbl-l">total ekor :</span><strong>{{ number_format($session->total_ekor, 0, '.', ',') }} ekor</strong></div>
        </div>
        <div class="right">
            <div><span class="lbl-r">Tgl Prod :</span><strong>{{ $session->delivered_at->format('d/m/Y') }}</strong></div>
            <div><span class="lbl-r">No mobil / Nama supir :</span><strong>{{ $session->vehicle_no }} / {{ $session->driver_name ?: '—' }}</strong></div>
            <div><span class="lbl-r">Rata2 berat ayam setelah timbang :</span><strong>{{ number_format($session->avgBird(), 2, '.', ',') }} Kg/ekor</strong></div>
        </div>
    </div>

    @php
        $items = $weighings->values();
        $countItems = $items->count();
        // Sama kayak Excel: grid ngikutin data (1 kolom setinggi data bila
        // ≤25; blok 25 per kolom sampai 150; 6 kolom di atas itu).
        if ($countItems === 0) { $pairCount = 1; $perCol = 5; }
        elseif ($countItems <= 25) { $pairCount = 1; $perCol = $countItems; }
        elseif ($countItems <= 150) { $pairCount = (int) ceil($countItems / 25); $perCol = 25; }
        else { $pairCount = 6; $perCol = (int) ceil($countItems / 6); }
        $cols = [];
        for ($c = 0; $c < $pairCount; $c++) { $cols[] = $items->slice($c * $perCol, $perCol)->values(); }
        // Format US seperti form client: koma ribuan, titik desimal.
        $fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
        // TTL 2 desimal ala form client (683,34).
        $fmtTtl = fn ($v) => number_format((float) $v, 2, '.', ',');
        $colTotals = [];
        foreach ($cols as $col) { $colTotals[] = $col->sum('gross_avg'); }
    @endphp
    <table class="grid">
        <thead>
            <tr>
                @for ($c = 0; $c < $pairCount; $c++)
                    <th>No</th><th>Kg</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $perCol; $i++)
                <tr>
                    @foreach ($cols as $c => $col)
                        @php $w = $col->get($i); @endphp
                        <td class="no">{{ $w ? $c * $perCol + $i + 1 : '' }}</td>
                        <td class="kg">{{ $w ? $fmt($w->gross_avg) : '' }}</td>
                    @endforeach
                </tr>
            @endfor
            <tr class="ttl">
                <td class="no">TTL</td>
                <td class="kg">{{ $fmtTtl($colTotals[0]) }}</td>
                @for ($c = 1; $c < $pairCount; $c++)
                    <td class="no"></td>
                    <td class="kg">{{ $fmtTtl($colTotals[$c]) }}</td>
                @endfor
            </tr>
        </tbody>
    </table>
    <div class="foot">
        <div class="col">
            <div><span class="lbl">TIMBANG KOTOR :</span><span class="c-navy">{{ $fmt($session->grossTotal()) }} kg</span></div>
            <div><span class="lbl">POTONG KERANJANG:</span><span class="c-amber">{{ $fmt($session->basketDeduction()) }} kg ({{ $session->basketCount() }} × {{ number_format($session->tarePerBasket(), 1, '.', ',') }}kg)</span></div>
            <div><span class="lbl">POTONG AYAM MATI:</span><span class="c-rose">{{ $fmt($session->dead_kg) }} kg ({{ $session->dead_count }} ekor)</span></div>
            <div><span class="lbl">POTONG BASAH :</span><span class="c-amber">{{ $fmt($session->wet_kg) }} kg</span></div>
        </div>
        <div class="col right">
            <div><span class="lbl">DO ASAL :</span><span class="c-navy">{{ $fmt($session->do_weight) }} kg</span></div>
            <div><span class="lbl">SUSUT DO :</span><span class="c-amber">{{ $fmt($session->shrinkage()) }} kg</span></div>
            <div><span class="lbl">PERSENTASE SUSUT:</span><span class="c-rose">{{ $fmt($session->shrinkagePct()) }}%</span></div>
            <div><span class="lbl">TIMBANG BERSIH :</span><span class="c-ember">{{ $fmt($session->netTotal()) }} kg</span></div>
        </div>
    </div>

    <script>window.print();</script>
</body>
</html>
