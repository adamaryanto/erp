#!/usr/bin/env python3
"""
ERP Timbang - Camera OCR Service (EasyOCR - stabil di Windows).

Alur:
  IP Cam (snapshot JPG / MJPEG, via LAN) -> service ini -> OCR angka display
  -> POST /api/cam/readings (simpan + auto-link selisih vs mesin)
  -> otomatis juga mengisi live cache source=cam, jadi halaman
     /timbang-cam yang polling ?source=cam langsung tampil tanpa ubah frontend.

Kebutuhan:
  pip install requests opencv-python numpy easyocr

Contoh jalan:
  python bridge/cam_ocr.py --snapshot-url http://192.168.1.50/snapshot.jpg --station POS-01
  python bridge/cam_ocr.py --snapshot-url http://192.168.1.50/snapshot.jpg --station POS-01 --simulate
  python bridge/cam_ocr.py --snapshot-url http://192.168.1.50/snapshot.jpg --station POS-01 --roi 0.35,0.30,0.30,0.20 --interval 2

RTSP / MJPEG (mis. EZVIZ H3C):
  URL snapshot boleh berupa stream, bukan cuma JPEG diam. Kalau URL
  diawali rtsp:// atau berupa MJPEG (kaya "/video.mjpg", "action=stream",
  "videofeed"), service ambil 1 frame lewat OpenCV (FFMPEG) lalu OCR
  seperti biasa. RTSP pakai koneksi TCP (lebih andal di WiFi/LAN).
  Contoh EZVIZ (aktifkan RTSP dulu di app EZVIZ → LAN Live View):
  python bridge/cam_ocr.py --snapshot-url "rtsp://admin:KODE@192.168.1.50:554/h264/ch1/main/av_stream" --station POS-01
  python bridge/cam_ocr.py --snapshot-url "http://admin:KODE@192.168.1.50/video.mjpg?q=30&fps=33&id=0.5" --station POS-01
  Mode pratinjau (tanpa OCR): --grab-only --out pratinjau.jpg

Gabungan (merge 2026-10-25): file ini = versi remote fee6887 + fitur
lokal yang di-port: AUTO-TRACK ROI di loop daemon (cari display sendiri,
lacak ulang berkala / saat gagal baca, simpan ROI ke server), preprocessing
adaptif (skala dari tinggi crop, deskew miring, normalisasi polaritas),
pacing interval dari awal frame, dan flag --rtsp-url yang kompatibel
(dipakai sebagai URL sumber; deteksi stream remote yang menanganinya).
Koreksi perspektif (2026-10-25): bila bacaan pertama gagal/ragu, polygon
angka di-warp jadi persegi lalu dibaca ulang (--perspective/--no-perspective);
hasil warp dipakai hanya bila lebih yakin.
Decoder 7-segmen + auto ROI struktur (2026-10-25, modul sevenseg.py):
bila EasyOCR gagal/ragu, decoder segmen (nyala/mati per segmen, tahan font
7-segmen apapun) menyelamatkan bacaan, dan AUTO-TRACK yang gagal lewat
EasyOCR dicoba ulang lewat kontur struktur (--seven-seg/--no-seven-seg,
kalibrasi tanpa OCR: --auto-roi-struct). Decoder tidak pernah menebak.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

try:
    import requests
except ImportError:
    # Biar service TIDAK mati seketika kalau requests belum diinstall:
    # errornya jelas per-frame + tertulis di status-file, proses tetap hidup.
    requests = None

# Decoder 7-segmen + auto ROI struktur (2026-10-25, lihat sevenseg.py):
# penyelamat saat EasyOCR gagal membaca font display. Modul terpisah biar
# logika murninya bisa di-unit-test tanpa opencv; kalau file-nya tidak
# ikut tercopy ke PC, service tetap jalan dengan EasyOCR saja (plus
# peringatan sekali saat start, bukan crash).
try:
    import sevenseg as _sevenseg
except ImportError:
    try:
        _sevenseg_dir = str(Path(__file__).resolve().parent)
        if _sevenseg_dir not in sys.path:
            sys.path.insert(0, _sevenseg_dir)
        import sevenseg as _sevenseg
    except ImportError:
        _sevenseg = None


def parse_roi(s: str) -> tuple[float, float, float, float]:
    """ROI sebagai fraksi gambar: 'x,y,w,h' masing-masing 0-1."""
    try:
        x, y, w, h = (float(v) for v in s.split(","))
    except ValueError:
        raise argparse.ArgumentTypeError("--roi format: x,y,w,h (contoh 0.35,0.30,0.30,0.20)")
    if not (0 <= x < 1 and 0 <= y < 1 and 0 < w <= 1 and 0 < h <= 1):
        raise argparse.ArgumentTypeError("--roi harus fraksi 0-1")
    return (x, y, w, h)


_SESSION = None


def _http_session():
    """Session keep-alive: request berikutnya tidak bayar handshake TCP baru.
    Hemat 50-200ms per frame di WiFi dibanding requests.get/post polos."""
    global _SESSION
    if requests is None:
        return None
    if _SESSION is None:
        _SESSION = requests.Session()
    return _SESSION


def apply_rotate(img_bytes: bytes, deg: int) -> bytes:
    """Putar frame SEBELUM ROI/OCR — kamera miring tetap kebaca.

    Kelipatan 90° = lossless (tanpa interpolasi, tanpa blur tambahan).
    Dipakai di semua jalur (daemon, --once, --auto-roi) jadi ROI yang
    disimpan/diuji selalu dalam koordinat gambar yg sudah tegak.
    """
    try:
        deg = int(deg or 0) % 360
    except (TypeError, ValueError):
        return img_bytes
    if deg == 0:
        return img_bytes
    try:
        import cv2
        import numpy as np
    except ImportError:
        return img_bytes  # tanpa opencv: lewati (tidak merusak alur)
    arr = np.frombuffer(img_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return img_bytes
    if deg == 90:
        img = cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    elif deg == 180:
        img = cv2.rotate(img, cv2.ROTATE_180)
    elif deg == 270:
        img = cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    else:
        return img_bytes  # hanya kelipatan 90 yg didukung
    ok, buf = cv2.imencode(".jpg", img, [int(cv2.IMWRITE_JPEG_QUALITY), 90])
    return bytes(buf) if ok else img_bytes


# Deteksi URL yang BUKAN JPEG diam tapi butuh ambil frame dari stream.
# Sengaja spesifik (bukan cuma kata "video") biar URL snapshot JPEG yang
# kebetulan ada "video" di path-nya tidak salah dikira stream.
_STREAM_HINT = re.compile(
    r"^rtsp://|mjpe?g|videofeed|videostream|action=stream|nphmotion|getoneshot|\.mjpg\b",
    re.I,
)

# Capture persisten per URL: RTSP itu mahal buka-tutup (handshake 1-2 dtk),
# jadi koneksi disimpan hidup dan dipakai ulang tiap frame.
_STREAM_CAPTURES: dict[str, object] = {}


def is_stream_url(url: str) -> bool:
    """True kalau URL berupa stream (RTSP/MJPEG), bukan file JPEG sekali ambil."""
    return bool(_STREAM_HINT.search(url or ""))


def release_stream_captures() -> None:
    """Tutup semua koneksi stream yang masih hidup (dipanggil saat berhenti)."""
    for cap in list(_STREAM_CAPTURES.values()):
        try:
            cap.release()
        except Exception:
            pass
    _STREAM_CAPTURES.clear()


def _grab_stream_frame(url: str, timeout: float = 8.0) -> bytes:
    """Ambil SATU frame terbaru dari RTSP/MJPEG via OpenCV (FFMPEG).

    Koneksi di-cache supaya frame berikutnya tidak bayar handshake lagi.
    Kalau read gagal/koneksi putus, capture dibuang supaya frame berikutnya
    buka ulang dari bersih (auto-reconnect).
    """
    try:
        import cv2
    except ImportError:
        raise RuntimeError("opencv belum diinstall (pip install opencv-python) — dibutuhkan untuk RTSP/MJPEG")

    # RTSP di WiFi/LAN jauh lebih andal lewat TCP (UDP sering rontok → frame rusak).
    # stimeout (mikrodetik) membatasi operasi socket FFMPEG biar tidak nyangkut lama.
    os.environ.setdefault(
        "OPENCV_FFMPEG_CAPTURE_OPTIONS",
        f"rtsp_transport;tcp|stimeout;{int(timeout * 1_000_000)}",
    )

    cap = _STREAM_CAPTURES.get(url)
    if cap is None or not cap.isOpened():
        ms = int(timeout * 1000)
        # Timeout HARUS diberikan saat membuka (konstruktor) — set() setelahnya
        # sudah terlambat karena open sudah blokir. Param ini didukung OpenCV 4.5.3+.
        try:
            cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG, [
                cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, ms,
                cv2.CAP_PROP_READ_TIMEOUT_MSEC, ms,
            ])
        except Exception:
            cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
        try:
            # Buffer 1: jangan menimbun frame lama, ambil yang terbaru saja.
            cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        except Exception:
            pass
        if not cap.isOpened():
            raise RuntimeError(f"tidak bisa buka stream: {url}")
        _STREAM_CAPTURES[url] = cap

    deadline = time.time() + timeout
    frame = None
    while time.time() < deadline:
        ok, frame = cap.read()
        if ok and frame is not None and getattr(frame, "size", 0):
            break
        frame = None
        time.sleep(0.05)

    if frame is None:
        # Koneksi mati/beku: buang biar percobaan berikutnya buka ulang.
        try:
            cap.release()
        except Exception:
            pass
        _STREAM_CAPTURES.pop(url, None)
        raise RuntimeError(f"stream tidak mengirim frame: {url}")

    # Ambil frame yang lebih segar bila tersedia (RTSP menahan 1-2 frame lama).
    try:
        if cap.grab():
            ok2, fresher = cap.retrieve()
            if ok2 and fresher is not None and getattr(fresher, "size", 0):
                frame = fresher
    except Exception:
        pass

    ok, buf = cv2.imencode(".jpg", frame, [int(cv2.IMWRITE_JPEG_QUALITY), 90])
    if not ok:
        raise RuntimeError("gagal encode frame JPEG")
    return bytes(buf)


def fetch_snapshot(url: str, timeout: float = 8.0) -> bytes:
    # RTSP/MJPEG: ambil frame lewat OpenCV, bukan GET HTTP (stream tak ada putusnya).
    if is_stream_url(url):
        return _grab_stream_frame(url, timeout)

    sess = _http_session()
    if sess is None:
        raise RuntimeError("modul 'requests' belum diinstall — jalankan: pip install requests opencv-python numpy easyocr")
    r = sess.get(url, timeout=timeout)
    r.raise_for_status()
    if len(r.content) < 5_000:
        raise RuntimeError(f"snapshot terlalu kecil ({len(r.content)} byte), URL mungkin salah")
    return r.content


def adaptive_scale(crop_h: int, crop_w: int, target_h: int = 96, max_w: int = 900) -> float:
    """Skala perbesaran menurut ukuran crop ASLI (jarak kamera tidak relevan).

    Display jauh/kecil -> crop mungil -> diperbesar banyak (maks 4x);
    display dekat/besar -> apa adanya. Lebar hasil dibatasi biar infer
    tidak makin berat. (Port dari versi lokal.)
    """
    if crop_h <= 0 or crop_w <= 0:
        return 1.0
    s = target_h / crop_h
    s = max(1.0, min(4.0, s))
    if crop_w * s > max_w:
        s = max(0.5, max_w / crop_w)
    return s


def preprocess_for_ocr(img_bytes: bytes, roi: tuple[float, float, float, float], scale: float | None = None,
                       angle: float = 0.0):
    """Crop ROI display + luruskan miring + perbesar adaptif + threshold.

    scale=None (bawaan): skala dihitung dari tinggi crop (adaptive_scale),
    jadi ukuran font/jarak kamera berubah pun digit hasil crop berukuran
    mirip terus. angle = kemiringan teks dari tracking (derajat): crop
    diputar balik sebelum dibaca, jadi kamera roll tetap kebaca.
    Polaritas dinormalkan: latar dibuat putih, digit gelap (format yang
    paling dikenali recognizer). (Port dari versi lokal; menggantikan
    skala tetap 2.5 versi remote.)
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None  # fallback: OCR langsung dari file full-frame

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
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    if abs(angle) >= 1.5:
        # Deskew: putar balik sebesar sudut teks (tanda dibalik, koordinat
        # gambar y-ke-bawah). Pinggir di-replicate biar sudut buatan tidak
        # ikut ke-threshold sebagai digit palsu.
        ch, cw = gray.shape[:2]
        M = cv2.getRotationMatrix2D((cw / 2, ch / 2), angle, 1.0)
        gray = cv2.warpAffine(gray, M, (cw, ch), flags=cv2.INTER_CUBIC,
                              borderMode=cv2.BORDER_REPLICATE)
    if scale is None:
        scale = adaptive_scale(gray.shape[0], gray.shape[1])
    if scale != 1.0:
        interp = cv2.INTER_CUBIC if scale > 1.0 else cv2.INTER_AREA
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=interp)
    gray = cv2.GaussianBlur(gray, (3, 3), 0)
    _, th = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    # Polaritas: tepi gambar = latar display. Kalau latar gelap (LED terang
    # di atas hitam), balik jadi digit gelap di atas putih.
    border = np.concatenate([th[0, :], th[-1, :], th[:, 0], th[:, -1]])
    if border.size and float(border.mean()) < 127:
        th = 255 - th
    return th


