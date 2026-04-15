$adb = "C:\Users\Drinx\AppData\Local\Android\Sdk\platform-tools\adb.exe"

if (-not (Test-Path $adb)) {
  Write-Error "adb.exe not found at $adb"
  exit 1
}

& $adb start-server | Out-Null
& $adb devices
& $adb reverse --remove-all
& $adb reverse tcp:8080 tcp:80
Write-Host ""
Write-Host "Active reverse mappings:"
& $adb reverse --list
