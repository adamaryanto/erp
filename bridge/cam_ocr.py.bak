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


def parse_roi(s: str) -> tuple[float, float, float, float]:
    """ROI sebagai fraksi gambar: 'x,y,w,h' masing-masing 0-1."""
    try:
        x, y, w, h = (float(v) for v in s.split(","))
    except ValueError:
        raise argparse.ArgumentTypeError("--roi format: x,y,w,h (contoh 0.35,0.30,0.30,0.20)")
    if not (0 <= x < 1 and 0 <= y < 1 and 0 < w <= 1 and 0 < h <= 1):
        raise argparse.ArgumentTypeError("--roi harus fraksi 0-1")
    return (x, y, w, h)


def fetch_snapshot(url: str, timeout: float = 8.0) -> bytes:
    if requests is None:
        raise RuntimeError("modul 'requests' belum diinstall — jalankan: pip install requests opencv-python numpy easyocr")
    r = requests.get(url, timeout=timeout)
    r.raise_for_status()
    if len(r.content) < 5_000:
        raise RuntimeError(f"snapshot terlalu kecil ({len(r.content)} byte), URL mungkin salah")
    return r.content


def preprocess_for_ocr(img_bytes: bytes, roi: tuple[float, float, float, float], scale: float = 2.5):
    """Crop ROI display + perbesar + threshold. Butuh opencv+numpy (opsional)."""
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
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    gray = cv2.GaussianBlur(gray, (3, 3), 0)
    _, th = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
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
            # Nol = timbangan kosong: LAPOR (biar LED ikut 0), server yang
            # memutuskan live-only tanpa masuk DB.
            if 0 <= v < 200 and (best is None or cf > best[1]):
                best = (v, cf)
    if best is None:
        m = re.findall(r"(\d+\.\d+)", raw_all.replace(",", "."))
        if m:
            try:
                return float(m[-1]), 0.3, raw_all
            except ValueError:
                pass
        return None, 0.0, raw_all
    return best[0], best[1], raw_all


def ocr_weight_fast(img_bytes: bytes, roi) -> tuple[float | None, float, str]:
    """Jalur cepat: crop ROI -> recognizer langsung (tanpa deteksi)."""
    reader = get_reader(fast=True)
    if reader is None:
        return None, 0.0, "easyocr belum diinstall (pip install easyocr)"
    crop = preprocess_for_ocr(img_bytes, roi, scale=1.0)
    if crop is None:
        return None, 0.0, "gagal crop (opencv hilang?)"
    try:
        texts = reader.recognize(crop)
    except Exception as e:
        return None, 0.0, f"recognize gagal: {e}"
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
    return pick_number(pairs)


def ocr_weight(img_bytes: bytes, roi, fast: bool = False) -> tuple[float | None, float, str]:
    """Kembalikan (angka_kg, confidence 0-1, raw_text).

    fast=True: recognizer langsung di crop ROI (2-3 dtk, buat service).
    fast=False: deteksi dulu baru baca (lebih teliti, buat kalibrasi --once).
    """
    if fast:
        return ocr_weight_fast(img_bytes, roi)

    reader = get_reader(fast=False)
    if reader is None:
        return None, 0.0, "easyocr belum diinstall (pip install easyocr)"

    processed = preprocess_for_ocr(img_bytes, roi)

    if processed is not None:
        results = reader.readtext(processed)
    else:
        # fallback tanpa opencv: tulis temp file lalu baca
        tmp = Path("storage/app/cam_tmp.jpg")
        tmp.parent.mkdir(parents=True, exist_ok=True)
        tmp.write_bytes(img_bytes)
        results = reader.readtext(str(tmp))

    pairs = [(str(t), float(c)) for _, t, c in results]
    return pick_number(pairs)


