<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form Pengiriman Ayam Hidup - {{ $session->vehicle_no }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; padding: 24px; }
        .kop { text-align: center; margin-bottom: 10px; }
        .kop h1 { font-size: 19px; letter-spacing: 1px; }
        .kop p { font-size: 12px; margin-top: 2px; }
        .kop .tel { font-size: 12px; margin-top: 2px; }
        .title { text-align: center; font-weight: bold; font-size: 14px; margin: 8px 0 2px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; font-size: 12px; }
        .head .left div { margin-bottom: 2px; }
        .head .right { text-align: right; font-weight: bold; white-space: nowrap; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.grid th, table.grid td { border: 1px solid #111; padding: 2px 6px; font-size: 11px; }
        table.grid th { background: #eee; }
        td.no { text-align: center; width: 5%; color: #333; }
        td.kg { text-align: right; width: 11.6%; font-weight: bold; }
        tr.ttl td { font-weight: bold; background: #fafafa; }
        .kotorbar { border: 1px solid #111; border-top: none; text-align: center; padding: 4px; font-size: 12px; }
        .foot { display: flex; margin-top: 10px; font-size: 12px; }
        .foot .col { flex: 1; }
        .foot .col div { margin-bottom: 3px; }
        .foot .right { text-align: left; padding-left: 48px; }
        .sign { display: flex; justify-content: space-between; margin-top: 48px; text-align: center; font-size: 12px; }
        .sign div { width: 30%; }
        .sign .line { margin-top: 56px; border-top: 1px solid #111; padding-top: 4px; }
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

    <div class="kop" style="display:flex;align-items:center;gap:16px;text-align:center;">
        @if(!empty($settings->company_logo))
            <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="" style="height:72px;flex:none;">
        @endif
        <div style="flex:1;">
        <h1>{{ strtoupper($settings->company_name ?? 'PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA') }}</h1>
        <p>{{ strtoupper($settings->company_address ?? '') }}</p>
        @if(!empty($settings->company_phone))
            <p class="tel">TELEPON : {{ $settings->company_phone }}</p>
        @endif
        </div>
    </div>

    <div class="title">FORM PENGIRIMAN AYAM HIDUP</div>
    <div class="head">
        <div class="left">
            <div>TANGGAL PROD : <strong>{{ $session->delivered_at->format('d/m/Y') }}</strong></div>
            <div>NAMA PENGIRIM : <strong>{{ $session->supplier->name }}</strong></div>
            <div>KG AYAM&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <strong>{{ number_format($session->do_weight, 2, '.', ',') }} / {{ number_format($session->total_ekor, 0, '.', ',') }}</strong></div>
        </div>
        <div class="right">RATA-RATA AYAM : {{ number_format($session->avgBird(), 2, '.', ',') }}</div>
    </div>

    @php
        $pairCount = 6;
        $items = $weighings->values();
        $perCol = max(1, (int) ceil(max($items->count(), 1) / $pairCount));
        $cols = [];
        for ($c = 0; $c < $pairCount; $c++) { $cols[] = $items->slice($c * $perCol, $perCol)->values(); }
        // Format US seperti form client: koma ribuan, titik desimal.
        $fmt = fn ($v) => number_format((float) $v, 2, '.', ',');
        // TTL 1 desimal ala form (905.8), tanpa nol ekor ("0" bukan "0.0").
        $fmtTtl = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ','), '0'), '.');
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
    <div class="kotorbar">Timbangan Kotor : {{ $fmt($session->grossTotal()) }}</div>

    <div class="foot">
        <div class="col">
            <div>POTONG KERANJANG : {{ $fmt($session->basketDeduction()) }} Kg</div>
            <div>POTONG AYAM MATI : {{ $fmt($session->dead_kg) }} Kg&nbsp;&nbsp;&nbsp;{{ $session->dead_count }} Ekor</div>
            <div>BASAH&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $fmt($session->wet_kg) }} Kg&nbsp;&nbsp;&nbsp;{{ $session->wet_count ?? 0 }} Ekor</div>
            <div>TIMBANG BERSIH&nbsp;&nbsp;&nbsp;: {{ $fmt($session->netTotal()) }} Kg</div>
            <div>DO ASAL&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $fmt($session->do_weight) }} Kg</div>
        </div>
        <div class="col right">
            <div>SUSUT&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $fmt($session->shrinkage()) }} Kg</div>
            <div>PERSENTASE : {{ $fmt($session->shrinkagePct()) }} %</div>
        </div>
    </div>

    <script>window.print();</script>
</body>
</html>
