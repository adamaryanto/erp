#!/usr/bin/env python3
"""Diagnosa sevenseg STANDALONE (tanpa EasyOCR, tanpa service, tanpa model).

Tujuan: menghentikan tebak-tebakan kenapa cam OCR tidak bisa baca sevenseg.
Script ini mengambil SATU frame dari kamera asli, lalu menjalankan decoder
sevenseg yang SAMA dengan yang dipakai service, dan mencetak verdict yang
jelas untuk tiap tahap. Dijalankan sekali, outputnya dikirim ke Muse.

Sumber frame (pilih SATU):
  --snapshot-url "http://..."   : URL snapshot HTTP kamera
  --rtsp-url "rtsp://..."       : URL RTSP kamera (butuh cv2)
  --file frame.jpg              : file JPEG frame yang sudah disimpan

Tes tambahan (opsional):
  --roi "x,y,w,h"               : uji ROI service yang sekarang (fraksi
                                  0-1, mis. "0.30,0.25,0.40,0.35"). Kalau
                                  tidak tahu ROI-nya, kosongkan saja.

Yang dicetak:
  1. Versi sevenseg.py yang dipakai (membuktikan file di bridge/ ini v3
     atau masih versi lama — kalau versi lama, berarti file v3 belum
     pernah dicopy ke folder ini).
  2. Hasil cari ROI dari struktur (tanpa OCR).
  3. Hasil decode di ROI struktur itu.
  4. Hasil decode di full frame.
  5. Hasil decode di ROI service (kalau --roi diisi).
  6. KESIMPULAN: tahap mana yang gagal, dan apa artinya.

Jalankan dari folder Project-Erp (agar sevenseg.py yang diuji adalah file
yang sama dengan yang dipakai service):
  python bridge\\diag_sevenseg.py --rtsp-url "rtsp://admin:KODE@IP:554/Streaming/Channels/101"
"""
import argparse
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))

try:
    import sevenseg
except Exception as e:  # noqa: BLE001 - diagnosa harus tetap jalan
    print(f"GAGAL import sevenseg.py dari {HERE}: {e}")
    print("Pastikan script ini ada di folder bridge/ bersama sevenseg.py.")
    sys.exit(2)


def get_frame_bytes(args):
    """Ambil satu frame -> bytes JPEG, atau (None, alasan)."""
    if args.file:
        p = Path(args.file)
        if not p.exists():
            return None, f"file tidak ada: {args.file}"
        data = p.read_bytes()
        if not data:
            return None, "file kosong"
        return data, ""
    if args.snapshot_url:
        try:
            import urllib.request
            req = urllib.request.Request(args.snapshot_url, headers={"User-Agent": "diag-sevenseg"})
            with urllib.request.urlopen(req, timeout=15) as r:
                data = r.read()
            if not data:
                return None, "snapshot kosong (0 byte)"
            return data, ""
        except Exception as e:  # noqa: BLE001
            return None, f"snapshot gagal: {e}"
    if args.rtsp_url:
        try:
            import cv2
        except ImportError:
            return None, "rtsp butuh cv2 (opencv belum diinstall)"
        try:
            cap = cv2.VideoCapture(args.rtsp_url)
            ok, frame = cap.read()
            cap.release()
            if not ok or frame is None:
                return None, "rtsp: tidak dapat frame (cek URL/IP/kode kamera)"
            ok2, buf = cv2.imencode(".jpg", frame)
            if not ok2:
                return None, "rtsp: gagal encode JPEG"
            return buf.tobytes(), ""
        except Exception as e:  # noqa: BLE001
            return None, f"rtsp gagal: {e}"
    return None, "tidak ada sumber frame (isi --snapshot-url / --rtsp-url / --file)"


def parse_roi(text):
    try:
        x, y, w, h = (float(v) for v in text.split(","))
        if not (0 <= x < 1 and 0 <= y < 1 and 0 < w <= 1 and 0 < h <= 1):
            return None
        return (x, y, w, h)
    except Exception:  # noqa: BLE001
        return None