def detect_roi(img_bytes: bytes) -> tuple[tuple[float, float, float, float] | None, str]:
    """Auto-ROI sekali jalan: full-frame readtext -> pilih box angka terbesar.

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
            best = (score, x0, y0, x1, y1, text)
    if best is None:
        seen = " | ".join(str(t) for _, t, _ in (results or [])[:6]) or "-"
        return None, f"tidak ada angka terdeteksi (kelihatan: {seen[:80]})"
    _, x0, y0, x1, y1, txt = best
    m = 0.15
    bw, bh = max(1.0, x1 - x0), max(1.0, y1 - y0)
    rx0 = max(0.0, (x0 - bw * m) / W)
    ry0 = max(0.0, (y0 - bh * m) / H)
    rx1 = min(float(W), x1 + bw * m) / W
    ry1 = min(float(H), y1 + bh * m) / H
    return (round(rx0, 3), round(ry0, 3), round(rx1 - rx0, 3), round(ry1 - ry0, 3)), txt


def post_reading(api_base: str, payload: dict) -> bool:
    if requests is None:
        print("  -> gagal POST: modul 'requests' belum diinstall")
        return False
    url = api_base.rstrip("/") + "/api/cam/readings"
    try:
        r = requests.post(url, json=payload, timeout=8)
        if r.status_code in (200, 201):
            data = r.json()
            if data.get('status') in ('debug', 'empty'):
                return True  # gagal/0: teks live-only, tidak masuk DB
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


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

    ap = argparse.ArgumentParser(description="Camera OCR: baca angka display timbangan dari IP cam (EasyOCR)")
    ap.add_argument("--snapshot-url", required=True, help="cth: http://192.168.1.50/snapshot.jpg")
    ap.add_argument("--station", default="POS-01")
    ap.add_argument("--api-base", default="http://localhost:8000")
    ap.add_argument("--roi", type=parse_roi, default="0.35,0.30,0.30,0.20",
                    help="area display dalam frame: x,y,w,h fraksi 0-1")
    ap.add_argument("--interval", type=float, default=2.0, help="detik antar jepretan")
    ap.add_argument("--save-dir", default="storage/app/public/cam",
                    help="arsip foto bukti (kosongkan dengan --no-save untuk hemat disk)")
    ap.add_argument("--no-save", action="store_true")
    ap.add_argument("--simulate", action="store_true", help="tanpa OCR beneran, angka acak 20-28kg")
    ap.add_argument("--once", action="store_true", help="sekali jepret lalu keluar (buat kalibrasi ROI)")
    ap.add_argument("--auto-roi", action="store_true",
                    help="deteksi area display otomatis sekali jalan, cetak ROI lalu keluar (buat tombol AUTO ROI)")
    ap.add_argument("--fast", action="store_true",
                    help="mode cepat 2-3 dtk: recognizer langsung di ROI tanpa deteksi (wajib ROI sudah pas)")
    ap.add_argument("--min-conf", type=float, default=0.4,
                    help="batas keyakinan 0-1: bacaan di bawah ini dibuang (default 0.4)")
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
    args = ap.parse_args()

    # Mode AUTO ROI: deteksi sekali, cetak, keluar. Tidak menyentuh
    # quiet/log/daemon di bawah (output harus bisa dibaca pemanggil).
    if args.auto_roi:
        try:
            img = fetch_snapshot(args.snapshot_url)
        except Exception as e:
            print(f"ROI gagal: snapshot: {e}")
            return 1
        print("[CAM-OCR] Deteksi display full-frame (±10-20 dtk)...", flush=True)
        try:
            get_reader(fast=False)
        except Exception:
            import traceback
            traceback.print_exc()
            return 3
        roi, txt = detect_roi(img)
        if roi is None:
            print(f"ROI gagal: {txt}")
            return 2
        print(f"ROI: {roi[0]},{roi[1]},{roi[2]},{roi[3]}")
        print(f"TEXT: {txt}")
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

    print(f"[CAM-OCR] station={station} src={args.snapshot_url} roi={args.roi} interval={args.interval}s fast={args.fast}")
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

    while True:
        if args.stop_file and Path(args.stop_file).exists():
            print("[CAM-OCR] Stop diminta dari web, berhenti.")
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
        try:
            img = fetch_snapshot(args.snapshot_url)
            if fail_streak >= CAMERA_FAIL_AFTER:
                write_status(args.status_file, "running", "Kamera kembali terhubung.")
            fail_streak = 0
        except Exception as e:
            fail_streak += 1
            print(f"[{datetime.now():%H:%M:%S}] snapshot gagal ({fail_streak}x): {e}")
            if fail_streak == CAMERA_FAIL_AFTER or fail_streak % 30 == 0:
                write_status(args.status_file, "camera_fail",
                             f"Snapshot gagal {fail_streak}x: {e}. URL={args.snapshot_url}. "
                             "Kalau EZVIZ: tidak ada /videofeed — aktifkan RTSP / pakai IP Webcam HP.")
            if args.once:
                return 1
            time.sleep(max(args.interval, 2.0))
            continue

        weighed_at = datetime.now(timezone.utc).isoformat()

        # Satu frame busuk JANGAN PERNAH membunuh daemon (kasus 22 Sep:
        # ValueError dari parser → proses mati → pill "BERHENTI").
        try:
            if args.simulate:
                import random
                weight, conf, raw = round(20 + random.random() * 8, 2), 0.99, "SIMULATE"
            else:
                t0 = time.time()
                weight, conf, raw = ocr_weight(img, args.roi, fast=args.fast)
                dt = time.time() - t0

            ts = datetime.now().strftime("%Y%m%d_%H%M%S")
            image_path = None
            if save_dir and weight is not None and weight > 0:
                fname = f"{station}_{ts}_{weight:.2f}kg.jpg"
                (save_dir / fname).write_bytes(img)
                image_path = f"cam/{fname}"

            if weight is None:
                print(f"[{ts}] OCR gagal (raw={raw[:80]!r}). Cek --roi / cahaya / fokus.")
                # Tetap lapor ke server (tanpa ocr_weight) supaya frontend bisa
                # tampilkan "kamera lihat: ..." buat debug ROI. Server TIDAK
                # menyimpan ke DB, cuma cache 60 detik.
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "ocr_weight": None,
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(args.roi), "model": "easyocr-en", "fast": args.fast},
                })
            elif not args.simulate and conf < args.min_conf:
                # Keyakinan rendah: JANGAN masuk DB, anggap gagal. Sampling +
                # LED pakai bacaan yakin saja. Ambang diatur via Settings.
                print(f"[{ts}] OCR ragu (conf {conf:.2f}, raw={raw[:80]!r}) — dibuang.")
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "ocr_weight": None,
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(args.roi), "model": "easyocr-en", "fast": args.fast},
                })
            else:
                extra = "" if args.simulate else f" {dt:.1f}s"
                print(f"[{ts}][{station}] OCR: {weight:.2f} kg (conf {conf:.2f}){extra} raw={raw[:60]!r}")
                post_reading(args.api_base, {
                    "station": station,
                    "weighed_at": weighed_at,
                    "image_path": image_path,
                    "ocr_weight": weight,
                    "ocr_conf": round(float(conf), 3),
                    "raw_payload": {"ocr_raw": raw[:200], "roi": list(args.roi), "model": "easyocr-en", "fast": args.fast},
                })
        except Exception as e:
            print(f"[{datetime.now():%H:%M:%S}] frame error (lanjut): {e}")
            weight = None

        if args.once:
            return 0 if weight is not None else 2
        # Interval dihitung dari selesai infer -> jeda tambahan biar ritme ~interval.
        time.sleep(args.interval)


if __name__ == "__main__":
    raise SystemExit(main())
