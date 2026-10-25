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
        return None, 0.0, set(), {}, "narrow-bukan-bar"
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

__version__ = "2026-10-25-v3-bg"


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
    for _name, score, thresh in scores_to_try:
        mask = (score >= thresh).astype(np.uint8) * 255
        try:
            closed = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
            digits, dots = classify_boxes(_boxes_from_mask(closed))
            val, cf, raw, detail = decode_grid_value(score, digits, dots, "bright", 1.0)
            if val is not None:
                return val, cf, raw, detail
            last_detail = detail
        except Exception:
            pass
        try:
            digits, dots = group_segment_boxes(_boxes_from_mask(mask))
            val, cf, raw, detail = decode_grid_value(score, digits, dots, "bright", 1.0)
            if val is not None:
                return val, cf, raw, detail
            last_detail = detail
        except Exception as e:
            last_detail = f"decoder gagal: {e}"
    return None, 0.0, "", f"decoder: {last_detail}"


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
                    if gw > 0.85 * W or gh > 0.6 * H or gw < gh:
                        continue
                    margin = 0.15
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