_OCR_READER = None


_OCR_READER = None
_FAST_READER = None


def get_reader(fast: bool = False):
    """EasyOCR reader (stabil di Windows, CPU OK).
    
    fast=True: recognizer only (detector off) -> 2-3 detik/frame, ROI harus pas.
    fast=False: full detect+recognize -> lebih teliti, buat kalibrasi.
    """
    global _OCR_READER, _FAST_READER
    try:
        import easyocr  # type: ignore
    except ImportError:
        return None
    if fast:
        if _FAST_READER is None:
            _FAST_READER = easyocr.Reader(["en"], gpu=False, detector=False, verbose=False)
        return _FAST_READER
    if _OCR_READER is None:
        _OCR_READER = easyocr.Reader(["en"], gpu=False)
    return _OCR_READER


def pick_number(texts: list[tuple[str, float]]) -> tuple[float | None, float, str]:
    """Cari angka kg dari daftar (teks, conf)."""
    best: tuple[float, float] | None = None
    raw_all = " ".join(t for t, _ in texts)
    for text, conf in texts:
        # Display 7-segmen sering kebaca berjarak ("24 . 92") → rapatkan dulu.
        t = text.replace(",", ".").replace(" ", "")
        # Titik desimal kecil sering hilang ("22 85") atau kebaca strip
        # ("22-75"): normalkan strip-diapit-digit jadi titik dulu.
        t = re.sub(r"(?<=\d)-(?=\d)", ".", t)
        for m in re.findall(r"(\d{1,3}[.,]\d{1,2})", t):
            try:
                v = float(m)
            except ValueError:
                continue
            # conf bisa string ('a') kalau easyocr recognize() balik aneh — amanin.
            try:
                cf = float(conf)
            except (ValueError, TypeError):
                cf = 0.5
            if v == 0.0:
                # Nol: titik di mana pun tidak mengubah nilai — angkat ke 0.3
                # (kasus "~0,00" conf 0.13: digitnya nol semua, jangan dibuang).
                cf = max(cf, 0.3)
            # Nol = timbangan kosong: LAPOR (biar LED ikut 0), server yang
            # memutuskan live-only tanpa masuk DB.
            if 0 <= v < 200 and (best is None or cf > best[1]):
                best = (v, cf)
    if best is None:
        # Display selalu 2 desimal tapi titiknya hilang total ("22 85"→"2285"):
        # sisipkan titik implisit. Hanya bila digit total PAS 4 (3 digit ambigu:
        # "228" = 2.28 atau 22.8?) dan hasil <200kg. Conf 0.3: yakin digitnya,
        # tidak yakin titiknya — lolos min-conf longgar, ketolak yg ketat.
        digits = re.sub(r"\D", "", raw_all)
        # Semua-nol ("0 00", "00", "000"): TITIK NGGAK RELEVAN — nol tetap nol
        # di posisi desimal mana pun. Min 2 digit biar serpihan "0" tunggal
        # tidak dikira nol beneran. Server tetap live-only (tanpa masuk DB).
        if len(digits) >= 2 and set(digits) == {"0"}:
            return 0.0, 0.3, raw_all
        if len(digits) == 4:
            try:
                v_imp = int(digits) / 100
                if 0 <= v_imp < 200:
                    return round(v_imp, 2), 0.3, raw_all
            except ValueError:
                pass
        m = re.findall(r"(\d+\.\d+)", raw_all.replace(",", "."))
        if m:
            try:
                return float(m[-1]), 0.3, raw_all
            except ValueError:
                pass
        return None, 0.0, raw_all
    return best[0], best[1], raw_all


# --- KOREKSI PERSPEKTIF (2026-10-25, kasus Ezviz H3c miring) ---
# Masalahnya: kamera melihat layar dari samping -> layar jadi trapesium,
# angka ikut miring/shear. Deskew lama hanya menangani miring kecil (roll),
# bukan trapesium. Solusinya di sini: kalau bacaan pertama gagal / ragu,
# jalankan deteksi full sekali di crop ROI, ambil polygon 4 titik grup
# angka terbaik, warp polygon itu jadi persegi tegak (getPerspectiveTransform),
# lalu baca ulang hasil warp. Kalau polygon tidak cukup miring, atau hasil
# warp tidak lebih yakin dari bacaan pertama, bacaan pertama dipertahankan
# (tidak ada regresi untuk kamera yang sudah tegak lurus). Semua helper di
# bawah ini anti-gagal: kembalikan None bila opencv/model tidak ada, jadi
# loop daemon tidak pernah mati karena koreksi ini.


def _order_points(pts):
    """4 titik sembarang -> urutan [kiri-atas, kanan-atas, kanan-bawah,
    kiri-bawah]. Murni hitungan, bisa di-unit-test tanpa opencv."""
    import math

    p = [(float(x), float(y)) for x, y in pts]
    # kiri-atas = jumlah x+y terkecil, kanan-bawah = terbesar;
    # kanan-atas = selisih y-x terkecil, kiri-bawah = terbesar.
    tl = min(p, key=lambda q: q[0] + q[1])
    br = max(p, key=lambda q: q[0] + q[1])
    tr = min(p, key=lambda q: q[1] - q[0])
    bl = max(p, key=lambda q: q[1] - q[0])
    return [tl, tr, br, bl]


def _polygon_skew(ordered):
    """Seberapa trapesium polygon itu: 0 = persegi sempurna, mendekati 1 =
    sangat trapesium. Bandingkan panjang sisi atas vs bawah dan kiri vs
    kanan. Murni hitungan."""
    import math

    (tl, tr, br, bl) = ordered

    def _dist(a, b):
        return math.hypot(a[0] - b[0], a[1] - b[1])

    top, bottom = _dist(tl, tr), _dist(bl, br)
    left, right = _dist(tl, bl), _dist(tr, br)
    if min(top, bottom, left, right) <= 0:
        return 0.0
    return max(abs(top - bottom) / max(top, bottom),
               abs(left - right) / max(left, right))


