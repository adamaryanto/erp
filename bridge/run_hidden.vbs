' ERP Timbang - peluncur proses tersembunyi (tanpa PowerShell/WMI/start).
' Dipakai tombol START SERVICE di web. PID dilacak via --pid-file cam_ocr.py,
' jadi di sini cukup luncurkan hidden + kembali langsung (async).
' Cara pakai: cscript //nologo run_hidden.vbs "C:\path\python.exe arg1 arg2 ..."
' Keluaran: "ok". TIDAK ada window/popup (Run dengan 0 = hidden).

Dim cmd, sh
If WScript.Arguments.Count = 0 Then
    WScript.StdErr.WriteLine "argumen perintah kosong"
    WScript.Quit 1
End If
cmd = WScript.Arguments(0)

On Error Resume Next
Set sh = CreateObject("WScript.Shell")
' Env vital (Apache minim environment; tanpa ini python fatal error).
Set env = sh.Environment("PROCESS")
env("SYSTEMROOT") = "C:\Windows"
env("PYTHONHASHSEED") = "0"
env("PYTHONIOENCODING") = "utf-8"
' 0 = hidden, False = jangan tunggu (async, kembali langsung)
sh.Run cmd, 0, False
If Err.Number <> 0 Then
    WScript.StdErr.WriteLine "Run gagal: " & Err.Description
    WScript.Quit 2
End If

WScript.Echo "ok"
