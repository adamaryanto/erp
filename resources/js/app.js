// Vendored lokal (bukan CDN) — PC pos timbang sering offline / jsdelivr
// keblokir. Kalau Alpine mati, semua x-text kosong & @click mati total.
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
window.Chart = Chart;
Alpine.start();
