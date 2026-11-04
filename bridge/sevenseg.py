#!/usr/bin/env python3
"""Decoder 7-segmen + Auto ROI struktur untuk ERP Timbang (2026-10-25).

Latar: OCR utama (EasyOCR) dilatih untuk huruf cetak, bukan 7-segmen.
Akibatnya ganti font display saja sudah bisa merusak dua hal sekaligus:
bacaan gagal, DAN auto ROI / AUTO-TRACK yang juga bergantung ke box teks
EasyOCR ikut tidak bisa menemukan display (ayam-telur). Modul ini memutus
ketergantungan itu dengan dua jalur berbasis STRUKTUR gambar, bukan bentuk
huruf:

1. Decoder 7-segmen: tiap digit dibagi 7 area segmen (atas, kanan-atas,
   kanan-bawah, bawah, kiri-bawah, kiri-atas, tengah). Yang diukur hanya
   NYALA atau MATI tiap area, bukan bentuk hurufnya. Jadi font 7-segmen
   apapun — tebal, tipis, runcing, rapat tanpa celah, hijau, merah —
   tetap kebaca selama memang 7-segmen. Segmen samar (ghost "888") ikut
   tertangani: nilainya jauh di bawah ambang nyala, jadi dihitung mati.
   Decoder TIDAK pernah menebak: satu segmen ambigu, satu digit tidak
   cocok peta, titik desimal tidak ketemu, atau nilai di luar 0-200 kg
   -> kembalikan None (tidak ada bacaan), bukan angka salah. Itu sengaja:
   tidak ada bacaan lebih aman daripada bacaan salah yang masuk timbangan.

2. Auto ROI struktur: cari display dari kontur area terang/gelap yang
   kontras (segmen menyala di latar gelap, atau digit gelap di layar
   terang), kelompokkan jadi digit, gabungkan digit sebaris jadi grup
   display. Jalan TANPA EasyOCR sama sekali, jadi bisa menemukan display
   yang fontnya tidak kebaca OCR. Dipakai sebagai fallback AUTO-TRACK
   (saat jalur EasyOCR gagal) dan mode kalibrasi --auto-roi-struct.

Batasan jujur: decoder ini hanya untuk font 7-segmen. Font cetak normal
tetap lewat EasyOCR; font dekoratif, tulisan tangan, dot-matrix, atau
14-segmen tetap bisa gagal. Syarat kamera tetap berlaku: digit cukup
besar di frame, kontras, tidak ketutup silau / terlalu miring.

Struktur file: bagian murni (tanpa cv2/numpy) bisa di-unit-test di mana
pun, termasuk VM tanpa opencv; bagian cv2 hanya dipakai di fungsi
decode_roi / detect_roi_struct dan anti-gagal (ImportError -> None).
"""

from __future__ import annotations

import math
import re

# ---------------------------------------------------------------------------
# Bagian murni: peta segmen, ukur segmen, kelompokkan box, rakit angka.
# grid = urutan baris, nilai = brightness 0-255 (boleh list Python maupun
# array numpy; akses grid[y][x] dikonversi float). polarity "bright" =
# digit terang di latar gelap; "dark" = digit gelap di latar terang.
# peak = acuan brightness (bright: persentil-99 crop; dark: latar terang).
# ---------------------------------------------------------------------------

_DIGIT_SEGMENTS = {
    "0": ("a", "b", "c", "d", "e", "f"),
    "1": ("b", "c"),
    "2": ("a", "b", "g", "e", "d"),
    "3": ("a", "b", "g", "c", "d"),
    "4": ("f", "g", "b", "c"),
    "5": ("a", "f", "g", "c", "d"),
    "6": ("a", "f", "g", "e", "c", "d"),
    "7": ("a", "b", "c"),
    "8": ("a", "b", "c", "d", "e", "f", "g"),
    "9": ("a", "b", "c", "d", "f", "g"),
}

SEGMENT_TO_DIGIT = {frozenset(v): k for k, v in _DIGIT_SEGMENTS.items()}

# Fraksi area tiap segmen dalam box digit (x0, y0, x1, y1), geometri v3
# sim: segmen bertemu persis di sambungan, tebal seragam. Area ini sengaja
# TIDAK tumpang tindih satu sama lain, jadi satu piksel hanya dihitung
# untuk satu segmen.
SEGMENT_REGIONS = {
    "a": (0.16, 0.00, 0.84, 0.09),
    "g": (0.16, 0.455, 0.84, 0.545),
    "d": (0.16, 0.91, 0.84, 1.00),
    "f": (0.00, 0.09, 0.16, 0.455),
    "b": (0.84, 0.09, 1.00, 0.455),
    "e": (0.00, 0.545, 0.16, 0.91),
    "c": (0.84, 0.545, 1.00, 0.91),
}

# Ambang skor nyala: >= ON = nyala, <= OFF = mati, di antaranya = ambigu.
# Skor = mean/peak (bright) atau 1-mean/peak (dark), 0-1. Segmen samar
# (ghost ~0.22) jatuh di bawah OFF, jadi dihitung mati — benar.
_SCORE_ON = 0.45
_SCORE_OFF = 0.30


def decode_digit_segments(on_set) -> str | None:
    """Himpunan segmen yang nyala -> digit, atau None bila tidak cocok."""
    return SEGMENT_TO_DIGIT.get(frozenset(on_set))


def _region_mean(grid, box, region) -> float:
    """Rata-rata brightness di satu area segmen (box = x0,y0,x1,y1)."""
    x0, y0, x1, y1 = (float(v) for v in box)
    w, h = x1 - x0, y1 - y0
    if w <= 0 or h <= 0:
        return 0.0
    rx0, ry0, rx1, ry1 = region
    px0, py0 = int(round(x0 + rx0 * w)), int(round(y0 + ry0 * h))
    px1, py1 = int(round(x0 + rx1 * w)), int(round(y0 + ry1 * h))
    # Jalur numpy (crop asli): mean slice jauh lebih cepat dari loop piksel.
    if hasattr(grid, "shape") and len(grid.shape) == 2:
        try:
            import numpy as _np  # noqa: F401 (hanya untuk cek tipe)
            _ = _np
            sl = grid[max(0, py0):max(py0 + 1, py1), max(0, px0):max(px1 + 1, px1)]
            if sl.size == 0:
                return 0.0
            return float(sl.mean())
        except Exception:
            pass
    # Jalur list murni (unit test): grid[y][x].
    total, n = 0.0, 0
    rows = len(grid)
    for yy in range(max(0, py0), min(rows, max(py0 + 1, py1))):
        row = grid[yy]
        cols = len(row)
        for xx in range(max(0, px0), min(cols, max(px0 + 1, px1))):
            total += float(row[xx])
            n += 1
    return total / n if n else 0.0