def main():
    ap = argparse.ArgumentParser(description="Diagnosa sevenseg standalone.")
    ap.add_argument("--snapshot-url", default="")
    ap.add_argument("--rtsp-url", default="")
    ap.add_argument("--file", default="")
    ap.add_argument("--roi", default="", help='ROI service, format "x,y,w,h" fraksi 0-1')
    args = ap.parse_args()

    print("=== DIAG SEVENSEG ===")
    print(f"sevenseg.py: {HERE / 'sevenseg.py'}")
    print(f"sevenseg_ver: {getattr(sevenseg, '__version__', '(tidak ada __version__ = versi lama)')}")
    print()

    frame, err = get_frame_bytes(args)
    if frame is None:
        print(f"FRAME GAGAL: {err}")
        return 2
    print(f"frame: {len(frame)} byte (JPEG)")
    print()

    results = {}

    # 1. Cari ROI dari struktur (tanpa OCR).
    try:
        roi, txt, cf, _ang, nd = sevenseg.detect_roi_struct(frame, search_roi=None)
        print(f"[1] struktur full-frame: roi={roi} teks={txt!r} conf={cf:.2f} digit={nd}")
        results["struct"] = roi
    except Exception as e:  # noqa: BLE001
        print(f"[1] struktur full-frame: ERROR {e}")
        results["struct"] = None

    # 2. Decode di ROI struktur (kalau ketemu).
    if results["struct"]:
        try:
            val, cf, raw, detail = sevenseg.decode_roi(frame, results["struct"], angle=0.0)
            print(f"[2] decode @ROI-struktur: val={val} conf={cf:.2f} raw={raw!r} detail={detail}")
            results["struct_decode"] = val
        except Exception as e:  # noqa: BLE001
            print(f"[2] decode @ROI-struktur: ERROR {e}")
            results["struct_decode"] = None
    else:
        print("[2] decode @ROI-struktur: dilewati (struktur tidak ketemu)")
        results["struct_decode"] = None

    # 3. Decode di full frame.
    try:
        val, cf, raw, detail = sevenseg.decode_roi(frame, (0.0, 0.0, 1.0, 1.0), angle=0.0)
        print(f"[3] decode @full-frame: val={val} conf={cf:.2f} raw={raw!r} detail={detail}")
        results["full_decode"] = val
    except Exception as e:  # noqa: BLE001
        print(f"[3] decode @full-frame: ERROR {e}")
        results["full_decode"] = None

    # 4. Decode di ROI service (kalau diisi).
    if args.roi:
        roi = parse_roi(args.roi)
        if roi is None:
            print(f"[4] decode @ROI-service: format ROI salah: {args.roi!r} (pakai x,y,w,h fraksi)")
        else:
            try:
                val, cf, raw, detail = sevenseg.decode_roi(frame, roi, angle=0.0)
                print(f"[4] decode @ROI-service {roi}: val={val} conf={cf:.2f} raw={raw!r} detail={detail}")
                results["roi_decode"] = val
            except Exception as e:  # noqa: BLE001
                print(f"[4] decode @ROI-service: ERROR {e}")
                results["roi_decode"] = None
    print()
    print("=== KESIMPULAN ===")
    # Kasus khusus: cv2 tidak ada di Python ini — bukan salah frame.
    # (Di VM Muse ini normal; di PC harus pakai Python Laragon yang sama
    # dengan service, karena Python itu yang punya cv2 + EasyOCR.)
    try:
        import cv2  # noqa: F401
    except ImportError:
        print("CV2 TIDAK ADA di Python ini. Script tidak bisa menguji apa-apa.")
        print("Jalankan lagi dengan Python Laragon yang dipakai service, mis.:")
        print("  C:\\laragon\\bin\\python\\python-3.10\\python.exe bridge\\diag_sevenseg.py ...")
        return 3
    if results.get("struct_decode") is not None or results.get("full_decode") is not None:
        print("DECODER BISA BACA frame ini. Kalau service tetap gagal, masalahnya")
        print("bukan di decoder, melainkan di service: file sevenseg.py di bridge/")
        print("belum v3, service belum restart, atau ROI service salah.")
        if args.roi and results.get("roi_decode") is None:
            print("Bukti tambahan: decode di ROI service GAGAL, tapi di ROI struktur")
            print("atau full-frame BERHASIL -> ROI service yang salah, ganti ROI.")
    elif results.get("struct") is not None:
        print("Struktur ketemu ROI, tapi decode gagal di semua percobaan.")
        print("Kirim detail di atas (baris [2] dan [3]) — dari situ ketahuan")
        print("apakah digit ambigu, titik hilang, atau grup tidak cocok.")
    else:
        print("Struktur TIDAK ketemu display di frame ini. Kemungkinan: frame")
        print("gelap/blur, kamera tidak mengarah ke display, atau display")
        print("terlalu kecil di frame. Kirim output ini + screenshot preview.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