def _best_digit_polygon(results):
    """Dari hasil readtext (crop ROI) -> polygon 4 titik grup angka terbaik,
    atau None. Grup = box angka yang sebaris & berdekatan (kriteria yang
    sama dengan _merge_row_boxes); polygon = hull sudut-sudut box grup yang
    didekati jadi 4 titik. Butuh opencv hanya untuk hull/approx."""
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None
    boxes = []
    for item in results or []:
        if not isinstance(item, (list, tuple)) or len(item) < 3:
            continue
        bbox, text, conf = item[0], str(item[1]), item[2]
        if not re.search(r"\d", text.replace(",", ".").replace(" ", "")):
            continue
        try:
            xs = [float(p[0]) for p in bbox]
            ys = [float(p[1]) for p in bbox]
        except (TypeError, IndexError, ValueError):
            continue
        try:
            cf = float(conf)
        except (TypeError, ValueError):
            cf = 0.3
        boxes.append({"bbox": bbox, "x0": min(xs), "y0": min(ys),
                      "x1": max(xs), "y1": max(ys), "text": text, "conf": cf})
    if not boxes:
        return None
    groups = []
    for b in sorted(boxes, key=lambda d: d["x0"]):
        placed = False
        for g in groups:
            gx0 = min(d["x0"] for d in g)
            gy0 = min(d["y0"] for d in g)
            gx1 = max(d["x1"] for d in g)
            gy1 = max(d["y1"] for d in g)
            overlap_h = min(gy1, b["y1"]) - max(gy0, b["y0"])
            minh = min(gy1 - gy0, b["y1"] - b["y0"])
            gap = b["x0"] - gx1
            if minh > 0 and overlap_h / minh >= 0.4 and -minh * 0.5 <= gap <= minh * 1.0:
                g.append(b)
                placed = True
                break
        if not placed:
            groups.append([b])
    best = None
    for g in groups:
        x0 = min(b["x0"] for b in g)
        y0 = min(b["y0"] for b in g)
        x1 = max(b["x1"] for b in g)
        y1 = max(b["y1"] for b in g)
        area = max(0.0, x1 - x0) * max(0.0, y1 - y0)
        txt = " ".join(b["text"] for b in sorted(g, key=lambda b: b["x0"]))
        t = txt.replace(",", ".").replace(" ", "")
        cf = sum(b["conf"] for b in g) / len(g)
        w_, h_ = max(1.0, x1 - x0), max(1.0, y1 - y0)
        score = area * cf
        score *= 3.0 if re.search(r"\d+[.,]\d+", t) else 1.0
        score *= 1.5 if w_ > h_ else 0.4
        if best is None or score > best[0]:
            best = (score, g)
    if best is None:
        return None
    pts = []
    for b in best[1]:
        for p in b["bbox"]:
            try:
                pts.append([float(p[0]), float(p[1])])
            except (TypeError, ValueError):
                pass
    if len(pts) < 4:
        return None
    arr = np.array(pts, dtype=np.float32)
    hull = cv2.convexHull(arr)
    peri = cv2.arcLength(hull, True)
    approx = cv2.approxPolyDP(hull, 0.02 * peri, True)
    if len(approx) != 4:
        return None
    return [(float(p[0][0]), float(p[0][1])) for p in approx]


def _warp_polygon_to_bytes(img_arr, poly):
    """Warp area polygon (koordinat piksel img_arr) jadi gambar persegi
    tegak, dikembalikan sebagai JPEG bytes. None bila gagal."""
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None
    try:
        ordered = _order_points(poly)
        (tl, tr, br, bl) = ordered
        import math
        width = int(max(math.hypot(tr[0] - tl[0], tr[1] - tl[1]),
                        math.hypot(br[0] - bl[0], br[1] - bl[1])))
        height = int(max(math.hypot(bl[0] - tl[0], bl[1] - tl[1]),
                         math.hypot(br[0] - tr[0], br[1] - tr[1])))
        if width < 20 or height < 10 or width > 4000 or height > 4000:
            return None
        src = np.array(ordered, dtype=np.float32)
        dst = np.array([[0, 0], [width - 1, 0], [width - 1, height - 1],
                        [0, height - 1]], dtype=np.float32)
        m = cv2.getPerspectiveTransform(src, dst)
        warped = cv2.warpPerspective(img_arr, m, (width, height),
                                     borderMode=cv2.BORDER_REPLICATE)
        ok, buf = cv2.imencode(".jpg", warped)
        return buf.tobytes() if ok else None
    except Exception:
        return None


def _pairs_from_recognize(texts):
    """Ubah hasil reader.recognize() jadi list (teks, conf), dengan
    pengaman yang sama seperti jalur cepat lama (bbox dibuang, conf
    nyasar tidak boleh crash)."""
    pairs: list[tuple[str, float]] = []
    for item in texts or []:
        txt, cf = '', 0.5
        if isinstance(item, (list, tuple)) and len(item) >= 3 \
                and isinstance(item[0], (list, tuple)) and isinstance(item[1], str):
            # Bentuk readtext: (bbox, teks, conf) — bbox HARUS dibuang,
            # kalau ikut ke teks, koordinat "[0, 240]" kebaca jadi "0,24".
            txt = item[1]
            try:
                cf = float(item[2])
            except (TypeError, ValueError):
                cf = 0.5
        elif isinstance(item, (list, tuple)) and len(item) >= 2:
            # Bentuk (teks, conf) — tapi conf kadang string nyasar
            # ("24 . 92", "a") → JANGAN langsung float(), crash 22 Sep.
            try:
                cf = float(str(item[1]).replace(',', '.'))
                txt = str(item[0])
            except (TypeError, ValueError):
                txt = (str(item[0]) + ' ' + str(item[1])).strip()
        else:
            txt = str(item)
        pairs.append((txt, cf))
    return pairs


def _perspective_retry(img_bytes: bytes, roi):
    """Percobaan kedua khusus layar miring: deteksi full di crop ROI,
    warp polygon angka terbaik jadi persegi, baca ulang. Kembalikan
    (angka, conf, raw) bila berhasil, atau None bila tidak ada polygon
    miring / warp gagal / hasil tidak membaik. Dipanggil HANYA bila
    bacaan pertama gagal atau ragu, jadi frame yang sudah bagus tidak
    membayar biaya deteksi full ini."""
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None
    full_reader = get_reader(fast=False)
    fast_reader = get_reader(fast=True)
    if full_reader is None or fast_reader is None:
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
    try:
        results = full_reader.readtext(crop)
    except Exception:
        return None
    poly = _best_digit_polygon(results)
    if poly is None:
        return None
    ordered = _order_points(poly)
    # Hanya warp bila memang trapesium (skew >= 15%); kalau sudah persegi,
    # baca ulang tidak akan memperbaiki apa-apa.
    if _polygon_skew(ordered) < 0.15:
        return None
    # Polygon harus menutupi sebagian wajar dari crop (bukan serpihan).
    xs = [p[0] for p in poly]
    ys = [p[1] for p in poly]
    pw, ph = max(xs) - min(xs), max(ys) - min(ys)
    if pw <= 0 or ph <= 0 or pw < 0.05 * crop.shape[1] or ph < 0.05 * crop.shape[0]:
        return None
    warped_bytes = _warp_polygon_to_bytes(crop, poly)
    if warped_bytes is None:
        return None
    try:
        # Gambar warp sudah tegak: baca dengan ROI penuh, tanpa deskew.
        warped_crop = preprocess_for_ocr(warped_bytes, (0.0, 0.0, 1.0, 1.0),
                                         angle=0.0)
        if warped_crop is None:
            return None
        texts = fast_reader.recognize(warped_crop)
    except Exception:
        return None
    val, cf, raw = pick_number(_pairs_from_recognize(texts))
    if val is None:
        return None
    return val, cf, raw + " [warp-perspektif]"


def _try_7seg(img_bytes: bytes, roi, angle: float = 0.0):
    """Decoder 7-segmen sebagai penyelamat -> (angka, conf, raw) atau None.

    Hanya dipanggil saat EasyOCR gagal/ragu (lihat ocr_weight_fast /
    ocr_weight). Decoder TIDAK pernah menebak: gagal total -> None, dan
    pemanggil tetap memakai hasil EasyOCR. Marker [7seg] di raw menandai
    di log bahwa angka ini dari decoder, bukan EasyOCR.
    """
    if _sevenseg is None:
        return None
    try:
        val, cf, raw, detail = _sevenseg.decode_roi(img_bytes, roi, angle=angle)
    except Exception as e:
        print(f"[CAM-OCR] 7seg gagal (exception): {e}", flush=True)
        return None
    if val is None:
        # Kegagalan decoder DITULIS (bukan diam): tanpa baris ini, frame
        # yang gagal decoder tidak bisa dibedakan dari decoder yang
        # tidak aktif sama sekali — persis kebingungan diagnosa kemarin.
        print(f"[CAM-OCR] 7seg gagal: {detail}", flush=True)
        return None
    print(f"[CAM-OCR] 7seg rescue: {raw} kg (conf {cf:.2f}) {detail}", flush=True)
    return val, cf, f"{val:.2f} [7seg]"


def ocr_weight_fast(img_bytes: bytes, roi, angle: float = 0.0, persp: bool = True, seven_seg: bool = True) -> tuple[float | None, float, str]:
    """Jalur cepat: crop ROI -> recognizer langsung (tanpa deteksi).
    Bila persp on dan bacaan pertama gagal (None) atau ragu (conf < 0.6),
    coba sekali lagi lewat koreksi perspektif; hasil warp hanya dipakai
    bila lebih yakin dari bacaan pertama. Terakhir, bila EasyOCR tetap
    gagal atau ragu (conf < 0.6), coba decoder 7-segmen; hasilnya dipakai
    hanya bila decoder yakin (conf >= 0.7) — EasyOCR yang yakin tidak
    pernah ditimpa decoder."""
    reader = get_reader(fast=True)
    if reader is None:
        return None, 0.0, "easyocr belum diinstall (pip install easyocr)"
    crop = preprocess_for_ocr(img_bytes, roi, angle=angle)  # skala adaptif + deskew (port lokal)
    if crop is None:
        return None, 0.0, "gagal crop (opencv hilang?)"
    try:
        texts = reader.recognize(crop)
    except Exception as e:
        return None, 0.0, f"recognize gagal: {e}"
    val, cf, raw = pick_number(_pairs_from_recognize(texts))
    # Retry perspektif HANYA kalau benar-benar tidak dapat angka. Dulu juga
    # retry saat conf <0.6 → tiap frame layar silau jadi 2x infer (0.8 dtk).
    # Konsistensi temporal di loop daemon yang menangani conf rendah.
    if persp and val is None:
        try:
            retry = _perspective_retry(img_bytes, roi)
        except Exception:
            retry = None
        if retry is not None and (val is None or retry[1] > cf):
            return retry
    # Penyelamat 7-segmen: murah (kontur + ukur segmen, tanpa model), jadi
    # boleh jalan tiap frame yang gagal/ragu tanpa menambah detik infer.
    if seven_seg and (val is None or cf < 0.6):
        rescue = _try_7seg(img_bytes, roi, angle=angle)
        if rescue is not None and (val is None or rescue[1] >= 0.7):
            return rescue
    return val, cf, raw