def measure_segment_scores(grid, box, polarity: str, peak: float) -> dict:
    """Skor nyala 0-1 tiap segmen dalam satu box digit."""
    if peak is None or peak <= 0:
        peak = 255.0
    scores = {}
    for name, region in SEGMENT_REGIONS.items():
        mean = _region_mean(grid, box, region)
        if polarity == "dark":
            scores[name] = max(0.0, min(1.0, 1.0 - mean / peak))
        else:
            scores[name] = max(0.0, min(1.0, mean / peak))
    return scores


def decode_digit_grid(grid, box, polarity: str, peak: float):
    """Baca satu digit dari grid -> (char|None, clarity 0-1, on_set, skor, alasan).

    clarity = jarak terkecil segmen dari ambang, dinormalkan 0-1. Segmen
    ambigu (skor di antara OFF dan ON) -> digit ditolak total.

    Kasus khusus: box sangat sempit (lebar < 35% tinggi) = digit "1"
    (satu bar vertikal, bukan slot 7-segmen penuh) — region segmen
    normal terlalu tipis untuk diukur (1-2 px, blur langsung merusak),
    jadi yang diukur hanya ada/tidaknya bar vertikal di tengah box.
    Fallback (v5): bila bar tengah redup (bukan "1"), box itu bisa
    jadi pecahan digit yang terpotong terlalu sempit oleh split
    (mis. "6" yang terpotong jadi w/h 0.28) — pecahan itu masih
    mengandung segmen 7-segmen lengkap, jadi ukur sebagai digit normal
    di bawah. "1" asli yang redup akan gagal juga di pengukuran normal
    (hanya segmen g yang nyala, bukan digit sah), jadi fallback ini
    tidak pernah menciptakan digit palsu.
    """
    _x0, _y0, _x1, _y1 = (float(v) for v in box)
    _w, _h = _x1 - _x0, _y1 - _y0
    if _h >= 12 and 0 < _w < 0.35 * _h:
        mean = _region_mean(grid, box, (0.30, 0.10, 0.70, 0.90))
        peak_eff = peak if peak and peak > 0 else 255.0
        s = (1.0 - mean / peak_eff) if polarity == "dark" else mean / peak_eff
        s = max(0.0, min(1.0, s))
        if s >= _SCORE_ON:
            return "1", max(0.0, min(1.0, (s - _SCORE_ON) / 0.30)), {"b", "c"}, \
                {"a": 0.0, "b": s, "c": s, "d": 0.0, "e": 0.0, "f": 0.0, "g": 0.0}, ""
        # Bukan "1" terang: jatuh ke pengukuran 7-segmen normal di bawah.
    scores = measure_segment_scores(grid, box, polarity, peak)
    on_set = set()
    min_margin = 1.0
    for name, s in scores.items():
        if s >= _SCORE_ON:
            on_set.add(name)
            min_margin = min(min_margin, s - _SCORE_ON)
        elif s <= _SCORE_OFF:
            min_margin = min(min_margin, _SCORE_OFF - s)
        else:
            return None, 0.0, on_set, scores, f"ambigu:{name}={s:.2f}"
    if not on_set:
        return None, 0.0, on_set, scores, "blank"
    ch = decode_digit_segments(on_set)
    if ch is None:
        return None, 0.0, on_set, scores, "no-match:" + "".join(sorted(on_set))
    clarity = max(0.0, min(1.0, min_margin / 0.30))
    return ch, clarity, on_set, scores, ""


