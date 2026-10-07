#!/usr/bin/env python3
"""
ERP Timbang Ayam Hidup - Serial Port Bridge Reader
Reads weight data from Balanza BX1 (Baykon) scale via RS-232C
Sends data to Laravel API via HTTP POST
"""

import serial
import re
import time
import requests
import json
import sys
import argparse
from datetime import datetime
from typing import Optional, Dict, Any

try:
    from serial.tools import list_ports
except ImportError:  # pragma: no cover
    list_ports = None

class WeightReader:
    def __init__(
        self,
        com_port: str = "AUTO",
        baud_rate: int = 9600,
        parity: str = "N",
        parsing_regex: str = r'/STX\s+([\d.]+)\s+kg/',
        api_url: str = "http://localhost:8000/api/weighings/live",
        api_token: Optional[str] = None,
        simulate: bool = False,
        station: str = "POS-01",
    ):
        self.com_port = com_port
        self.baud_rate = baud_rate
        self.parity = parity
        self.parsing_regex = parsing_regex
        self.api_url = api_url
        self.api_token = api_token
        self.simulate = simulate
        self.station = station.strip().upper() or "POS-01"
        self.serial_conn: Optional[serial.Serial] = None
        self.running = False

    def parse_regex(self) -> re.Pattern:
        """Convert string regex to compiled pattern."""
        # Remove leading/trailing slashes if present
        pattern = self.parsing_regex.strip('/')
        return re.compile(pattern)

    @staticmethod
    def list_serial_ports() -> list:
        """Daftar semua port serial yang terlihat OS (butuh driver kepasang)."""
        if list_ports is None:
            return []
        return [p.device for p in list_ports.comports()]

    def auto_detect_port(self, timeout_per_port: float = 2.0) -> Optional[str]:
        """Scan semua port, pilih yang mengalirkan data mirip timbangan.

        Tiap port dibuka 9600 8N1, didengarkan sebentar: kalau ada baris
        mengandung angka desimal -> skor +1. Port skor tertinggi menang.
        """
        ports = self.list_serial_ports()
        if not ports:
            print("[AUTO] Tidak ada port serial terdeteksi.")
            print("[AUTO] Pastikan kabel USB-RS232 NYOLOK + driver kepasang,")
            print("[AUTO] lalu cek Device Manager -> Ports (COM & LPT).")
            return None

        print(f"[AUTO] Ditemukan: {', '.join(ports)} - dites satu per satu...")
        parity_map = {'N': serial.PARITY_NONE, 'E': serial.PARITY_EVEN, 'O': serial.PARITY_ODD}
        best_port, best_score = None, 0

        for port in ports:
            try:
                conn = serial.Serial(
                    port=port,
                    baudrate=self.baud_rate,
                    parity=parity_map.get(self.parity, serial.PARITY_NONE),
                    stopbits=serial.STOPBITS_ONE,
                    bytesize=serial.EIGHTBITS,
                    timeout=0.5,
                )
            except serial.SerialException:
                print(f"[AUTO] {port}: tidak bisa dibuka, lewati.")
                continue

            score = 0
            try:
                deadline = time.time() + timeout_per_port
                while time.time() < deadline:
                    raw = conn.readline()
                    if not raw:
                        continue
                    data = raw.decode('utf-8', errors='ignore').strip()
                    if re.search(r'(\d+\.\d+)', data):
                        score += 1
            finally:
                conn.close()

            print(f"[AUTO] {port}: skor {score}")
            if score > best_score:
                best_port, best_score = port, score

        if best_port and best_score > 0:
            print(f"[AUTO] Dipilih {best_port} (skor {best_score}).")
            return best_port

        # Tidak ada yang streaming data (wajar kalau BX1 belum nyala/kirim):
        # pakai port pertama agar tetap bisa konek & menunggu data.
        print(f"[AUTO] Tidak ada yang streaming data - pakai {ports[0]} dan menunggu.")
        return ports[0]

    def connect_serial(self) -> bool:
        """Establish serial connection."""
        if self.simulate:
            print("[INFO] Simulator mode active - no serial connection")
            return True

        if self.com_port.strip().upper() == 'AUTO':
            detected = self.auto_detect_port()
            if not detected:
                return False
            self.com_port = detected

        try:
            # Map parity string to pyserial constants
            parity_map = {'N': serial.PARITY_NONE, 'E': serial.PARITY_EVEN, 'O': serial.PARITY_ODD}

            self.serial_conn = serial.Serial(
                port=self.com_port,
                baudrate=self.baud_rate,
                parity=parity_map.get(self.parity, serial.PARITY_NONE),
                stopbits=serial.STOPBITS_ONE,
                bytesize=serial.EIGHTBITS,
                timeout=1,
            )
            print(f"[INFO] Connected to {self.com_port} @ {self.baud_rate} baud")
            return True
        except serial.SerialException as e:
            print(f"[ERROR] Failed to connect to {self.com_port}: {e}")
            return False

    def read_weight(self) -> Optional[float]:
        """Read weight from serial port.

        BX1/Baykon kirim continuous RS-232C, contoh mentah:
          b'\\x02+ 23.450 kg\\r\\n'  (STX = byte 0x02, bukan teks "STX")
        Jadi: decode toleran + coba regex setting dulu,
        kalau gagal fallback ke angka desimal terakhir di baris itu.
        """
        if self.simulate:
            # Simulate weight reading 20-28 kg
            import random
            return round(20 + random.random() * 8, 3)

        if not self.serial_conn or not self.serial_conn.is_open:
            return None

        try:
            raw = self.serial_conn.readline()
            if not raw:
                return None
            data = raw.decode('utf-8', errors='ignore').strip()
            if not data:
                return None

            # 1) Coba regex dari Settings (halaman Pengaturan).
            try:
                pattern = self.parse_regex()
                match = pattern.search(data)
                if match:
                    return float(match.group(1))
            except Exception:
                pass

            # 2) Fallback: ambil angka desimal terakhir (format BX1 umum).
            fallback = re.findall(r'(\d+\.\d+)', data)
            if fallback:
                return float(fallback[-1])

            # 3) Baris tidak dikenali - tampilkan mentahnya agar user bisa
            #    sesuaikan parsing_regex di halaman Pengaturan.
            printable = data.replace('\x02', '<STX>').replace('\x03', '<ETX>')
            print(f"[DEBUG] Baris tak dikenali: {repr(printable)}")
            return None

        except Exception as e:
            print(f"[ERROR] Failed to read weight: {e}")
            return None

    def send_to_api(self, weight: float) -> bool:
        """Send weight reading to Laravel API."""
        try:
            headers = {
                'Content-Type': 'application/json',
            }

            if self.api_token:
                headers['Authorization'] = f'Bearer {self.api_token}'

            response = requests.post(
                self.api_url,
                json={'weight': weight, 'station': self.station},
                headers=headers,
                timeout=5,
            )

            if response.status_code in [200, 201]:
                return True
            else:
                print(f"[WARNING] API returned {response.status_code}: {response.text}")
                return False

        except requests.exceptions.RequestException as e:
            print(f"[ERROR] Failed to send to API: {e}")
            return False

    def status_url(self) -> str:
        """Turunkan URL heartbeat dari URL live API."""
        return self.api_url.replace('/api/weighings/live', '/api/bridge/heartbeat')

    def send_heartbeat(self) -> None:
        """Lapor status driver/COM mesin ini ke server (diam-diam, gagal = abaikan)."""
        try:
            import socket
            ports = [p.device for p in list_ports.comports()] if list_ports else []
            requests.post(
                self.status_url(),
                json={
                    'hostname': socket.gethostname(),
                    'station': self.station,
                    'com_port': None if self.simulate else self.com_port,
                    'ports': ports,
                    'installers': [name for name, _label in find_downloaded_installers()],
                },
                headers={'Content-Type': 'application/json'},
                timeout=5,
            )
        except Exception:
            pass

    def run(self):
        """Main reader loop."""
        if not self.connect_serial():
            if not self.simulate:
                print("[ERROR] Cannot continue without serial connection")
                return

        self.running = True
        print(f"[INFO] Starting weight reader [{self.station}]...")
        self.send_heartbeat()

        try:
            loop = 0
            while self.running:
                weight = self.read_weight()

                if weight is not None:
                    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                    print(f"[{timestamp}][{self.station}] Weight: {weight:.2f} kg", end='', flush=True)

                    if self.send_to_api(weight):
                        print(" OK")
                    else:
                        print(" FAIL")

                loop += 1
                if loop % 100 == 0:  # tiap ~10 detik
                    self.send_heartbeat()

                time.sleep(0.1)  # 100ms delay between reads

        except KeyboardInterrupt:
            print("\n[INFO] Stopping weight reader...")
        finally:
            self.stop()

    def stop(self):
        """Stop the reader and close connections."""
        self.running = False
        if self.serial_conn:
            self.serial_conn.close()
            print("[INFO] Serial connection closed")