def ocr_weight(img_bytes: bytes, roi, fast: bool = False, angle: float = 0.0, persp: bool = True, seven_seg: bool = True) -> tuple[float | None, float, str]:
    """Kembalikan (angka_kg, confidence 0-1, raw_text).

    fast=True: recognizer langsung di crop ROI (2-3 dtk, buat service).
    fast=False: deteksi dulu baru baca (lebih teliti, buat kalibrasi --once).
    angle diteruskan ke preprocessing untuk deskew (port lokal).
    persp=True: bila bacaan pertama gagal/ragu, coba sekali koreksi
    perspektif (warp polygon angka jadi persegi) dan pakai hasilnya hanya
    bila lebih yakin.
    """
    if fast:
        return ocr_weight_fast(img_bytes, roi, angle=angle, persp=persp, seven_seg=seven_seg)

    reader = get_reader(fast=False)
    if reader is None:
        return None, 0.0, "easyocr belum diinstall (pip install easyocr)"

    processed = preprocess_for_ocr(img_bytes, roi, angle=angle)

    if processed is not None:
        results = reader.readtext(processed)
    else:
        # fallback tanpa opencv: tulis temp file lalu baca
        tmp = Path("storage/app/cam_tmp.jpg")
        tmp.parent.mkdir(parents=True, exist_ok=True)
        tmp.write_bytes(img_bytes)
        results = reader.readtext(str(tmp))

    pairs = [(str(t), float(c)) for _, t, c in results]
    val, cf, raw = pick_number(pairs)
    if persp and (val is None or cf < 0.6):
        try:
            retry = _perspective_retry(img_bytes, roi)
        except Exception:
            retry = None
        if retry is not None and (val is None or retry[1] > cf):
            return retry
    if seven_seg and (val is None or cf < 0.6):
        rescue = _try_7seg(img_bytes, roi, angle=angle)
        if rescue is not None and (val is None or rescue[1] >= 0.7):
            return rescue
    return val, cf, raw


def _score_digit_groups(results, W: int, H: int):
    """Gabung box sebaris + nilai tiap grup angka (dipakai auto-roi biasa
    maupun coba-putar). Kembalikan list (score, x0, y0, x1, y1, teks, conf)
    terurut menurun. Murni hitungan (tanpa OCR)."""
    boxes = []
    for item in results or []:
        if not isinstance(item, (list, tuple)) or len(item) < 3:
            continue
        bbox, text, conf = item[0], str(item[1]), item[2]
        t = text.replace(",", ".").replace(" ", "")
        if not re.search(r"\d", t):
            continue
        try:
            xs = [p[0] for p in bbox]
            ys = [p[1] for p in bbox]
        except (TypeError, IndexError):
            continue
        try:
            cf = float(conf)
        except (TypeError, ValueError):
            cf = 0.3
        boxes.append({"x0": min(xs), "y0": min(ys), "x1": max(xs), "y1": max(ys),
                      "text": text, "conf": cf})
    groups = _merge_row_boxes(boxes)
    scored = []
    for g in groups:
        x0 = min(b["x0"] for b in g)
        y0 = min(b["y0"] for b in g)
        x1 = max(b["x1"] for b in g)
        y1 = max(b["y1"] for b in g)
        area = max(0.0, x1 - x0) * max(0.0, y1 - y0)
        txt = " ".join(b["text"] for b in sorted(g, key=lambda b: b["x0"]))
        t = txt.replace(",", ".").replace(" ", "")
        cf = sum(b["conf"] for b in g) / len(g)
        w_, h_ = max(1.0, x1 - x0), max(1.0, y1 - y0)
        # Grup desimal ("22.99") menang besar; grup landscape (display)
        # menang atas grup portrait (serpihan 1 digit / teks vertical).
        score = area * cf
        score *= 3.0 if re.search(r"\d+[.,]\d+", t) else 1.0
        score *= 1.5 if w_ > h_ else 0.4
        scored.append((score, x0, y0, x1, y1, txt, cf))
    scored.sort(key=lambda s: -s[0])

    return scored


def _roi_from_box(best, W: int, H: int, margin: float = 0.15):
    """Box piksel -> ROI fraksi 0-1 + teks. Kembalikan ((x,y,w,h), teks)."""
    _, x0, y0, x1, y1, txt, _cf = best
    bw, bh = max(1.0, x1 - x0), max(1.0, y1 - y0)
    rx0 = max(0.0, (x0 - bw * margin) / W)
    ry0 = max(0.0, (y0 - bh * margin) / H)
    rx1 = min(float(W), x1 + bw * margin) / W
    ry1 = min(float(H), y1 + bh * margin) / H

    return (round(rx0, 3), round(ry0, 3), round(rx1 - rx0, 3), round(ry1 - ry0, 3)), txt