def _median(values) -> float:
    vals = sorted(values)
    if not vals:
        return 0.0
    n = len(vals)
    return vals[n // 2] if n % 2 else (vals[n // 2 - 1] + vals[n // 2]) / 2.0


def classify_boxes(boxes):
    """Box kontur (satu box = satu digit/titik, hasil closing) ->
    (digit_boxes, dot_boxes), masing-masing list (x0,y0,x1,y1) urut x.

    Dot dikenali dari ukuran kecil + posisi bawah sejajar digit. Noise
    kecil yang bukan dot dibuang. Box di sini dict {x0,y0,x1,y1} atau tuple.
    """
    norm = []
    for b in boxes:
        if isinstance(b, dict):
            norm.append((float(b["x0"]), float(b["y0"]), float(b["x1"]), float(b["y1"])))
        else:
            norm.append(tuple(float(v) for v in b))
    norm = [b for b in norm if b[2] > b[0] and b[3] > b[1]]
    if not norm:
        return [], []
    heights = [b[3] - b[1] for b in norm]
    max_h = max(heights)
    digit_like = [b for b in norm if (b[3] - b[1]) >= 0.5 * max_h]
    if not digit_like:
        return [], []
    digit_h = _median([b[3] - b[1] for b in digit_like])
    digit_y0 = _median([b[1] for b in digit_like])
    digit_y1 = _median([b[3] for b in digit_like])
    digits, dots = [], []
    for b in norm:
        h, w = b[3] - b[1], b[2] - b[0]
        if h >= 0.5 * max_h:
            digits.append(b)
        elif (h < 0.4 * digit_h and w < 0.5 * digit_h
                and b[1] >= digit_y0 + 0.4 * digit_h
                and abs(b[3] - digit_y1) <= 0.3 * digit_h):
            # Titik desimal: kecil, di paruh bawah, DAN sejajar bawah digit.
            # Syarat sejajar ini penting: noise kecil jauh di bawah baris
            # digit (mis. tombol) tidak boleh dikira titik.
            dots.append(b)
        # Selain itu: noise, buang.
    digits.sort(key=lambda b: b[0])
    dots.sort(key=lambda b: b[0])
    return digits, dots


def group_segment_boxes(boxes):
    """Box segmen terpisah (tanpa closing) -> (digit_boxes, dot_boxes).

    Segmen satu digit digabung dengan dua aturan (union-find), bukan
    sekadar perluasan box — perluasan saja gagal di dua ujung: digit "1"
    yang segmen atas-bawahnya berjarak (tidak ada segmen tengah) akan
    pecah, sementara titik desimal yang dekat digit tetangga akan
    tertelan. Aturannya: (1) satu kolom: tumpang tindih horizontal
    >=50% lebar terkecil DAN celah vertikal <=30% tinggi terbesar
    (ini yang menyatukan b-c pada digit "1"); (2) bersentuhan: celah
    horizontal DAN vertikal masing-masing <=10% tinggi terbesar
    (ini yang menyatukan segmen yang bertemu di sudut, mis. a-f).
    Titik desimal TIDAK digabung sama sekali (dikecualikan eksplisit):
    di frame kecil titik hanya berjarak 1-2 px dari digit tetangga,
    jadi aturan "bersentuhan" akan menelannya. Kalau grouping salah,
    decode di bawah tetap menolak (bukan menebak).
    """
    norm = []
    for b in boxes:
        if isinstance(b, dict):
            norm.append((float(b["x0"]), float(b["y0"]), float(b["x1"]), float(b["y1"])))
        else:
            norm.append(tuple(float(v) for v in b))
    norm = [b for b in norm if b[2] > b[0] and b[3] > b[1]]
    if not norm:
        return [], []
    parent = list(range(len(norm)))

    def find(i):
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    def union(i, j):
        ri, rj = find(i), find(j)
        if ri != rj:
            parent[ri] = rj

    def _gap_and_overlap(a, b):
        x_gap = max(0.0, max(a[0], b[0]) - min(a[2], b[2]))
        y_gap = max(0.0, max(a[1], b[1]) - min(a[3], b[3]))
        x_ov = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
        return x_gap, y_gap, x_ov

    # Titik desimal TIDAK BOLEH ikut digabung (2026-10-25, dari frame
    # nyata 21.83): di frame kecil, titik hanya berjarak 1-2 px dari
    # digit tetangga, jadi aturan "bersentuhan" menggabungkan
    # "1"+titik+"8" jadi satu box lebar — decode gagal. Titik dikenali
    # sebagai komponen kecil + kira-kira bujur sangkar (segmen bukan
    # bujur sangkar: horizontal itu lebar, vertikal itu tinggi, jadi
    # cek aspek ini aman walau ambang ukurannya longgar). Komponen
    # titik dibiarkan jadi klaster sendiri; classify_boxes belakangan
    # yang memutuskan ia titik (sejajar bawah digit) atau noise.
    _max_comp_h = max(b[3] - b[1] for b in norm)
    _dot_thresh = 0.30 * (_max_comp_h / 0.365 if _max_comp_h > 0 else 0)

    def _is_dot_like(b):
        h, w = b[3] - b[1], b[2] - b[0]
        if h <= 0 or w <= 0:
            return False
        return h <= _dot_thresh and w <= _dot_thresh and 0.5 <= w / h <= 2.0

    for i in range(len(norm)):
        for j in range(i + 1, len(norm)):
            a, b = norm[i], norm[j]
            if _is_dot_like(a) or _is_dot_like(b):
                continue
            x_gap, y_gap, x_ov = _gap_and_overlap(a, b)
            max_h = max(a[3] - a[1], b[3] - b[1])
            min_w = min(a[2] - a[0], b[2] - b[0])
            column = x_ov >= 0.5 * min_w and y_gap <= 0.30 * max_h
            touching = x_gap <= 0.10 * max_h and y_gap <= 0.10 * max_h
            if column or touching:
                union(i, j)
    clusters = {}
    for i, b in enumerate(norm):
        clusters.setdefault(find(i), []).append(b)
    unions = []
    for members in clusters.values():
        unions.append((min(m[0] for m in members), min(m[1] for m in members),
                       max(m[2] for m in members), max(m[3] for m in members)))
    return classify_boxes([{"x0": u[0], "y0": u[1], "x1": u[2], "y1": u[3]} for u in unions])


def decode_grid_value(grid, digit_boxes, dot_boxes, polarity: str, peak: float):
    """Rakit angka dari slot digit + titik -> (nilai|None, conf, raw, detail).

    Aturan tolak (bukan tebak): digit ambigu/tidak cocok, titik tidak
    tepat satu (kecuali angka nol tunggal), digit tunggal bukan nol,
    atau nilai di luar 0-200 kg.
    """
    if not digit_boxes:
        return None, 0.0, "", "tidak ada digit"
    digits = []
    min_clarity = 1.0
    seg_parts = []
    for box in digit_boxes:
        ch, clarity, on_set, _scores, reason = decode_digit_grid(grid, box, polarity, peak)
        if ch is None:
            return None, 0.0, "", f"digit gagal ({reason})"
        digits.append(ch)
        min_clarity = min(min_clarity, clarity)
        seg_parts.append(f"{ch}:{''.join(sorted(on_set))}")
    if len(digits) == 1 and digits[0] != "0":
        return None, 0.0, "", "satu digit bukan nol (bisa serpihan)"
    items = [(b[0], "d", ch) for b, ch in zip(digit_boxes, digits)]
    items += [(b[0], "dot", ".") for b in dot_boxes]
    items.sort(key=lambda t: t[0])
    raw = "".join(t[2] for t in items)
    if len(digits) >= 2 and raw.count(".") != 1:
        return None, 0.0, "", "titik desimal tidak tepat satu"
    try:
        val = float(raw)
    except ValueError:
        return None, 0.0, "", f"rakit gagal: {raw!r}"
    if not (0.0 <= val < 200.0):
        return None, 0.0, "", f"di luar jangkauan: {val}"
    conf = 0.5 + 0.45 * min_clarity
    return val, conf, raw, "7seg:" + ",".join(seg_parts)


# ---------------------------------------------------------------------------
# Bagian cv2: crop grayscale, decode satu ROI, cari ROI dari struktur.
# Semua fungsi di sini mengembalikan None/alasan bila cv2 tidak ada atau
# gambar gagal — pemanggil (daemon) tidak pernah mati karena modul ini.
# ---------------------------------------------------------------------------

__version__ = "2026-10-25-v5-split"


def _crop_gray_color(img_bytes: bytes, roi, angle: float = 0.0):
    """Crop ROI dari frame JPEG -> (gray, color, bgr, H, W) atau None.

    Tanpa threshold, tanpa ubah polaritas: decoder yang menilai nyala/mati
    dari brightness asli (biar segmen samar tetap bisa dibedakan dari
    segmen terang). color = max-min antar channel (0-255): digit LED
    berwarna (hijau/merah) nilainya tinggi, latar abu-abu/putih/hitam
    nilainya ~0 — kanal ini yang menyelamatkan kasus digit berwarna di
    atas latar terang, di mana brightness saja tidak bisa memisahkan
    digit dari latar (latar justru lebih terang dari digit). bgr ikut
    dikembalikan untuk skor per-kanal (G/R/B) di decode_roi.
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None
    arr = np.frombuffer(img_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return None
    H, W = img.shape[:2]
    x, y, w, h = roi
    x0, y0 = int(x * W), int(y * H)
    x1, y1 = int((x + w) * W), int((y + h) * H)
    crop = img[y0:y1, x0:x1]
    if crop.size == 0:
        return None
    if abs(angle) >= 1.5:
        ch, cw = crop.shape[:2]
        M = cv2.getRotationMatrix2D((cw / 2, ch / 2), angle, 1.0)
        crop = cv2.warpAffine(crop, M, (cw, ch), flags=cv2.INTER_CUBIC,
                              borderMode=cv2.BORDER_REPLICATE)
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    ci = crop.astype(int)
    color = (ci.max(axis=2) - ci.min(axis=2)).astype(float)
    return gray, color, crop, H, W


def _border_mean(arr) -> float:
    """Rata-rata border array 2D = level latar (dipakai semua kanal)."""
    try:
        import numpy as np
        border = np.concatenate([arr[0, :], arr[-1, :], arr[:, 0], arr[:, -1]])
        return float(border.mean()) if border.size else 0.0
    except Exception:
        return 0.0


def _polarity_peak(gray):
    """Dari crop grayscale -> (polarity, peak, bg). Border = latar display:
    gelap -> digit terang; terang -> digit gelap. Peak = persentil-99,
    bg = rata-rata border. bg ini kuncinya: skor segmen dihitung RELATIF
    latar (nilai-bg)/(peak-bg), bukan nilai/peak — karena latar monitor
    nyata bukan hitam pekat melainkan abu-abu kebiruan (~110-150), dan
    skor nilai/peak membuat segmen mati ikut terangkat ke zona ambigu
    (0.30-0.45) sehingga decoder menolak digit yang sebenarnya jelas."""
    try:
        import numpy as np
        border_mean = _border_mean(gray)
        peak = float(np.percentile(gray, 99)) if gray.size else 0.0
    except Exception:
        return "bright", 255.0, 0.0
    return ("dark", peak, border_mean) if border_mean >= 127 else ("bright", peak, border_mean)


def _boxes_from_mask(mask):
    """Mask biner (digit putih) -> list box dict, buang noise kecil."""
    try:
        import cv2
    except ImportError:
        return []
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    boxes = []
    for c in contours:
        x, y, w, h = cv2.boundingRect(c)
        area = cv2.contourArea(c)
        # Ambang sengaja longgar (titik desimal di frame downscale bisa
        # setinggi 4 px): noise kecil dibuang belakangan oleh
        # classify/grouping (titik harus sejajar bawah digit, digit harus
        # setinggi >=0.5 tinggi terbesar), bukan di sini.
        if area >= 5 and h >= 4 and w >= 3:
            boxes.append({"x0": float(x), "y0": float(y), "x1": float(x + w), "y1": float(y + h)})
    return boxes


def split_wide_boxes(digit_boxes, mask_bool, valley_frac=0.20):
    """Pecah box digit yang terlalu lebar (digit menyatu karena glow /
    celah antar digit rapat) jadi beberapa box, lewat lembah kolom mask.

    Dipakai HANYA sebagai rescue setelah decode tanpa split gagal, jadi
    font normal (box w/h <= 0.75) tidak pernah tersentuh. Box lebar
    (w > 0.75*h; digit tunggal normal w/h ~0.55-0.65, gabungan 2 digit
    w/h ~0.9+) dicari lembahnya: kolom-kolom dengan isi mask < 20%
    tinggi box, lebar >= 2 kolom, dengan puncak >= 50% tinggi di kedua
    sisi. Ambang 20% (bukan 30%) sengaja: kolom lubang tengah "0"/"6"
    isinya ~27-28% (bar atas+bawah), jadi di 30% digit itu ikut
    terbelah, sedangkan celah antar digit asli (latar+glow saja,
    ~0-13%) tetap di bawah 20% dan tetap terpecah. Kalaupun sebuah
    digit terbelah, validasi decode (try_split_decode) akan membuangnya
    karena pecahannya bukan digit sah. mask_bool: array bool 2D dalam
    koordinat yang sama dengan box. valley_frac: ambang lembah
    (default 0.20); try_split_decode mencoba 0.20 dan 0.30 per box
    (v5, karena pasangan digit yang berbeda bisa butuh ambang berbeda
    dalam satu gambar yang sama). Mengembalikan list baru bila ada
    yang terpecah, atau list asal bila tidak ada.
    """
    try:
        import numpy as np
    except ImportError:
        return digit_boxes
    if mask_bool is None or len(digit_boxes) == 0:
        return digit_boxes
    try:
        mh, mw = mask_bool.shape[:2]
    except Exception:
        return digit_boxes
    out = []
    changed = False
    for b in digit_boxes:
        x0, y0, x1, y1 = (float(v) for v in b)
        w, h = x1 - x0, y1 - y0
        if h <= 0 or w <= 0.75 * h:
            out.append(b)
            continue
        ix0, ix1 = max(0, int(round(x0))), min(mw, int(round(x1)))
        iy0, iy1 = max(0, int(round(y0))), min(mh, int(round(y1)))
        if ix1 - ix0 < 4 or iy1 - iy0 < 4:
            out.append(b)
            continue
        try:
            colsum = mask_bool[iy0:iy1, ix0:ix1].sum(axis=0)
        except Exception:
            out.append(b)
            continue
        low = colsum < valley_frac * h
        valleys = []
        i, n = 0, len(low)
        while i < n:
            if low[i]:
                j = i
                while j < n and low[j]:
                    j += 1
                if j - i >= 2:
                    valleys.append((i, j))
                i = j
            else:
                i += 1
        if not valleys:
            out.append(b)
            continue
        cuts = []
        for (a, z) in valleys:
            left_peak = colsum[:a].max() if a > 0 else 0
            right_peak = colsum[z:].max() if z < n else 0
            # Lembah yang sah = celah ANTAR digit, bukan lubang TENGAH
            # digit: celah itu SEMPIT (<= max(4, 10% lebar box)) dan
            # diapit puncak di kedua sisi. Ambang puncak 0.25*h (bukan
            # 0.50*h): tepi digit seperti "2" tidak punya bar vertikal
            # penuh (tanpa segmen c bawah), jadi puncaknya hanya ~30%
            # tinggi — ambang 0.50 menolak celah yang benar. Lubang
            # tengah "0"/"6" (lebar 8-30 kolom) ditolak oleh filter
            # lebar, bukan oleh puncak, sehingga celah sempit tetap lolos
            # meski puncaknya tidak setinggi bar penuh.
            if (left_peak >= 0.25 * h and right_peak >= 0.25 * h
                    and (z - a) <= max(4, 0.10 * w)):
                # Potong di TENGAH lembah, bukan di kolom minimum:
                # minimum bisa di tepi lembah (dekat digit kanan),
                # sehingga box kiri ikut menelan piksel digit kanan
                # dan segmennya terkontaminasi (kasus "2"+"6" di 26.50:
                # potong di 59 membuat c "2" ikut nyala 0.54).
                cuts.append((a + z - 1) // 2)
        if not cuts:
            out.append(b)
            continue
        parts = []
        prev = 0
        for c in cuts:
            parts.append((prev, c))
            prev = c + 1
        parts.append((prev, n))
        made = []
        for (pa, pz) in parts:
            if pz - pa < 3:
                continue
            # Batas box dari bounding box mask per pecahan (bukan
            # diwarisi parent, bukan kolom padat): digit sebaris di foto
            # miring TIDAK sama tinggi (kasus 26.50: "2" 53 px vs "6"
            # 76 px — bukan artefak mask, tapi geometri nyata di foto),
            # sehingga warisan y parent menggeser semua region segmen
            # "2" (g/d/e ikut mati). Kolom padat juga terbukti memotong
            # bar tepi digit yang tipis (kasus "5": f ikut hilang).
            # Potongan di tengah celah sudah cukup mencegah piksel
            # tetangga masuk; bounding box mask per pecahan adalah
            # yang paling setia pada piksel digit itu sendiri.
            sub = mask_bool[iy0:iy1, ix0 + pa:ix0 + pz]
            ys, xs = np.where(sub)
            if len(xs) == 0:
                continue
            sx0 = ix0 + pa + int(xs.min())
            sx1 = ix0 + pa + int(xs.max()) + 1
            sy0 = iy0 + int(ys.min())
            sy1 = iy0 + int(ys.max()) + 1
            if sy1 - sy0 >= 4 and sx1 - sx0 >= 3:
                made.append((float(sx0), float(sy0), float(sx1), float(sy1)))
        if len(made) >= 2:
            out.extend(made)
            changed = True
        else:
            out.append(b)
    return out if changed else digit_boxes


def split_one_wide_box(box, mask_bool, valley_frac=0.20):
    """Pecah SATU box (lihat split_wide_boxes) -> list sub-box atau [box]."""
    return split_wide_boxes([box], mask_bool, valley_frac)


def try_split_decode(score, base_digits, base_dots, mask_bool):
    """Coba decode dengan digit hasil split, termasuk kombinasi ambang
    per-box (v5).

    Mengembalikan (val, cf, raw, detail) bila decode berhasil, atau None.
    Satu ambang tidak cukup: di satu gambar yang sama, pasangan digit
    yang berbeda bisa butuh ambang berbeda (celah "2"+"6" terisi glow
    ~20-30% sehingga butuh ambang 30% untuk terpecah, sementara lubang
    tengah "0" isinya ~27% sehingga di ambang 30% ikut terbelah).
    Karena itu tiap box lebar dicoba dengan varian [tidak dipecah,
    pecah@20%, pecah@30%] dan semua kombinasinya dicoba (dibatasi 64
    kombinasi). Pengaman sama seperti sebelumnya: semua sub-box
    setinggi digit (>= 0.5 tinggi maksimum) harus selamat sebagai digit
    setelah klasifikasi ulang, dan decode harus menghasilkan digit sah
    + titik tepat satu — jadi kombinasi yang salah gugur sendiri,
    tidak pernah jadi bacaan palsu.
    """
    try:
        import itertools
    except Exception:
        return None
    if mask_bool is None or not base_digits:
        return None
    try:
        wide_idx = []
        fixed = []
        for i, b in enumerate(base_digits):
            x0, y0, x1, y1 = (float(v) for v in b)
            if (y1 - y0) > 0 and (x1 - x0) > 0.75 * (y1 - y0):
                wide_idx.append(i)
            else:
                fixed.append(b)
        if not wide_idx:
            return None
        variants = []
        for i in wide_idx:
            b = base_digits[i]
            opts = []
            seen = set()
            for cand in ([b],
                         split_one_wide_box(b, mask_bool, 0.20),
                         split_one_wide_box(b, mask_bool, 0.30)):
                key = tuple(cand)
                if key not in seen:
                    seen.add(key)
                    opts.append(cand)
            variants.append(opts)
        combos = list(itertools.product(*variants))
        if len(combos) > 64:
            combos = combos[:64]
        base_tuple = tuple(base_digits)
        for combo in combos:
            combined = list(fixed)
            for parts in combo:
                combined.extend(parts)
            combined_sorted = sorted(combined, key=lambda d: float(d[0]))
            if tuple(combined_sorted) == base_tuple:
                continue
            try:
                nd, ndt = classify_boxes(list(combined_sorted) + list(base_dots))
            except Exception:
                continue
            try:
                max_h = max(float(d[3]) - float(d[1]) for d in combined_sorted)
                digit_sized = [d for d in combined_sorted if (float(d[3]) - float(d[1])) >= 0.5 * max_h]
                if len(nd) != len(digit_sized):
                    continue
                val, cf, raw, detail = decode_grid_value(score, nd, ndt, "bright", 1.0)
                if val is not None:
                    return val, cf, raw, detail
            except Exception:
                continue
    except Exception:
        return None
    return None


def main_digit_row(digit_boxes, dots):
    """Pilih baris digit utama, buang baris lain (v5).

    ROI yang mencakup tulisan "kg" di bawah digit membuat "kg" ikut
    terhitung sebagai digit dan merusak decode. Baris utama = baris
    dengan box terbanyak (seri -> median tinggi terbesar, lewat
    _row_groups: sebaris bila overlap vertikal >=40% dan gap wajar).
    Titik yang tidak sejajar bawah baris utama atau di luar rentang-x
    baris ikut dibuang. Bila hanya satu baris, kembalikan as-is.
    Mengembalikan (row_digits, row_dots).
    """
    if not digit_boxes:
        return digit_boxes, dots
    try:
        groups = _row_groups(digit_boxes)
    except Exception:
        return digit_boxes, dots
    if len(groups) <= 1:
        return digit_boxes, dots
    try:
        def row_key(g):
            hs = sorted(float(b[3]) - float(b[1]) for b in g)
            med_h = hs[len(hs) // 2] if hs else 0
            return (len(g), med_h)
        best = max(groups, key=row_key)
        best_ids = set(id(b) for b in best)
        row_digits = [b for b in digit_boxes if id(b) in best_ids]
        if not row_digits:
            return digit_boxes, dots
        hs = sorted(float(b[3]) - float(b[1]) for b in row_digits)
        digit_h = hs[len(hs) // 2]
        y1s = sorted(float(b[3]) for b in row_digits)
        row_y1 = y1s[len(y1s) // 2]
        rx0 = min(float(b[0]) for b in row_digits)
        rx1 = max(float(b[2]) for b in row_digits)
        row_dots = [d for d in dots
                    if abs(float(d[3]) - row_y1) <= 0.3 * digit_h
                    and float(d[0]) >= rx0 - digit_h
                    and float(d[2]) <= rx1 + digit_h]
        return row_digits, row_dots
    except Exception:
        return digit_boxes, dots


def decode_roi(img_bytes: bytes, roi, angle: float = 0.0):
    """Baca angka 7-segmen dari satu ROI -> (nilai|None, conf, raw, detail).

    Semua skor dihitung RELATIF latar: (nilai-bg)/(peak-bg), bg =
    rata-rata border crop. Ini yang membedakan v3 dari v2 (nilai/peak):
    latar monitor nyata itu abu-abu kebiruan (~110-150), bukan hitam
    pekat, sehingga skor nilai/peak mengangkat segmen mati ke zona
    ambigu dan membuat mask kehilangan segmen atas (yang paling redup
    karena gradien layar) — box digit bergeser, semua region segmen
    ikut salah, decoder menolak. Dengan skor relatif latar, segmen
    mati = 0, segmen nyala = 0.6-1.0, latar gradien tetap < 0.35.

    Percobaan kanal (berurutan, yang pertama berhasil dipakai): gray
    sesuai polaritas, WARNA (saturasi), lalu per-kanal G, R, B —
    kanal warna tunggal penting karena digit hijau di atas latar
    biru-keabu-abuan nyaris tidak punya kontras grayscale (digit
    ~138 vs latar ~127), tapi kontras kanal hijaunya besar
    (~200 vs ~130). Mask 0.35 (bukan 0.5): segmen atas yang redup
    (~0.44) harus masuk mask, sementara latar gradien (~0.22) dan
    ghost (~0.29) tetap di bawahnya. Tiap kanal dicoba dengan dua
    strategi slot: closing 5x5, lalu tanpa closing + grouping segmen.
    Rescue split (v4): kalau decode gagal dan ada box digit yang terlalu
    lebar (digit menyatu karena glow / celah antar digit rapat, seperti
    font baru di screenshot 26.50 — box 76/62 px padahal digit tunggal
    ~30 px), box itu dipecah di lembah kolom mask lalu decode diulang.
    Pecahan yang salah (mis. "0" terbelah) otomatis gugur karena
    pecahannya bukan digit sah, jadi split tidak pernah menciptakan
    bacaan palsu — hanya membuka jalan buat bacaan yang benar.
    v5: split mencoba kombinasi ambang per-box (tiap box lebar dicoba
    tidak dipecah / pecah@20% / pecah@30%, karena pasangan digit yang
    berbeda bisa butuh ambang berbeda dalam satu gambar yang sama),
    dan decode hanya memakai baris digit utama (baris lain seperti
    "kg" di bawah digit dibuang, supaya tidak ikut terhitung digit).
    """
    got = _crop_gray_color(img_bytes, roi, angle)
    if got is None:
        return None, 0.0, "", "crop gagal (opencv hilang?)"
    gray, color, bgr, _H, _W = got
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None, 0.0, "", "opencv belum diinstall"
    polarity, peak, _bg = _polarity_peak(gray)
    if peak < 30:
        return None, 0.0, "", "crop terlalu gelap"

    def bg_score(arr):
        """Skor relatif latar, atau None bila kontras kanal < 30."""
        try:
            bg_a = _border_mean(arr)
            peak_a = float(np.percentile(arr, 99)) if arr.size else 0.0
            if peak_a - bg_a < 30:
                return None
            return np.clip((arr.astype(float) - bg_a) / max(peak_a - bg_a, 1.0), 0.0, 1.0)
        except Exception:
            return None

    def dark_score(arr):
        """Skor digit gelap di latar terang: 1 - nilai/bg_border."""
        try:
            bg_a = _border_mean(arr)
            if bg_a < 30:
                return None
            return np.clip(1.0 - arr.astype(float) / max(bg_a, 1.0), 0.0, 1.0)
        except Exception:
            return None

    scores_to_try = []  # (nama, skor, ambang_mask)
    if polarity == "dark":
        s = dark_score(gray)
        if s is not None:
            scores_to_try.append(("gray-dark", s, 0.35))
    else:
        s = bg_score(gray)
        if s is not None:
            scores_to_try.append(("gray", s, 0.35))
        else:
            scores_to_try.append(("gray-peak", np.clip(gray.astype(float) / max(peak, 1.0), 0.0, 1.0), 0.5))
    s = bg_score(color)
    if s is not None:
        scores_to_try.append(("color", s, 0.35))
    for ch_name, ch_idx in (("G", 1), ("R", 2), ("B", 0)):
        try:
            s = bg_score(bgr[:, :, ch_idx].astype(float))
            if s is not None:
                scores_to_try.append((ch_name, s, 0.35))
        except Exception:
            pass
    if not scores_to_try:
        scores_to_try.append(("gray-peak-dark", np.clip(1.0 - gray.astype(float) / max(peak, 1.0), 0.0, 1.0), 0.5))
    last_detail = "tidak ada kanal"
    last_detail = "tidak ada kanal"
    for _name, score, thresh in scores_to_try:
        mask = (score >= thresh).astype(np.uint8) * 255
        mask_bool = mask > 0
        # Dua sumber slot: closing (segmen menyatu, tapi titik bisa ikut
        # tertelan + digit bisa menyatu) dan grouping (titik selamat,
        # tapi segmen runcing tidak menggumpal). Kandidat decode mencoba
        # kombinasi keduanya, karena satu jalur saja sering hanya punya
        # separuh: digit dari closing+split, titik dari grouping.
        try:
            closed = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
            c_digits, c_dots = classify_boxes(_boxes_from_mask(closed))
        except Exception:
            c_digits, c_dots = [], []
        try:
            g_digits, g_dots = group_segment_boxes(_boxes_from_mask(mask))
        except Exception:
            g_digits, g_dots = [], []
        # Titik gabungan (dedup berdasarkan posisi, biar tidak terhitung dua).
        merged_dots = list(c_dots)
        for d in g_dots:
            if not any(abs(d[0] - m[0]) <= 5 and abs(d[3] - m[3]) <= 5 for m in merged_dots):
                merged_dots.append(d)
        # Filter baris utama (v5): buang baris lain seperti "kg" di bawah
        # digit, sebelum decode maupun split.
        c_digits, c_dots = main_digit_row(c_digits, c_dots)
        g_digits, g_dots = main_digit_row(g_digits, g_dots)
        _, merged_c = main_digit_row(c_digits, merged_dots)
        _, merged_g = main_digit_row(g_digits, merged_dots)
        candidates = [(c_digits, c_dots), (g_digits, g_dots)]
        # Split rescue untuk digit menyatu (closing maupun grouping),
        # plus kombinasi silang digit closing+split + titik grouping.
        for _base_digits, _base_dots, _merged in ((c_digits, c_dots, merged_c),
                                                  (g_digits, g_dots, merged_g)):
            try:
                r = try_split_decode(score, _base_digits, _base_dots, mask_bool)
                if r is not None:
                    return r[0], r[1], r[2], r[3] + " split"
                r = try_split_decode(score, _base_digits, _merged, mask_bool)
                if r is not None:
                    return r[0], r[1], r[2], r[3] + " split"
            except Exception:
                pass
        seen = set()
        for digits, dots in candidates:
            key = (tuple(digits), tuple(dots))
            if key in seen or not digits:
                continue
            seen.add(key)
            try:
                val, cf, raw, detail = decode_grid_value(score, digits, dots, "bright", 1.0)
                if val is not None:
                    return val, cf, raw, detail
                last_detail = detail
            except Exception as e:
                last_detail = f"decoder gagal: {e}"
    return None, 0.0, "", f"decoder: {last_detail}"


# Cache sudut deskew terbaik: {roi_key: (angle, hits)}. Dipakai supaya
# frame berikutnya mencoba sudut yang kemarin berhasil DULU -> cepat.
_DESKEW_CACHE = {}


def decode_roi_auto(img_bytes: bytes, roi, angle: float = 0.0, angles=None):
    """Decode 7-segmen dengan AUTO-DESKEW (dioptimalkan untuk kecepatan).

    Kamera yang agak miring membuat digit tidak tegak -> segmen ambigu ->
    decoder menolak. Fungsi ini mencoba beberapa sudut koreksi kecil dan
    memilih hasil dengan confidence tertinggi.

    Optimasi kecepatan (untuk pabrik hectic):
    1. Sudut yang TERAKHIR berhasil untuk ROI ini dicoba DULU (cache) ->
       frame berikutnya biasanya cukup 1 percobaan (0.1s).
    2. Early-exit lebih agresif: berhenti begitu conf >= 0.82.
    3. Bila sudut utama (angle) sudah berhasil, sudut lain TIDAK dicoba.

    Return sama seperti decode_roi: (nilai|None, conf, raw, detail).
    """
    # ROI key ringkas (dibulatkan) untuk cache sudut per-area.
    try:
        roi_key = tuple(round(float(v), 3) for v in roi)
    except Exception:
        roi_key = None

    if angles is None:
        angles = [angle, angle - 2, angle + 2, angle - 4, angle + 4,
                  angle - 6, angle + 6]

    # Susun urutan: sudut cache (paling mungkin berhasil) didahulukan.
    cached = _DESKEW_CACHE.get(roi_key) if roi_key is not None else None
    order = list(angles)
    if cached is not None and cached[0] in order:
        order.remove(cached[0])
        order.insert(0, cached[0])

    best = None  # (conf, val, raw, detail, angle)
    tried = []
    for ang in order:
        tried.append(ang)
        try:
            val, cf, raw, detail = decode_roi(img_bytes, roi, angle=ang)
        except Exception:
            continue
        if val is not None:
            if best is None or cf > best[0]:
                best = (cf, val, raw, detail, ang)
            # Berhenti lebih awal: sudut pertama yang berhasil & cukup yakin
            # tidak perlu lanjut. 0.82 = digit jelas & konsisten.
            if cf >= 0.82:
                break
            # Kalau sudut utama (angle) berhasil walau conf menengah, itu
            # paling mungkin sudut yang benar -> jangan coba yang lain.
            if ang == angle and cf >= 0.70:
                break

    if best is None:
        return decode_roi(img_bytes, roi, angle=angle)

    # Simpan sudut pemenang ke cache (naikkan hit-count bila sama).
    if roi_key is not None:
        if cached is not None and cached[0] == best[4]:
            _DESKEW_CACHE[roi_key] = (best[4], cached[1] + 1)
        else:
            _DESKEW_CACHE[roi_key] = (best[4], 1)

    return best[1], best[0], best[2], best[3]


def _row_groups(digit_boxes):
    """Kelompokkan box digit jadi baris (mirip _merge_row_boxes di cam_ocr):
    sebaris (overlap vertikal >=40%) dan gap wajar (<=1 tinggi digit)."""
    groups = []
    for b in sorted(digit_boxes, key=lambda d: d[0]):
        placed = False
        for g in groups:
            gx0 = min(d[0] for d in g)
            gy0 = min(d[1] for d in g)
            gx1 = max(d[2] for d in g)
            gy1 = max(d[3] for d in g)
            overlap_h = min(gy1, b[3]) - max(gy0, b[1])
            minh = min(gy1 - gy0, b[3] - b[1])
            gap = b[0] - gx1
            if minh > 0 and overlap_h / minh >= 0.4 and -minh * 0.5 <= gap <= minh * 1.0:
                g.append(b)
                placed = True
                break
        if not placed:
            groups.append([b])
    return groups


def detect_roi_struct(img_bytes: bytes, search_roi=None):
    """Cari ROI display dari struktur (tanpa OCR) ->
    (roi|None, teks_terbaca, conf, sudut, jumlah_digit).

    search_roi (fraksi) membatasi jendela cari; hasil dipetakan balik ke
    koordinat full-frame. Dua polaritas dicoba (terang-di-gelap dulu, lalu
    gelap-di-terang). ROI diterima bila grup digitnya >=2 dengan tinggi
    seragam — walau decoder belum tentu bisa membacanya (font cetak).
    Bila decoder berhasil, teksnya ikut dikembalikan sebagai validasi.
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None, "opencv belum diinstall (pip install opencv-python)", 0.0, 0.0, 0
    try:
        arr = np.frombuffer(img_bytes, dtype=np.uint8)
        img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
        if img is None:
            return None, "gagal decode gambar", 0.0, 0.0, 0
        H, W = img.shape[:2]
        ox, oy = 0, 0
        target = img
        if search_roi is not None:
            sx, sy, sw, sh = search_roi
            ox, oy = int(sx * W), int(sy * H)
            x1, y1 = int((sx + sw) * W), int((sy + sh) * H)
            target = img[oy:y1, ox:x1]
            if target.size == 0:
                return None, "jendela cari kosong", 0.0, 0.0, 0
        gray = cv2.cvtColor(target, cv2.COLOR_BGR2GRAY)
        if gray.size == 0 or float(np.percentile(gray, 99)) < 30:
            return None, "frame terlalu gelap", 0.0, 0.0, 0
        # Kanal-kenal seperti decode_roi v3: semua skor RELATIF latar
        # (nilai-bg)/(peak-bg), mask 0.35 — latar monitor gradien
        # (~0.22) dan ghost (~0.29) tetap di bawah mask, segmen atas
        # yang redup (~0.44) tetap masuk. Urutan: gray terang, warna,
        # G, R, B, lalu gray gelap (digit gelap di layar terang).
        def _bg_score(arr):
            try:
                bg_a = _border_mean(arr)
                peak_a = float(np.percentile(arr, 99)) if arr.size else 0.0
                if peak_a - bg_a < 30:
                    return None
                return np.clip((arr.astype(float) - bg_a) / max(peak_a - bg_a, 1.0), 0.0, 1.0)
            except Exception:
                return None

        attempts = []
        peak_all = float(np.percentile(gray, 99))
        s = _bg_score(gray)
        attempts.append(s if s is not None else np.clip(gray.astype(float) / max(peak_all, 1.0), 0.0, 1.0))
        try:
            ti = target.astype(int)
            color_arr = (ti.max(axis=2) - ti.min(axis=2)).astype(float)
        except Exception:
            color_arr = None
        if color_arr is not None:
            s = _bg_score(color_arr)
            if s is not None:
                attempts.append(s)
        for ch_idx in (1, 2, 0):
            try:
                s = _bg_score(target[:, :, ch_idx].astype(float))
                if s is not None:
                    attempts.append(s)
            except Exception:
                pass
        if float(gray.mean()) > 150:
            bg_a = _border_mean(gray)
            if bg_a >= 30:
                attempts.append(np.clip(1.0 - gray.astype(float) / max(bg_a, 1.0), 0.0, 1.0))
        best = None  # (skor, roi, ndigit)
        for score in attempts:
            mask = (score >= 0.35).astype(np.uint8) * 255
            # Bersihkan bintik noise, lalu dua strategi slot seperti decode.
            mask_open = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
            slot_sets = []
            try:
                closed = cv2.morphologyEx(mask_open, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
                d, _dots = classify_boxes(_boxes_from_mask(closed))
                slot_sets.append(d)
            except Exception:
                pass
            try:
                d, _dots = group_segment_boxes(_boxes_from_mask(mask_open))
                slot_sets.append(d)
            except Exception:
                pass
            for digits in slot_sets:
                if len(digits) < 2:
                    continue
                for group in _row_groups(digits):
                    if len(group) < 2:
                        continue
                    hs = [b[3] - b[1] for b in group]
                    mean_h = sum(hs) / len(hs)
                    if mean_h <= 0:
                        continue
                    var = sum((h - mean_h) ** 2 for h in hs) / len(hs)
                    uniformity = max(0.0, 1.0 - math.sqrt(var) / mean_h)
                    if uniformity < 0.5:
                        continue
                    gx0 = min(b[0] for b in group) + ox
                    gy0 = min(b[1] for b in group) + oy
                    gx1 = max(b[2] for b in group) + ox
                    gy1 = max(b[3] for b in group) + oy
                    gw, gh = max(1.0, gx1 - gx0), max(1.0, gy1 - gy0)
                    # Display timbangan wajar: lebar 15-55% frame, tinggi
                    # 5-40%, selalu lebih lebar dari tinggi (landscape).
                    # Batas atas diperketat dari 0.85/0.6 -> mencegah konten
                    # besar (seluruh layar, taskbar, monitor lain) dianggap
                    # display saat scene kamera ramai.
                    if gw > 0.55 * W or gh > 0.45 * H or gw < gh:
                        continue
                    if gw < 0.12 * W:
                        continue
                    margin = 0.18
                    rx0 = max(0.0, (gx0 - gw * margin) / W)
                    ry0 = max(0.0, (gy0 - gh * margin) / H)
                    rx1 = min(float(W), gx1 + gw * margin) / W
                    ry1 = min(float(H), gy1 + gh * margin) / H
                    roi = (round(rx0, 3), round(ry0, 3),
                           round(rx1 - rx0, 3), round(ry1 - ry0, 3))
                    n = len(group)
                    score_val = n * uniformity * (1.5 if gw > gh else 0.5) * (3.0 if 3 <= n <= 6 else 1.0)
                    if best is None or score_val > best[0]:
                        best = (score_val, roi, n)
        if best is None:
            return None, "tidak ada grup digit dari struktur", 0.0, 0.0, 0
        _s, roi, nd = best
        # Validasi lewat decoder (tidak wajib lolos: font cetak tetap sah
        # sebagai ROI, EasyOCR frame berikutnya yang membacanya).
        txt, cf = "", 0.0
        try:
            val, cf2, raw, _detail = decode_roi(img_bytes, roi, angle=0.0)
            if val is not None:
                txt, cf = f"{val:.2f}", cf2
        except Exception:
            pass
        return roi, txt, cf, 0.0, nd
    except Exception as e:
        return None, f"struktur gagal: {e}", 0.0, 0.0, 0