CHIP_DB = {
    0x0403: ('FTDI', 'https://ftdichip.com/drivers/vcp-drivers/'),
    0x1A86: ('WCH CH340/CH341', 'https://www.wch.cn/downloads/CH341SER_EXE.html'),
    0x067B: ('Prolific PL2303', 'https://www.prolific.com.tw/US/ShowProduct.aspx?p_id=225&pcid=41'),
    0x10C4: ('Silicon Labs CP210x', 'https://www.silabs.com/developers/usb-to-uart-bridge-vcp-drivers'),
}

# Pola nama file installer driver di folder Downloads.
INSTALLER_HINTS = [
    (('cdm', 'ftdi'), 'FTDI'),
    (('ch34', 'ch341ser', 'wch'), 'WCH CH340/CH341'),
    (('pl23', 'prolific'), 'Prolific PL2303'),
    (('cp210', 'silabs'), 'Silicon Labs CP210x'),
]


def find_downloaded_installers() -> list:
    """Cari file installer driver di folder Downloads user."""
    from pathlib import Path
    found = []
    try:
        dl = Path.home() / 'Downloads'
        if not dl.is_dir():
            return found
        for f in dl.iterdir():
            if not f.is_file():
                continue
            name = f.name.lower()
            if not name.endswith(('.exe', '.zip', '.msi')):
                continue
            for keys, label in INSTALLER_HINTS:
                if any(k in name for k in keys):
                    found.append((f.name, label))
                    break
    except OSError:
        pass
    return found


