@extends('layouts.app')

@section('title', 'Master Data Supplier & Keranjang')

@section('content')
    <div class="mb-6">
        <div class="flex items-center gap-2 text-xs mb-2">
            <span class="text-gray-400">Accurate ERP</span>
            <span class="text-gray-300">/</span>
            <span class="text-gray-400">Data Master</span>
            <span class="text-gray-300">/</span>
            <span class="text-blue-700 font-bold">Supplier & Keranjang</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Master Data Supplier & Keranjang</h1>
        <p class="text-gray-600 text-sm mt-1">Daftar peternak plasma & referensi bobot tara keranjang. Refresh aman — halaman ini punya URL sendiri (<span class="font-mono">/master</span>).</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
        <!-- SUPPLIER PANEL -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-bold text-gray-800 text-sm uppercase">Supplier Plasma / Peternak</h2>
                <span class="text-xs font-mono text-gray-500">{{ $suppliers->count() }} data</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                            <th class="px-5 py-2.5">Nama</th>
                            <th class="px-3 py-2.5">HP</th>
                            <th class="px-3 py-2.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($suppliers as $s)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-5 py-2.5">
                                    <div class="font-semibold text-gray-800">{{ $s->name }}</div>
                                    @if($s->address)
                                        <div class="text-xs text-gray-500">{{ $s->address }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $s->phone ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    @if(!auth()->check() || auth()->user()->isAdmin())
                                        <button type="button" data-id="{{ $s->id }}" data-name="{{ $s->name }}" data-address="{{ $s->address }}" data-phone="{{ $s->phone }}" onclick="editSupplier(this)" class="text-blue-600 hover:text-blue-800 text-xs font-bold mr-2">UBAH</button>
                                        <form action="{{ route('master.suppliers.destroy', $s) }}" method="POST" class="inline" onsubmit="return confirm('Hapus supplier {{ $s->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-rose-600 hover:text-rose-800 text-xs font-bold">HAPUS</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">lihat saja</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-6 text-center text-gray-400 text-sm">Belum ada supplier</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(!auth()->check() || auth()->user()->isAdmin())
                <form id="supplier-form" action="{{ route('master.suppliers.store') }}" method="POST" class="px-5 py-4 bg-gray-50 border-t border-gray-100 space-y-2">
                    @csrf
                    <span id="supplier-method"></span>
                    <p id="supplier-form-title" class="text-xs font-bold text-gray-700 uppercase">+ Tambah Supplier</p>
                    <input type="text" name="name" id="supplier-name" required placeholder="Nama plasma / peternak" class="w-full h-11 px-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input type="text" name="address" id="supplier-address" placeholder="Alamat (opsional)" class="w-full h-11 px-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <div class="flex gap-2">
                        <input type="text" name="phone" id="supplier-phone" placeholder="No. HP (opsional)" class="flex-1 h-11 px-3 border border-gray-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <button class="h-11 px-5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-md">SIMPAN</button>
                        <button type="button" id="supplier-cancel" onclick="resetSupplierForm()" class="hidden h-11 px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-bold rounded-md">BATAL</button>
                    </div>
                </form>
            @endif
        </div>

        <!-- BASKET PANEL -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-bold text-gray-800 text-sm uppercase">Keranjang (Referensi Tara)</h2>
                <span class="text-xs font-mono text-gray-500">{{ $baskets->count() }} data</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                            <th class="px-5 py-2.5">Kode</th>
                            <th class="px-3 py-2.5">Ukuran</th>
                            <th class="px-3 py-2.5">Tare Default</th>
                            <th class="px-3 py-2.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($baskets as $b)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-5 py-2.5 font-mono font-bold text-gray-800">{{ $b->code }}</td>
                                <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200">{{ $b->size ?: '—' }}</span></td>
                                <td class="px-3 py-2.5 font-mono">{{ number_format($b->tare_default_kg, 2) }} kg</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    @if(!auth()->check() || auth()->user()->isAdmin())
                                        <button type="button" data-id="{{ $b->id }}" data-code="{{ $b->code }}" data-size="{{ $b->size }}" data-tare="{{ $b->tare_default_kg }}" onclick="editBasket(this)" class="text-blue-600 hover:text-blue-800 text-xs font-bold mr-2">UBAH</button>
                                        <form action="{{ route('master.baskets.destroy', $b) }}" method="POST" class="inline" onsubmit="return confirm('Hapus keranjang {{ $b->code }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-rose-600 hover:text-rose-800 text-xs font-bold">HAPUS</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">lihat saja</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-6 text-center text-gray-400 text-sm">Belum ada keranjang</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(!auth()->check() || auth()->user()->isAdmin())
                <form id="basket-form" action="{{ route('master.baskets.store') }}" method="POST" class="px-5 py-4 bg-gray-50 border-t border-gray-100 space-y-2">
                    @csrf
                    <span id="basket-method"></span>
                    <p id="basket-form-title" class="text-xs font-bold text-gray-700 uppercase">+ Tambah Keranjang</p>
                    <div class="flex gap-2">
                        <input type="text" name="code" id="basket-code" required placeholder="Kode (cth: B05)" class="flex-1 h-11 px-3 border border-gray-300 rounded-md text-sm font-mono uppercase focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <select name="size" id="basket-size" class="w-32 h-11 px-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Ukuran…</option>
                            <option value="Kecil">Kecil</option>
                            <option value="Sedang">Sedang</option>
                            <option value="Besar">Besar</option>
                        </select>
                        <input type="number" name="tare_default_kg" id="basket-tare" required step="0.05" min="1" max="10" placeholder="Tare kg" class="w-32 h-11 px-3 border border-gray-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <button class="h-11 px-5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-md">SIMPAN</button>
                        <button type="button" id="basket-cancel" onclick="resetBasketForm()" class="hidden h-11 px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-bold rounded-md">BATAL</button>
                    </div>
                    <p class="text-[11px] text-gray-400">Tare dipakai otomatis sebagai default di form timbang (bisa dioverride 2.0–3.5 kg).</p>
                </form>
            @endif
        </div>

        <!-- POS TIMBANG PANEL -->
        <div class="bg-white rounded-lg shadow overflow-hidden xl:col-span-2">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-bold text-gray-800 text-sm uppercase">Pos Timbang (Mesin)</h2>
                <span class="text-xs font-mono text-gray-500">{{ $stations->count() }} pos</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                            <th class="px-5 py-2.5">Kode</th>
                            <th class="px-3 py-2.5">Nama / Lokasi</th>
                            <th class="px-3 py-2.5">Tahap</th>
                            <th class="px-3 py-2.5">COM</th>
                            <th class="px-3 py-2.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($stations as $st)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-5 py-2.5 font-mono font-bold text-gray-800">{{ $st->code }}</td>
                                <td class="px-3 py-2.5">
                                    <div class="font-semibold text-gray-800">{{ $st->name }}</div>
                                    @if($st->location)
                                        <div class="text-xs text-gray-500">{{ $st->location }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5"><span class="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-700">{{ \App\Models\ScaleStation::STAGES[$st->stage] ?? $st->stage }}</span></td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $st->com_port ?: 'AUTO' }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    @if(!auth()->check() || auth()->user()->isAdmin())
                                        <button type="button" data-id="{{ $st->id }}" data-code="{{ $st->code }}" data-name="{{ $st->name }}" data-stage="{{ $st->stage }}" data-location="{{ $st->location }}" data-com="{{ $st->com_port }}" onclick="editStation(this)" class="text-blue-600 hover:text-blue-800 text-xs font-bold mr-2">UBAH</button>
                                        <form action="{{ route('master.stations.destroy', $st) }}" method="POST" class="inline" onsubmit="return confirm('Hapus pos {{ $st->code }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-rose-600 hover:text-rose-800 text-xs font-bold">HAPUS</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">lihat saja</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-6 text-center text-gray-400 text-sm">Belum ada pos</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(!auth()->check() || auth()->user()->isAdmin())
                <form id="station-form" action="{{ route('master.stations.store') }}" method="POST" class="px-5 py-4 bg-gray-50 border-t border-gray-100 space-y-2">
                    @csrf
                    <span id="station-method"></span>
                    <p id="station-form-title" class="text-xs font-bold text-gray-700 uppercase">+ Tambah Pos</p>
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
                        <input type="text" name="code" id="station-code" required placeholder="Kode (cth: POS-02)" class="h-11 px-3 border border-gray-300 rounded-md text-sm font-mono uppercase focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <input type="text" name="name" id="station-name" required placeholder="Nama pos" class="h-11 px-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <select name="stage" id="station-stage" required class="h-11 px-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            @foreach(\App\Models\ScaleStation::STAGES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="location" id="station-location" placeholder="Lokasi (opsional)" class="h-11 px-3 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <div class="flex gap-2">
                            <button class="h-11 px-5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-md">SIMPAN</button>
                            <button type="button" id="station-cancel" onclick="resetStationForm()" class="hidden h-11 px-4 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-bold rounded-md">BATAL</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400">Setelah tambah, jalankan bridge di PC pos itu dengan <span class="font-mono">--station KODE</span> — pos otomatis aktif.</p>
                </form>
            @endif
        </div>
    </div>

    <script>
        const supplierStoreUrl = "{{ route('master.suppliers.store') }}";
        const basketStoreUrl = "{{ route('master.baskets.store') }}";
        const stationStoreUrl = "{{ route('master.stations.store') }}";

        function editSupplier(btn) {
            const id = btn.dataset.id;
            const f = document.getElementById('supplier-form');
            f.action = `/master/suppliers/${id}`;
            document.getElementById('supplier-method').innerHTML = '@method("PUT")';
            document.getElementById('supplier-form-title').textContent = 'Ubah Supplier #' + id;
            document.getElementById('supplier-name').value = btn.dataset.name || '';
            document.getElementById('supplier-address').value = btn.dataset.address || '';
            document.getElementById('supplier-phone').value = btn.dataset.phone || '';
            document.getElementById('supplier-cancel').classList.remove('hidden');
            f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function resetSupplierForm() {
            const f = document.getElementById('supplier-form');
            f.action = supplierStoreUrl;
            document.getElementById('supplier-method').innerHTML = '';
            document.getElementById('supplier-form-title').textContent = '+ Tambah Supplier';
            f.reset();
            document.getElementById('supplier-cancel').classList.add('hidden');
        }
        function editBasket(btn) {
            const id = btn.dataset.id;
            const f = document.getElementById('basket-form');
            f.action = `/master/baskets/${id}`;
            document.getElementById('basket-method').innerHTML = '@method("PUT")';
            document.getElementById('basket-form-title').textContent = 'Ubah Keranjang #' + id;
            document.getElementById('basket-code').value = btn.dataset.code || '';
            document.getElementById('basket-size').value = btn.dataset.size || '';
            document.getElementById('basket-tare').value = btn.dataset.tare || '';
            document.getElementById('basket-cancel').classList.remove('hidden');
            f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function resetBasketForm() {
            const f = document.getElementById('basket-form');
            f.action = basketStoreUrl;
            document.getElementById('basket-method').innerHTML = '';
            document.getElementById('basket-form-title').textContent = '+ Tambah Keranjang';
            f.reset();
            document.getElementById('basket-cancel').classList.add('hidden');
        }
        function editStation(btn) {
            const id = btn.dataset.id;
            const f = document.getElementById('station-form');
            f.action = `/master/stations/${id}`;
            document.getElementById('station-method').innerHTML = '@method("PUT")';
            document.getElementById('station-form-title').textContent = 'Ubah Pos #' + id;
            document.getElementById('station-code').value = btn.dataset.code || '';
            document.getElementById('station-name').value = btn.dataset.name || '';
            document.getElementById('station-stage').value = btn.dataset.stage || 'hidup';
            document.getElementById('station-location').value = btn.dataset.location || '';
            document.getElementById('station-cancel').classList.remove('hidden');
            f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function resetStationForm() {
            const f = document.getElementById('station-form');
            f.action = stationStoreUrl;
            document.getElementById('station-method').innerHTML = '';
            document.getElementById('station-form-title').textContent = '+ Tambah Pos';
            f.reset();
            document.getElementById('station-cancel').classList.add('hidden');
        }
    </script>
@endsection
