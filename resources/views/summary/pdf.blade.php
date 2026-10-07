<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Harian - {{ today()->format('d-m-Y') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; padding: 24px; }
        .kop { text-align: center; border-bottom: 3px double #111; padding-bottom: 10px; margin-bottom: 12px; }
        .kop h1 { font-size: 20px; letter-spacing: 1px; }
        .kop h2 { font-size: 15px; margin-top: 4px; }
        .kop p { font-size: 11px; color: #444; margin-top: 2px; }
        .kpi { display: flex; gap: 12px; margin-bottom: 12px; }
        .kpi div { flex: 1; border: 1px solid #111; padding: 8px; text-align: center; }
        .kpi small { display: block; font-size: 10px; color: #555; }
        .kpi strong { font-size: 16px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data th, table.data td { border: 1px solid #111; padding: 3px 8px; font-size: 11px; }
        table.data th { background: #eee; }
        td.r { text-align: right; }
        .susut { border-top: 2px solid #111; padding-top: 10px; font-size: 12px; display: flex; flex-wrap: wrap; gap: 4px 32px; }
        .susut div { min-width: 45%; }
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
        <a href="{{ route('summary.index') }}">← Kembali</a>
    </div>

    <div class="kop">
        <h1>PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA</h1>
        <h2>REKAP PENIMBANGAN HARIAN</h2>
        <p>{{ today()->format('d/m/Y') }} • Sistem ERP Timbang Ayam Hidup</p>
    </div>

    @php
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $totalNet = $weighings->sum('net_kg');
        $susut = $dayDo - $dayBersih;
    @endphp
    <div class="kpi">
        <div><small>TOTAL NETTO</small><strong>{{ $fmt($totalNet) }} kg</strong></div>
        <div><small>TOTAL KERANJANG</small><strong>{{ $weighings->count() }}</strong></div>
        <div><small>SUSUT HARIAN</small><strong>{{ $fmt($susut) }} kg</strong></div>
    </div>

    <table class="data">
        <thead>
            <tr><th>Supplier</th><th>Keranjang</th><th>Total Netto (kg)</th><th>Rata-rata (kg)</th></tr>
        </thead>
        <tbody>
            @foreach($suppliers->where('weighings_count', '>', 0) as $supplier)
                @php
                    $sw = $weighings->where('supplier_id', $supplier->id);
                    $count = $sw->count();
                    $total = $sw->sum('net_kg');
                @endphp
                <tr>
                    <td>{{ $supplier->name }}</td>
                    <td class="r">{{ $count }}</td>
                    <td class="r">{{ $fmt($total) }}</td>
                    <td class="r">{{ $fmt($count > 0 ? $total / $count : 0) }}</td>
                </tr>
            @endforeach
            <tr>
                <td><strong>GRAND TOTAL</strong></td>
                <td class="r"><strong>{{ $weighings->count() }}</strong></td>
                <td class="r"><strong>{{ $fmt($totalNet) }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="susut">
        <div>DO HARIAN : <strong>{{ $fmt($dayDo) }}</strong></div>
        <div>BERSIH HARIAN : <strong>{{ $fmt($dayBersih) }}</strong></div>
        <div>SUSUT : <strong>{{ $fmt($susut) }}</strong></div>
        <div>PERSENTASE : <strong>{{ $fmt($dayDo > 0 ? $susut / $dayDo * 100 : 0) }}%</strong></div>
    </div>

    <div class="sign">
        <div>Admin Timbang<div class="line">( .................... )</div></div>
        <div>Mengetahui<div class="line">( .................... )</div></div>
    </div>

    <script>window.print();</script>
</body>
</html>