def run_doctor() -> None:
    """Vonis satu perintah: driver kepasang atau belum."""
    print("=== CEK DRIVER & KABEL ===")
    print(f"pyserial: OK ({serial.__version__})")

    if list_ports is None:
        print("Port: tidak bisa dicek (modul list_ports hilang).")
        return

    installers = find_downloaded_installers()
    if installers:
        print("File installer di Downloads: ADA")
        for name, label in installers:
            print(f"  - {name} [{label}]")
        print("  Artinya file sudah didownload - tinggal dobel-klik install + restart.")
    else:
        print("File installer di Downloads: TIDAK ADA yang dikenali.")
        print("  (Cek manual bila file-nya bernama lain / tersimpan di folder lain.)")
    print("")

    ports = list(list_ports.comports())
    if not ports:
        print("Port: TIDAK ADA yang terdeteksi.")
        print("")
        print("Artinya SATU dari dua ini:")
        print("  1. Kabel BELUM nyolok ke PC ini -> colok dulu, ulangi perintah ini.")
        print("  2. Kabel nyolok tapi driver BELUM kepasang -> install driver,")
        print("     restart PC, ulangi perintah ini.")
        print("Cek silang: Device Manager -> harus ada grup 'Ports (COM & LPT)'.")
        return

    print(f"Port: DITEMUKAN {len(ports)} - driver BERES, kabel kebaca Windows.")
    for p in ports:
        chip = CHIP_DB.get(getattr(p, 'vid', None), None)
        label = f"{chip[0]}" if chip else "chip tak dikenal"
        print(f"  - {p.device}: {p.description} [{label}]")

    print("")
    print("Langkah berikut: python bridge/reader.py --com-port AUTO")


def main():
    # Console Windows (cp1252) crash kalau print karakter aneh.
    # Paksa UTF-8, kalau gagal ya sudah (print sudah ASCII semua).
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

    parser = argparse.ArgumentParser(
        description='ERP Timbang Ayam Hidup - Serial Port Bridge Reader'
    )

    parser.add_argument('--com-port', default='AUTO', help='Serial port, atau AUTO untuk deteksi otomatis (default: AUTO)')
    parser.add_argument('--list-ports', action='store_true', help='Tampilkan daftar port serial lalu keluar')
    parser.add_argument('--doctor', action='store_true', help='Diagnosa: driver kepasang atau belum, lalu keluar')
    parser.add_argument('--baud', type=int, default=9600, help='Baud rate (default: 9600)')
    parser.add_argument('--parity', default='N', choices=['N', 'E', 'O'], help='Parity (default: N)')
    parser.add_argument(
        '--regex',
        default=r'/STX\s+([\d.]+)\s+kg/',
        help='Regex pattern for parsing (default: /STX\\s+([\\d.]+)\\s+kg/)',
    )
    parser.add_argument('--api-url', default='http://localhost:8000/api/weighings/live', help='API endpoint URL')
    parser.add_argument('--api-token', help='API bearer token (optional)')
    parser.add_argument('--station', default='POS-01', help='Kode pos timbang mesin ini (default: POS-01)')
    parser.add_argument('--simulate', action='store_true', help='Enable simulator mode')

    args = parser.parse_args()

    if args.doctor:
        run_doctor()
        return

    if args.list_ports:
        ports = WeightReader.list_serial_ports()
        if ports:
            print("Port serial terdeteksi:")
            for p in ports:
                print(f"  - {p}")
        else:
            print("Tidak ada port serial. Colok kabel + install driver dulu.")
        return

    reader = WeightReader(
        com_port=args.com_port,
        baud_rate=args.baud,
        parity=args.parity,
        parsing_regex=args.regex,
        api_url=args.api_url,
        api_token=args.api_token,
        simulate=args.simulate,
        station=args.station,
    )

    reader.run()


if __name__ == '__main__':
    main()