def detect_roi(img_bytes: bytes) -> tuple[tuple[float, float, float, float] | None, str]:
    """Auto-ROI sekali jalan: full-frame readtext -> gabung box sebaris
    ("22" + "." + "99" yg terpecah) -> pilih grup angka terbaik.

    Dipakai tombol AUTO ROI (kalibrasi), BUKAN loop service. Kembalikan
    ((x, y, w, h) fraksi 0-1, teks_terbaca).
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None, "opencv belum diinstall (pip install opencv-python)"
    reader = get_reader(fast=False)
    if reader is None:
        return None, "easyocr belum diinstall (pip install easyocr)"
    arr = np.frombuffer(img_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return None, "gagal decode gambar"
    H, W = img.shape[:2]
    try:
        results = reader.readtext(img)
    except Exception as e:
        return None, f"detect gagal: {e}"
    scored = _score_digit_groups(results, W, H)
    if not scored:
        seen = " | ".join(str(t) for _, t, _ in (results or [])[:6]) or "-"
        return None, f"tidak ada angka terdeteksi (kelihatan: {seen[:80]})"

    return _roi_from_box(scored[0], W, H)


def _merge_row_boxes(boxes: list[dict]) -> list[list[dict]]:
    """Gabung box angka yg sebaris & berdekatan jadi satu grup display.

    Display digital sering terdeteksi pecah ("22" "." "99"); tanpa gabung,
    serpihan 1 digit bisa menang atas angka utuh. Murni hitungan (tanpa
    OCR) supaya bisa di-unit-test pakai hasil palsu.
    """
    groups: list[list[dict]] = []
    for b in sorted(boxes, key=lambda d: d["x0"]):
        placed = False
        for g in groups:
            gx0 = min(d["x0"] for d in g)
            gy0 = min(d["y0"] for d in g)
            gx1 = max(d["x1"] for d in g)
            gy1 = max(d["y1"] for d in g)
            overlap_h = min(gy1, b["y1"]) - max(gy0, b["y0"])
            minh = min(gy1 - gy0, b["y1"] - b["y0"])
            gap = b["x0"] - gx1
            # Sebaris (overlap vertikal >=40% tinggi terkecil) dan gap wajar
            # (boleh sedikit tumpuk = deteksi ganda, sampai ~1 tinggi digit
            # untuk titik yg jauh). Jam taskbar yg jauh tidak ikut gabung.
            if minh > 0 and overlap_h / minh >= 0.4 and -minh * 0.5 <= gap <= minh * 1.0:
                g.append(b)
                placed = True
                break
        if not placed:
            groups.append([b])
    return groups


# --- AUTO-TRACK ROI (port dari versi lokal) ---
# Dipakai HANYA di loop daemon (lihat main): cari display sendiri saat
# start, lacak ulang berkala / saat bacaan gagal beruntun, simpan ROI ke
# server bila bergeser jauh. --auto-roi remote di bawah tetap memakai
# detect_roi/_score_digit_groups-nya sendiri dan tidak tersentuh blok ini.


def tracking_text_ok(txt: str) -> bool:
    """Teks hasil deteksi cukup yakin untuk memindahkan ROI?

    Display timbangan normal = beberapa digit ("23.45", "0.00"). Deteksi
    satu digit acak ('1', '3', '8' dari segmen/latar) JANGAN dipakai:
    dulu ROI drift ke area sembarang, OCR gagal terus, lalu service
    me-restart diri tiap ROI "bergeser". Pengecualian: '0' sah untuk
    timbangan kosong yang cuma menampilkan satu nol.
    """
    t = (txt or "").replace(",", ".").replace(" ", "")
    if len(re.findall(r"\d", t)) >= 2:
        return True
    return t in ("0", "0.", "0.0", "0.00")


def roi_clamp(roi) -> tuple[float, float, float, float]:
    x, y, w, h = (float(v) for v in roi)
    x = min(max(x, 0.0), 0.99)
    y = min(max(y, 0.0), 0.99)
    w = min(max(w, 0.01), 1.0 - x)
    h = min(max(h, 0.01), 1.0 - y)
    return (x, y, w, h)


def roi_expand(roi, frac: float = 0.75) -> tuple[float, float, float, float]:
    """Lebarkan ROI frac*(w,h) ke tiap sisi — jendela cari saat tracking."""
    x, y, w, h = roi
    return roi_clamp((x - w * frac, y - h * frac, w * (1 + 2 * frac), h * (1 + 2 * frac)))


def roi_smooth(old, new, alpha: float = 0.6) -> tuple[float, float, float, float]:
    """Gerak ROI jangan lompat: campur ROI lama dengan temuan baru."""
    return roi_clamp(tuple(o + (n - o) * alpha for o, n in zip(old, new)))


def roi_changed_enough(a, b, pos: float = 0.02, size: float = 0.20) -> bool:
    """True bila pusat geser > pos (fraksi) atau ukuran berubah > size (relatif)."""
    acx, acy = a[0] + a[2] / 2, a[1] + a[3] / 2
    bcx, bcy = b[0] + b[2] / 2, b[1] + b[3] / 2
    if abs(acx - bcx) > pos or abs(acy - bcy) > pos:
        return True
    return any(abs(nv - ov) > size * max(ov, 0.01) for ov, nv in zip(a[2:], b[2:]))


def bbox_angle_deg(bbox) -> float:
    """Sudut miring garis teks (derajat, koordinat gambar y-ke-bawah).

    Dari sisi atas box deteksi (p0->p1). Dinormalkan ke -45..45: display
    miring karena roll kamera berapa pun, teksnya tetap 'hampir horizontal'.
    """
    import math

    try:
        x0, y0 = float(bbox[0][0]), float(bbox[0][1])
        x1, y1 = float(bbox[1][0]), float(bbox[1][1])
    except (TypeError, IndexError, ValueError):
        return 0.0
    ang = math.degrees(math.atan2(y1 - y0, x1 - x0))
    while ang > 45:
        ang -= 90
    while ang < -45:
        ang += 90
    return ang


def pick_roi_from_results(results, W: int, H: int, margin: float = 0.15):
    """Pilih box angka terbaik dari hasil readtext -> (roi, teks, conf, sudut).

    Murni hitungan (tanpa OCR) supaya bisa di-unit-test pakai hasil palsu.
    Sudut = kemiringan teks dalam gambar, buat deskew saat recognizer jalan.
    """
    best = None
    for item in results or []:
        if not isinstance(item, (list, tuple)) or len(item) < 3:
            continue
        bbox, text, conf = item[0], str(item[1]), item[2]
        t = text.replace(",", ".").replace(" ", "")
        if not re.search(r"\d", t):
            continue
        try:
            xs = [p[0] for p in bbox]
            ys = [p[1] for p in bbox]
        except (TypeError, IndexError):
            continue
        x0, y0, x1, y1 = min(xs), min(ys), max(xs), max(ys)
        area = max(0.0, x1 - x0) * max(0.0, y1 - y0)
        try:
            cf = float(conf)
        except (TypeError, ValueError):
            cf = 0.3
        score = area * cf * (2.0 if re.search(r"\d+[.,]\d+", t) else 1.0)
        if best is None or score > best[0]:
            best = (score, x0, y0, x1, y1, text, cf, bbox_angle_deg(bbox))
    if best is None:
        seen = " | ".join(str(t) for _, t, _ in (results or [])[:6]) or "-"
        return None, f"tidak ada angka terdeteksi (kelihatan: {seen[:80]})", 0.0, 0.0
    _, x0, y0, x1, y1, txt, cf, ang = best
    bw, bh = max(1.0, x1 - x0), max(1.0, y1 - y0)
    rx0 = max(0.0, (x0 - bw * margin) / W)
    ry0 = max(0.0, (y0 - bh * margin) / H)
    rx1 = min(float(W), x1 + bw * margin) / W
    ry1 = min(float(H), y1 + bh * margin) / H
    roi = (round(rx0, 3), round(ry0, 3), round(rx1 - rx0, 3), round(ry1 - ry0, 3))
    return roi, txt, cf, ang


def detect_roi_in_frame(img_bytes: bytes, search_roi=None):
    """Deteksi area display dalam satu frame -> (roi, teks, conf, sudut_miring).

    search_roi diberikan: deteksi hanya di jendela itu (lebih cepat dari
    full-frame, dan display yang bergeser dikit tetap ketemu); koordinat
    hasil dipetakan balik ke fraksi full-frame. margin lebih longgar
    (0.30) biar recognizer mode --fast punya ruang napas.
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None, "opencv belum diinstall (pip install opencv-python)", 0.0, 0.0
    reader = get_reader(fast=False)
    if reader is None:
        return None, "easyocr belum diinstall (pip install easyocr)", 0.0, 0.0
    arr = np.frombuffer(img_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return None, "gagal decode gambar", 0.0, 0.0
    H, W = img.shape[:2]
    ox, oy = 0, 0
    target = img
    if search_roi is not None:
        sx, sy, sw, sh = search_roi
        ox, oy = int(sx * W), int(sy * H)
        x1, y1 = int((sx + sw) * W), int((sy + sh) * H)
        target = img[oy:y1, ox:x1]
        if target.size == 0:
            return None, "jendela cari kosong", 0.0, 0.0
    try:
        results = reader.readtext(target)
    except Exception as e:
        return None, f"detect gagal: {e}", 0.0, 0.0
    if ox or oy:
        shifted = []
        for item in results or []:
            if isinstance(item, (list, tuple)) and len(item) >= 3:
                bbox = [[p[0] + ox, p[1] + oy] for p in item[0]]
                shifted.append((bbox, item[1], item[2]))
        results = shifted
    return pick_roi_from_results(results, W, H, margin=0.30)


def post_roi_save(api_base: str, station: str, roi) -> bool:
    """Simpan ROI hasil tracking ke Pengaturan server (endpoint yang sama
    dengan tombol SIMPAN di modal kalibrasi), biar restart service tidak
    mulai dari ROI lama."""
    if requests is None:
        return False
    try:
        r = _http_session().post(
            api_base.rstrip("/") + "/api/cam/service/roi",
            json={"station": station,
                  "roi": ",".join(f"{float(v):.3f}" for v in roi),
                  # Jangan restart service: ROI baru sudah aktif di memori
                  # loop ini; stop+start = model load ulang 8-15 dtk + daemon
                  # membunuh dirinya sendiri tiap ROI bergeser.
                  "no_restart": True},
            timeout=5)
        return r.status_code in (200, 201)
    except Exception:
        return False


def post_reading(api_base: str, payload: dict) -> bool:
    if requests is None:
        print("  -> gagal POST: modul 'requests' belum diinstall")
        return False
    url = api_base.rstrip("/") + "/api/cam/readings"
    try:
        r = _http_session().post(url, json=payload, timeout=8)
        if r.status_code in (200, 201):
            data = r.json()
            if data.get('status') in ('debug', 'empty', 'live'):
                return True  # gagal/0/frame-cepat: live-only, tidak masuk DB
            print(f"  -> tersimpan id={data.get('reading', {}).get('id')} "
                  f"selisih={data.get('selisih_kg')}kg ({data.get('selisih_persen')}%) "
                  f"link_mesin={data.get('linked_mesin_id')}")
            return True
        print(f"  -> API {r.status_code}: {r.text[:200]}")
        return False
    except requests.RequestException as e:
        print(f"  -> gagal POST: {e}")
        return False


def write_status(path: str, state: str, detail: str = "") -> None:
    """Tulis status diagnosa mandiri (dibaca tombol web). Anti-gagal."""
    if not path:
        return
    try:
        Path(path).write_text(json.dumps(
            {"state": state, "detail": detail,
             "updated_at": datetime.now().isoformat(timespec="seconds")},
            ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass


def write_result(path: str, **fields) -> None:
    """Tulis hasil kalibrasi (JSON) yang dipoll oleh web (mode async)."""
    if not path:
        return
    fields["updated_at"] = datetime.now().isoformat(timespec="seconds")
    try:
        tmp = f"{path}.tmp"
        Path(tmp).write_text(json.dumps(fields, ensure_ascii=False), encoding="utf-8")
        os.replace(tmp, path)
    except OSError:
        pass


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

    ap = argparse.ArgumentParser(description="Camera OCR: baca angka display timbangan dari IP cam (EasyOCR)")
    ap.add_argument("--snapshot-url", default="",
                    help="cth: http://192.168.1.50/snapshot.jpg (salah satu: snapshot-url ATAU rtsp-url; "
                         "boleh juga berupa URL stream rtsp:// / MJPEG)")
    ap.add_argument("--rtsp-url", default="",
                    help="cth: rtsp://admin:KODE_STIKER@192.168.1.64:554/Streaming/Channels/101 "
                         "(EZVIZ H3c: aktifkan dulu RTSP di aplikasi EZVIZ > Local Service Settings; "
                         "password = 6 huruf kode verifikasi di stiker kamera, BUKAN password akun EZVIZ)")
    ap.add_argument("--station", default="POS-01")
    ap.add_argument("--api-base", default="http://localhost:8000")
    ap.add_argument("--roi", type=parse_roi, default="0.35,0.30,0.30,0.20",
                    help="area display dalam frame: x,y,w,h fraksi 0-1")
    ap.add_argument("--rotate", type=int, choices=[0, 90, 180, 270], default=0,
                    help="putar frame searah jarum jam bila kamera miring (0/90/180/270)")
    ap.add_argument("--interval", type=float, default=2.0, help="detik antar jepretan")
    ap.add_argument("--save-dir", default="storage/app/public/cam",
                    help="arsip foto bukti (kosongkan dengan --no-save untuk hemat disk)")
    ap.add_argument("--no-save", action="store_true")
    ap.add_argument("--simulate", action="store_true", help="tanpa OCR beneran, angka acak 20-28kg")
    ap.add_argument("--once", action="store_true", help="sekali jepret lalu keluar (buat kalibrasi ROI)")
    ap.add_argument("--auto-roi", action="store_true",
                    help="deteksi area display otomatis sekali jalan, cetak ROI lalu keluar (buat tombol AUTO ROI)")
    ap.add_argument("--auto-roi-struct", action="store_true",
                    help="deteksi area display otomatis berbasis struktur (kontur segmen, TANPA EasyOCR) sekali jalan, cetak ROI lalu keluar — kalibrasi saat font tidak kebaca OCR")
    ap.add_argument("--try-rotate", action="store_true",
                    help="coba putar 0/90/180/270, pakai arah dgn grup desimal terbaik (kalibrasi full-mandiri)")
    ap.add_argument("--fast", action="store_true",
                    help="mode cepat 2-3 dtk: recognizer langsung di ROI tanpa deteksi (wajib ROI sudah pas)")
    ap.add_argument("--min-conf", type=float, default=0.4,
                    help="batas keyakinan 0-1: bacaan di bawah ini dibuang (default 0.4)")
    ap.add_argument("--db-every", type=float, default=2.0,
                    help="simpan bacaan ke DB tiap sekian detik (0=semua frame). "
                         "Live cache LED tetap diupdate tiap frame, jadi angka tetap "
                         "responsif tanpa membanjiri MySQL (default 2 dtk)")
    ap.add_argument("--track", action=argparse.BooleanOptionalAction, default=True,
                    help="AUTO-TRACK ROI: service mencari sendiri area display saat start, "
                         "melacaknya lagi tiap --track-every frame / saat bacaan gagal beruntun "
                         "(--no-track = perilaku lama, ROI diam)")
    ap.add_argument("--track-every", type=int, default=50,
                    help="cek ulang posisi display tiap sekian frame (default 50)")
    ap.add_argument("--track-fails", type=int, default=3,
                    help="gagal/ragu beruntun sekian kali -> cari ulang display (default 3)")
    ap.add_argument("--save-roi", action=argparse.BooleanOptionalAction, default=True,
                    help="simpan ROI hasil tracking ke Pengaturan server (maks 1x/menit, "
                         "hanya bila bergeser jauh) biar restart tidak mulai dari ROI lama")
    ap.add_argument("--perspective", action=argparse.BooleanOptionalAction, default=True,
                    help="koreksi perspektif: bila bacaan pertama gagal/ragu, warp polygon "
                         "angka jadi persegi lalu baca ulang; hasil warp dipakai hanya bila "
                         "lebih yakin (--no-perspective = matikan, perilaku lama)")
    ap.add_argument("--seven-seg", action=argparse.BooleanOptionalAction, default=True,
                    help="decoder 7-segmen + auto ROI struktur sebagai penyelamat saat EasyOCR "
                         "gagal/ragu (decoder hanya dipakai bila yakin >= 0.7; --no-seven-seg = "
                         "matikan, perilaku lama EasyOCR saja)")
    ap.add_argument("--stop-file", default="",
                    help="file pemicu berhenti (dibuat oleh tombol STOP di web); loop keluar saat file ada")
    ap.add_argument("--pid-file", default="",
                    help="tulis PID ke file ini saat start (buat tombol START/STOP web); dihapus saat berhenti bersih")
    ap.add_argument("--heartbeat-file", default="",
                    help="disentuh tiap loop sebagai tanda hidup (web baca ini, bukan tasklist)")
    ap.add_argument("--status-file", default="",
                    help="ditulis tiap ada perubahan kondisi: starting/running/degraded_model/camera_fail/stopped + penyebabnya (web tampilkan)")
    ap.add_argument("--quiet", action="store_true",
                    help="tanpa print ke console (buat daemon dari web; bacaan tetap masuk DB via API)")
    ap.add_argument("--log-file", default="",
                    help="tulis semua log ke file ini (kotak hitam daemon; diputar tiap start bila >1MB)")
    ap.add_argument("--grab-only", action="store_true",
                    help="ambil 1 frame (RTSP/MJPEG/JPEG) lalu tulis ke --out, keluar. Tanpa OCR/model (pratinjau web)")
    ap.add_argument("--out", default="", help="path file JPEG output untuk --grab-only")
    ap.add_argument("--grab-timeout", type=float, default=8.0,
                    help="detik maksimum menunggu frame untuk --grab-only")
    ap.add_argument("--input-file", default="",
                    help="frame JPEG statis untuk --auto-roi (tanpa buka RTSP lagi; dipakai kalibrasi web)")
    ap.add_argument("--result-file", default="",
                    help="tulis hasil --auto-roi ke JSON (state running/done/error) untuk polling web async")
    args = ap.parse_args()

    # Sumber frame: snapshot HTTP/MJPEG atau RTSP — wajib tepat satu.
    # --rtsp-url hanya memilih URL sumber; penanganannya tetap lewat
    # fetch_snapshot remote (deteksi stream -> _grab_stream_frame TCP).
    use_rtsp = bool(args.rtsp_url.strip())
    use_snap = bool(args.snapshot_url.strip())
    if use_rtsp == use_snap:
        ap.error("isi tepat satu: --snapshot-url ATAU --rtsp-url")
    source_url = args.rtsp_url.strip() if use_rtsp else args.snapshot_url.strip()

    # Mode GRAB-ONLY: ambil 1 frame untuk pratinjau browser (RTSP tak bisa
    # diputar browser). Tidak load EasyOCR → cepat & ringan.
    if args.grab_only:
        if not args.out:
            print("GRAB gagal: --out wajib diisi")
            return 1
        try:
            img = fetch_snapshot(source_url, timeout=args.grab_timeout)
            Path(args.out).write_bytes(img)
        except Exception as e:
            print(f"GRAB gagal: {e}")
            return 1
        print(f"GRAB ok: {args.out} ({len(img)} byte)")
        return 0

    # Mode AUTO ROI STRUKTUR: seperti --auto-roi tapi tanpa EasyOCR sama
    # sekali (tidak perlu load model 8-15 dtk). Dipakai kalibrasi saat font
    # tidak kebaca OCR: kalau mode ini menemukan ROI + angka, decoder
    # 7-segmen bekerja untuk font itu; kalau hanya ROI tanpa angka, ROI-nya
    # tetap berguna (font cetak — EasyOCR frame berikutnya yang membaca).
    if args.auto_roi_struct:
        write_result(args.result_file, state="running", detail="Mendeteksi display (struktur)...")
        if args.input_file:
            try:
                img = Path(args.input_file).read_bytes()
            except OSError as e:
                print(f"ROI struktur gagal: baca --input-file: {e}")
                write_result(args.result_file, state="error", message=f"baca frame: {e}")
                return 1
        else:
            try:
                img = fetch_snapshot(source_url)
            except Exception as e:
                print(f"ROI struktur gagal: snapshot: {e}")
                write_result(args.result_file, state="error", message=f"snapshot: {e}")
                return 1
        if _sevenseg is None:
            print("ROI struktur gagal: sevenseg.py tidak ditemukan di folder bridge/")
            write_result(args.result_file, state="error", message="sevenseg.py tidak ditemukan")
            return 3
        img = apply_rotate(img, args.rotate)
        try:
            roi, txt, cf, _ang, nd = _sevenseg.detect_roi_struct(img, search_roi=None)
        except Exception as e:
            print(f"ROI struktur gagal: {e}")
            write_result(args.result_file, state="error", message=str(e))
            return 2
        if roi is None:
            print(f"ROI struktur gagal: {txt}")
            write_result(args.result_file, state="error", message=txt or "ROI struktur gagal")
            return 2
        print(f"ROI: {roi[0]},{roi[1]},{roi[2]},{roi[3]}")
        print(f"TEXT: {txt}")
        print(f"STRUCT: {nd} digit")
        write_result(args.result_file, state="done",
                     roi=f"{roi[0]},{roi[1]},{roi[2]},{roi[3]}", text=txt, method="struct")
        return 0

    # Mode AUTO ROI: deteksi sekali, cetak, keluar. Tidak menyentuh
    # quiet/log/daemon di bawah (output harus bisa dibaca pemanggil).
    if args.auto_roi:
        # Kalau --input-file diisi: pakai frame statis yang sudah diambil
        # pemanggil (1x konek), jadi deteksi/putar TIDAK buka RTSP lagi.
        # Menghindari rebutan koneksi kamera (kamera consumer sering cuma
        # izinkan 1 sesi RTSP) → kalibrasi & pratinjau tidak saling putus.
        write_result(args.result_file, state="running", detail="Mendeteksi display...")
        if args.input_file:
            try:
                img = Path(args.input_file).read_bytes()
            except OSError as e:
                print(f"ROI gagal: baca --input-file: {e}")
                write_result(args.result_file, state="error", message=f"baca frame: {e}")
                return 1
        else:
            try:
                img = fetch_snapshot(source_url)
            except Exception as e:
                print(f"ROI gagal: snapshot: {e}")
                write_result(args.result_file, state="error", message=f"snapshot: {e}")
                return 1
        print("[CAM-OCR] Deteksi display full-frame (±10-20 dtk)...", flush=True)
        try:
            get_reader(fast=False)
        except Exception:
            import traceback
            traceback.print_exc()
            write_result(args.result_file, state="error", message="model EasyOCR gagal dimuat")
            return 3
        if args.try_rotate:
            # Coba semua arah, pemenang = skor grup desimal tertinggi.
            # Model di-load sekali di atas; tiap arah cuma infer.
            try:
                import cv2
                import numpy as np
            except ImportError:
                print("ROI gagal: opencv belum diinstall (pip install opencv-python)")
                write_result(args.result_file, state="error", message="opencv belum diinstall")
                return 3
            winner = None  # (score, deg, roi, txt)
            for deg in (0, 90, 180, 270):
                rimg = apply_rotate(img, deg)
                arr = np.frombuffer(rimg, dtype=np.uint8)
                frame = cv2.imdecode(arr, cv2.IMREAD_COLOR)
                if frame is None:
                    continue
                FH, FW = frame.shape[:2]
                try:
                    res = get_reader(fast=False).readtext(frame)
                except Exception as e:
                    print(f"[CAM-OCR] coba {deg}°: detect gagal: {e}", flush=True)
                    continue
                scored = _score_digit_groups(res, FW, FH)
                if not scored:
                    print(f"[CAM-OCR] coba {deg}°: tidak ada angka.", flush=True)
                    continue
                s = scored[0]
                print(f"[CAM-OCR] coba {deg}°: skor={s[0]:.0f} baca={s[5]!r}", flush=True)
                roi_d, txt_d = _roi_from_box(s, FW, FH)
                if winner is None or s[0] > winner[0]:
                    winner = (s[0], deg, roi_d, txt_d)
            if winner is None:
                print("ROI gagal: tidak ada angka di semua arah putar")
                write_result(args.result_file, state="error", message="tidak ada angka di semua arah putar")
                return 2
            _, deg, roi, txt = winner
            print(f"ROI: {roi[0]},{roi[1]},{roi[2]},{roi[3]}")
            print(f"TEXT: {txt}")
            print(f"ROTATE: {deg}")
            write_result(args.result_file, state="done",
                         roi=f"{roi[0]},{roi[1]},{roi[2]},{roi[3]}", text=txt, rotate=deg)
            return 0
        img = apply_rotate(img, args.rotate)
        roi, txt = detect_roi(img)
        if roi is None:
            print(f"ROI gagal: {txt}")
            write_result(args.result_file, state="error", message=txt or "ROI gagal")
            return 2
        print(f"ROI: {roi[0]},{roi[1]},{roi[2]},{roi[3]}")
        print(f"TEXT: {txt}")
        write_result(args.result_file, state="done",
                     roi=f"{roi[0]},{roi[1]},{roi[2]},{roi[3]}", text=txt)
        return 0

    # Mode daemon senyap: buang print console (pipe PHP tidak dibaca, biar
    # tidak penuh & blokir). Kalau --log-file diisi, print masuk file itu.
    # Bacaan tetap masuk DB lewat POST API.
    if args.quiet:
        try:
            if args.log_file:
                if os.path.exists(args.log_file) and os.path.getsize(args.log_file) > 1024 * 1024:
                    os.remove(args.log_file)
                # buffering=1 (line-buffered) biar kotak hitam live.
                sys.stdout = open(args.log_file, 'a', encoding='utf-8', errors='replace', buffering=1)
            else:
                sys.stdout = open(os.devnull, 'w')
            sys.stderr = sys.stdout
        except OSError:
            pass

    station = args.station.strip().upper() or "POS-01"
    save_dir = None if args.no_save else Path(args.save_dir)
    if save_dir:
        save_dir.mkdir(parents=True, exist_ok=True)

    sevenseg_ver = getattr(_sevenseg, "__version__", "-") if _sevenseg is not None else "-"
    print(f"[CAM-OCR] station={station} src={source_url} roi={args.roi} rotate={args.rotate} interval={args.interval}s fast={args.fast} perspective={args.perspective} seven_seg={args.seven_seg} sevenseg_ver={sevenseg_ver}")
    if args.seven_seg and _sevenseg is None:
        print("[CAM-OCR] PERINGATAN: decoder 7-seg tidak aktif (sevenseg.py tidak ditemukan di folder bridge/) — hanya EasyOCR yang jalan. Copy sevenseg.py ke folder bridge lalu restart service.")
    print("[CAM-OCR] Kalibrasi: jalankan --once, lihat foto crop, geser --roi sampai display pas.")
    if args.simulate:
        print("[CAM-OCR] SIMULATE: angka acak, tetap POST biar alur API ketest.")

    # Hapus stop-file sisa run sebelumnya biar tidak langsung mati.
    if args.stop_file:
        try:
            Path(args.stop_file).unlink(missing_ok=True)
        except OSError:
            pass

    # Tulis PID duluan (sebelum load model yg lama) biar tombol web
    # langsung tahu service hidup.
    if args.pid_file:
        try:
            Path(args.pid_file).write_text(str(os.getpid()))
        except OSError as e:
            print(f"[CAM-OCR] pid-file gagal: {e}")

    write_status(args.status_file, "starting", "Menyiapkan service...")
    fail_streak = 0
    last_db_post = 0.0
    # Bacaan terakhir buat konsistensi temporal: satu frame conf rendah tetap
    # diterima KALAU frame sebelumnya membaca angka yang sama (±0.30 kg).
    # Lebih cepat & tetap akurat: misread acak tidak akan dua kali kebetulan sama.
    recent_ok: list[tuple[float, float]] = []
    CAMERA_FAIL_AFTER = 5
    MODEL_RETRY_SEC = 60.0

    # Panaskan model sekali di depan (load ~15-20 dtk), biar frame
    # berikutnya tinggal infer 2-3 dtk. Tanpa ini frame pertama lambat.
    # GAGAL LOAD MODEL = JANGAN MATI (dulu: return 3 -> pill balik START):
    # masuk mode tunggu, heartbeat tetap jalan, retry tiap 60 dtk.
    reader_ok = bool(args.simulate)
    model_retry_at = 0.0
    if not args.simulate:
        write_status(args.status_file, "loading_model", "Memuat model EasyOCR (±20 dtk)...")
        print("[CAM-OCR] Memuat model EasyOCR...", flush=True)
        t0 = time.time()
        try:
            reader_ok = get_reader(fast=args.fast) is not None
            if not reader_ok:
                raise RuntimeError("easyocr belum diinstall (pip install easyocr)")
        except Exception as e:
            import traceback
            traceback.print_exc()
            reader_ok = False
            model_retry_at = time.time() + MODEL_RETRY_SEC
            print(f"[CAM-OCR] Model GAGAL dimuat: {e} -> mode tunggu, retry otomatis 60 dtk.", flush=True)
            write_status(args.status_file, "degraded_model",
                         f"Model EasyOCR gagal dimuat: {e}. Service tetap hidup, retry otomatis 60 dtk. "
                         "Cek internet (download model pertama) / pip install easyocr.")
        if reader_ok:
            print(f"[CAM-OCR] Model siap ({time.time() - t0:.1f}s).", flush=True)
            write_status(args.status_file, "running", "Model siap, OCR jalan.")

    # --- AUTO-TRACK ROI (state, port lokal) ---
    # roi_cur = ROI kerja yang bisa bergerak sendiri; args.roi cuma tebakan
    # awal. need_track=True -> frame pertama langsung cari display sendiri,
    # operator tidak perlu setting ROI dulu. Blok ini hanya dipakai di loop
    # daemon (kondisi tracking di bawah mengecualikan --once/--simulate).
    roi_cur = tuple(float(v) for v in args.roi)
    roi_saved = roi_cur
    roi_angle = 0.0  # kemiringan teks terakhir dari tracking (derajat)
    ocr_fail_streak = 0
    frames_since_track = 0
    need_track = bool(args.track)
    last_roi_save = 0.0
    if args.track and not args.simulate:
        print("[CAM-OCR] AUTO-TRACK ROI aktif: display dicari sendiri saat start, "
              f"dicek ulang tiap {args.track_every} frame / {args.track_fails}x gagal.")

    while True:
        if args.stop_file and Path(args.stop_file).exists():
            print("[CAM-OCR] Stop diminta dari web, berhenti.")
            release_stream_captures()
            write_status(args.status_file, "stopped", "Berhenti atas permintaan.")
            if args.pid_file:
                try:
                    Path(args.pid_file).unlink(missing_ok=True)
                except OSError:
                    pass
            return 0
        # Detak jantung buat tombol web (murah, tiap loop).
        if args.heartbeat_file:
            try:
                Path(args.heartbeat_file).touch()
            except OSError:
                pass
        # Mode tunggu model: tetap hidup + heartbeat, coba lagi tiap 60 dtk.
        if not reader_ok and not args.simulate:
            if time.time() >= model_retry_at:
                try:
                    if get_reader(fast=args.fast) is not None:
                        reader_ok = True
                        print("[CAM-OCR] Model siap (retry berhasil).", flush=True)
                        write_status(args.status_file, "running", "Model siap, OCR jalan.")
                    else:
                        raise RuntimeError("easyocr belum diinstall (pip install easyocr)")
                except Exception as e:
                    model_retry_at = time.time() + MODEL_RETRY_SEC
                    write_status(args.status_file, "degraded_model",
                                 f"Model belum siap: {e}. Retry otomatis 60 dtk.")
            time.sleep(min(max(args.interval, 2.0), 10.0))
            continue
        t_frame = time.time()  # pacing interval dihitung dari AWAL frame
        try:
            img = apply_rotate(fetch_snapshot(source_url), args.rotate)
            if fail_streak >= CAMERA_FAIL_AFTER:
                write_status(args.status_file, "running", "Kamera kembali terhubung.")
            fail_streak = 0
        except Exception as e:
            fail_streak += 1
            print(f"[{datetime.now():%H:%M:%S}] snapshot gagal ({fail_streak}x): {e}")
            if fail_streak == CAMERA_FAIL_AFTER or fail_streak % 30 == 0:
                write_status(args.status_file, "camera_fail",
                             f"Snapshot gagal {fail_streak}x: {e}. URL={source_url}. "
                             "Cek: kamera nyala + satu LAN/WiFi, URL benar, dan (EZVIZ) RTSP sudah "
                             "diaktifkan di app (LAN Live View → Local Server Settings); "
                             "password RTSP = kode verifikasi di stiker, bukan password akun.")
            if args.once:
                return 1
            time.sleep(max(args.interval, 2.0))
            continue

        weighed_at = datetime.now(timezone.utc).isoformat()

        # AUTO-TRACK: cari/lacak ulang area display dalam frame INI juga
        # (frame yang sama lanjut dipakai OCR dengan ROI baru). Jalan saat:
        # start (need_track), berkala (track_every), atau bacaan gagal
        # beruntun (track_fails). Dicari dulu di jendela sekitar ROI lama
        # (cepat), gagal -> full-frame. Hasilnya dihaluskan (smooth) dan
        # disimpan balik ke server bila bergeser jauh (maks 1x/menit).
        # TIDAK jalan di --once/--simulate (dan frame ini sudah diputar
        # --rotate dulu, jadi tracking memakai koordinat gambar tegak).
        # PERCEPAT (aman): tracking dan retry perspektif JANGAN jalan di
        # frame yang sama. Tracking sudah melakukan deteksi full (jendela
        # luas lalu full-frame); retry perspektif akan menambah deteksi
        # full kedua di frame yang sama — inilah yang bikin satu frame
        # busuk lama tanpa posting baru. Perspektif ditunda ke frame
        # berikut, saat tracking tidak jalan lagi (fail streak dan
        # frames_since_track direset di bawah). Ambang conf dan konsistensi
        # temporal TIDAK diubah, jadi akurasi tidak dikorbankan.
        did_track = False
        if (args.track and not args.simulate and not args.once and reader_ok
                and (need_track or frames_since_track >= max(5, args.track_every)
                     or ocr_fail_streak >= max(1, args.track_fails))):
            did_track = True
            struct_ok = False
            try:
                found, ftxt, _fcf, fang = detect_roi_in_frame(img, search_roi=roi_expand(roi_cur, 0.75))
                if found is None or not tracking_text_ok(ftxt):
                    found, ftxt, _fcf, fang = detect_roi_in_frame(img, search_roi=None)
                # Fallback STRUKTUR (tanpa OCR): kalau EasyOCR tidak menemukan
                # display — persis kasus font tidak kebaca — cari dari kontur
                # segmen. ROI struktur diterima walau decoder belum membaca
                # angkanya (font cetak): EasyOCR frame berikutnya yang akan
                # membacanya dari ROI baru yang sudah pas. Sudut teks TIDAK
                # diubah oleh jalur ini (tidak ada info sudut dari kontur).
                if (found is None or not tracking_text_ok(ftxt)) and args.seven_seg and _sevenseg is not None:
                    s_roi, s_txt, _s_cf, _s_ang, s_nd = _sevenseg.detect_roi_struct(img, search_roi=roi_expand(roi_cur, 0.75))
                    if s_roi is None:
                        s_roi, s_txt, _s_cf, _s_ang, s_nd = _sevenseg.detect_roi_struct(img, search_roi=None)
                    if s_roi is not None:
                        found, ftxt, struct_ok = s_roi, (s_txt if s_txt else f"struktur:{s_nd}digit"), True
                        print(f"[CAM-OCR] ROI track (struktur, tanpa OCR): {s_roi} baca: {ftxt!r}", flush=True)
                # Satu digit acak ('1','3','8') bukan display timbangan:
                # jangan pindahkan ROI (lihat tracking_text_ok). Pengecualian:
                # ROI struktur sudah lolos seleksi grup digit di atas.
                if found is not None and not (tracking_text_ok(ftxt) or struct_ok):
                    found = None
                if found is not None:
                    smoothed = roi_smooth(roi_cur, found)
                    if not struct_ok:
                        roi_angle = roi_angle + (fang - roi_angle) * 0.6
                    if roi_changed_enough(roi_cur, smoothed):
                        print(f"[CAM-OCR] ROI track: {roi_cur} -> {smoothed} miring {roi_angle:.1f}° (baca: {ftxt!r})", flush=True)
                    roi_cur = smoothed
                    if (args.save_roi and roi_changed_enough(roi_saved, roi_cur)
                            and time.time() - last_roi_save > 60):
                        if post_roi_save(args.api_base, station, roi_cur):
                            roi_saved = roi_cur
                            last_roi_save = time.time()
                else:
                    print(f"[CAM-OCR] ROI track: display tidak ketemu ({ftxt}) — pakai ROI terakhir.", flush=True)
            except Exception as e:
                print(f"[CAM-OCR] ROI track error (lanjut ROI terakhir): {e}", flush=True)
            need_track = False
            frames_since_track = 0
            ocr_fail_streak = 0

        # Satu frame busuk JANGAN PERNAH membunuh daemon (kasus 22 Sep:
        # ValueError dari parser → proses mati → pill "BERHENTI").
        try:
            if args.simulate:
                import random
                weight, conf, raw = round(20 + random.random() * 8, 2), 0.99, "SIMULATE"
            else:
                t0 = time.time()
                weight, conf, raw = ocr_weight(img, roi_cur, fast=args.fast, angle=roi_angle, persp=(args.perspective and not did_track), seven_seg=args.seven_seg)
                dt = time.time() - t0
            frames_since_track += 1

            # RESCUE STRUKTUR di frame yang sama (2026-10-25): kalau OCR +
            # decoder di ROI lama sama-sama gagal, jangan tunggu siklus
            # tracking berikutnya — cari display dari struktur di frame
            # INI juga, baca dari ROI baru itu, dan pindahkan ROI kerja
            # ke sana (dihaluskan seperti tracking) biar frame berikutnya
            # langsung pakai area baru. Murah (kontur, tanpa model), jadi
            # boleh jalan tiap frame gagal. Kalau ROI struktur ketemu tapi
            # decode-nya gagal, ROI tetap dipindah (frame berikutnya
            # EasyOCR/decoder mencoba dari ROI baru yang sudah pas).
            if weight is None and not args.simulate and args.seven_seg and _sevenseg is not None:
                try:
                    s_roi, s_txt, _s_cf, _s_ang, s_nd = _sevenseg.detect_roi_struct(img, search_roi=roi_expand(roi_cur, 0.75))
                    if s_roi is None:
                        s_roi, s_txt, _s_cf, _s_ang, s_nd = _sevenseg.detect_roi_struct(img, search_roi=None)
                    if s_roi is not None:
                        new_roi = roi_smooth(roi_cur, s_roi)
                        if roi_changed_enough(roi_cur, new_roi):
                            print(f"[CAM-OCR] ROI rescue (struktur): {roi_cur} -> {new_roi} (baca: {s_txt!r})", flush=True)
                        roi_cur = new_roi
                        if (args.save_roi and roi_changed_enough(roi_saved, roi_cur)
                                and time.time() - last_roi_save > 60):
                            if post_roi_save(args.api_base, station, roi_cur):
                                roi_saved = roi_cur
                                last_roi_save = time.time()
                        try:
                            d_val, d_cf, d_raw, d_detail = _sevenseg.decode_roi(img, s_roi, angle=roi_angle)
                        except Exception:
                            d_val = None
                        if d_val is not None:
                            weight, conf, raw = d_val, d_cf, f"{d_val:.2f} [7seg-struct]"
                            print(f"[CAM-OCR] 7seg-struct rescue: {d_raw} kg (conf {d_cf:.2f}) {d_detail}", flush=True)
                except Exception as e:
                    print(f"[CAM-OCR] ROI rescue error (lanjut): {e}", flush=True)

            ts = datetime.now().strftime("%Y%m%d_%H%M%S")
            image_path = None
            if save_dir and weight is not None and weight > 0:
                fname = f"{station}_{ts}_{weight:.2f}kg.jpg"
                (save_dir / fname).write_bytes(img)
                image_path = f"cam/{fname}"

            # Konsistensi temporal (hanya untuk bacaan valid).
            agree = False
            if weight is not None:
                now_ts = time.time()
                recent_ok = [(t, w) for (t, w) in recent_ok if now_ts - t <= 2.0]
                agree = any(abs(w - weight) <= 0.30 for _t, w in recent_ok)
                recent_ok.append((now_ts, weight))
                if len(recent_ok) > 20:
                    recent_ok = recent_ok[-20:]

            if weight is None:
                ocr_fail_streak += 1
                print(f"[{ts}] OCR gagal (raw={raw[:80]!r}). Cek --roi / cahaya / fokus.")
                # Tetap lapor ke server (tanpa ocr_weight) supaya frontend bisa
                # tampilkan "kamera lihat: ..." buat debug ROI. Server TIDAK
                # menyimpan ke DB, cuma cache 60 detik.
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "ocr_weight": None,
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(roi_cur), "model": "easyocr-en", "fast": args.fast},
                })
            elif not args.simulate and conf < args.min_conf and not agree:
                ocr_fail_streak += 1
                # Keyakinan rendah DAN tidak ada bacaan serupa di frame
                # sebelumnya: buang. Kalau dua frame berturut-turut membaca
                # angka yang sama, tetap dipakai (lihat `agree` di atas).
                print(f"[{ts}] OCR ragu (conf {conf:.2f}, raw={raw[:80]!r}) — dibuang.")
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "ocr_weight": None,
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(roi_cur), "model": "easyocr-en", "fast": args.fast},
                })
            else:
                ocr_fail_streak = 0
                extra = "" if args.simulate else f" {dt:.1f}s"
                print(f"[{ts}][{station}] OCR: {weight:.2f} kg (conf {conf:.2f}){extra} raw={raw[:60]!r}")
                # Live cache SELALU diupdate (LED cepat); insert DB dibatasi
                # --db-every detik biar frame cepat tidak membanjiri MySQL.
                persist_now = args.db_every <= 0 or (time.time() - last_db_post) >= args.db_every
                if persist_now:
                    last_db_post = time.time()
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "image_path": image_path,
                    "ocr_weight": weight,
                    "ocr_conf": round(float(conf), 3),
                    "persist": persist_now,
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(roi_cur), "model": "easyocr-en", "fast": args.fast},
                })
        except Exception as e:
            print(f"[{datetime.now():%H:%M:%S}] frame error (lanjut): {e}")
            weight = None

        if args.once:
            return 0 if weight is not None else 2
        # Ritme interval dihitung dari AWAL frame: sisa waktu saja yang
        # ditidurkan. Dulu sleep penuh setelah infer -> siklus selalu
        # (infer 2-3 dtk + interval), sekarang totalnya ~interval
        # (atau secepat infer selesai bila infer > interval).
        time.sleep(max(0.0, args.interval - (time.time() - t_frame)))


if __name__ == "__main__":
    raise SystemExit(main())
