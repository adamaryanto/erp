<!-- FORM MESIN ONLY -->
<div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm space-y-4">
    <div class="border-b border-gray-100 pb-2 mb-1">
        <h3 class="font-bold text-gray-800 text-sm uppercase">Form Timbang Mesin</h3>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Pos Timbang (Mesin)</label>
        <select id="stationSelect" x-model="selectedStation" @change="onStationChange(); saveSession()" :disabled="isSampling || autoLoop"
                class="w-full h-12 bg-white border border-gray-300 rounded-md px-3 text-gray-800 font-mono font-bold text-sm focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
            <template x-for="st in stations" :key="st"><option :value="st" x-text="st"></option></template>
        </select>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Sumber Angka (Mesin)</label>
        <div class="flex gap-2" :class="(isSampling || autoLoop) ? 'opacity-50 pointer-events-none' : ''">
            <button type="button" @click="setSource('mesin')" disabled
                    class="h-12 flex-1 rounded-md border bg-blue-600 text-white border-blue-600 text-sm uppercase tracking-wider transition">
                MESIN
            </button>
        </div>
        <p class="text-[11px] text-gray-400 mt-1">Angka dari kabel/USB timbangan (bridge reader.py).</p>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">No. DO / Faktur</label>
        <select x-model="selectedDo" @change="saveSession()" :disabled="isSampling || autoLoop"
                class="w-full h-12 bg-white border border-gray-300 rounded-md px-3 text-gray-800 font-mono font-bold text-sm focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
            <option value="">-- Pilih No. DO --</option>
            <template x-for="o in dbOrders" :key="o.id"><option :value="o.id" x-text="`${o.number} — ${Number(o.target_kg).toFixed(2)} kg`"></option></template>
        </select>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Nomor Mobil / Armada</label>
        <input type="text" x-model="noMobil" @change="saveSession()" :disabled="isSampling || autoLoop" placeholder="B 1234 XYZ"
               class="w-full h-12 bg-white border border-gray-300 rounded-md px-3 text-gray-800 font-mono font-bold text-base uppercase focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Kode Keranjang (Tare)</label>
        <select x-model="selectedKeranjang" @change="saveSession()" :disabled="isSampling || autoLoop"
                class="w-full h-12 bg-white border border-gray-300 rounded-md px-3 text-gray-800 font-medium text-sm focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
            <option value="">-- Pilih Keranjang --</option>
            <template x-for="k in keranjangList" :key="k.id"><option :value="k.id" x-text="k.label"></option></template>
        </select>
    </div>

    <div class="p-3 bg-gray-50 rounded border border-gray-200 text-xs flex justify-between font-mono">
        <span>Tare: <strong class="text-amber-600" x-text="selectedKeranjang ? activeTare.toFixed(2) + ' kg' : '—'"></strong></span>
        <span>Bruto Live: <strong class="text-blue-700" x-text="liveBruto.toFixed(2) + ' kg'"></strong></span>
    </div>

    <div class="pt-2">
        <button @click="startSampling()"
                :disabled="isSampling || !canWeigh || autoLoop"
                :class="isSampling ? 'bg-blue-700 opacity-90' : (!canWeigh || autoLoop) ? 'bg-slate-300 text-slate-500 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700 text-white shadow-md active:scale-[0.99]'"
                class="w-full h-16 rounded-md font-extrabold text-lg uppercase tracking-wider flex items-center justify-center gap-3 transition">
            <template x-if="!isSampling && canWeigh && !autoLoop">
                <div class="flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span x-text="'TIMBANG ' + manualDurationSec + ' DETIK'"></span>
                </div>
            </template>
            <template x-if="isSampling">
                <div class="flex items-center gap-2">
                    <svg class="w-6 h-6 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    <span>MENGUMPULKAN SAMPEL...</span>
                </div>
            </template>
            <template x-if="!canWeigh"><span>TIMBANGAN OFFLINE</span></template>
            <template x-if="canWeigh && autoLoop && !isSampling"><span>AUTO LOOP JALAN…</span></template>
        </button>
    </div>

    <div class="pt-2 border-t border-gray-100">
        <label class="flex items-center gap-2 mb-2 cursor-pointer select-none">
            <input type="checkbox" x-model="autoStart" @change="saveSession()" class="w-4 h-4 rounded">
            <span class="text-xs text-gray-600">Otomatis jalan saat konek (sesi diingat)</span>
        </label>
        <button @click="toggleAutoLoop()" type="button"
                :disabled="!canWeigh && !autoLoop"
                :class="autoLoop ? 'bg-rose-600 hover:bg-rose-700 text-white' : (!canWeigh ? 'bg-slate-200 text-slate-400 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 text-white')"
                class="w-full h-12 rounded-md font-extrabold text-sm uppercase tracking-wider transition flex items-center justify-center gap-2">
            <template x-if="autoLoop">
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1.5"></rect></svg>
                    STOP AUTO LOOP
                </span>
            </template>
            <template x-if="!autoLoop">
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>
                    <span x-text="'START AUTO LOOP (' + autoDurationSec + ' DETIK / KERANJANG)'"></span>
                </span>
            </template>
        </button>
        <div x-show="autoLoop" class="mt-2 p-3 bg-emerald-50 border border-emerald-200 rounded text-center space-y-1">
            <div class="font-mono text-2xl font-black text-emerald-700" x-text="'#' + autoCount"></div>
            <div class="text-[11px] font-mono text-gray-600">Total sesi: <strong x-text="autoTotalNetto.toFixed(2) + ' kg'"></strong></div>
            <div class="text-[11px] font-bold uppercase" :class="isSampling ? 'text-blue-700' : 'text-emerald-700'" x-text="autoStatusText"></div>
        </div>
        <p x-show="!autoLoop" class="mt-1.5 text-[11px] text-gray-400 text-center">Sekali klik → taruh keranjang → ke-record otomatis → angkat → taruh berikutnya.</p>
    </div>
</div>