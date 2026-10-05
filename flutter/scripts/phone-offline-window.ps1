# Offline window for integration_test/phase3a_acceptance_test.dart.
#
# Start it in a second PowerShell window, then run the test with its output saved to the
# same file:
#   .\scripts\phone-offline-window.ps1 -Watch run.txt
#   flutter test integration_test/phase3a_acceptance_test.dart -d emulator-5554 | Tee-Object run.txt
#
# When the test prints "ACCEPTANCE-GO-OFFLINE" (test output is forwarded to the computer,
# not to the emulator's logcat), airplane mode goes on for 30 s, then off. The API server
# (port 8000) is never touched: only the phone loses its network.
param(
    [Parameter(Mandatory = $true)][string]$Watch,
    [string]$Device = 'emulator-5554',
    [int]$Seconds = 30,
    [int]$WaitMinutes = 15
)

$adb = "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe"
"$(Get-Date -Format HH:mm:ss) watching $Watch for ACCEPTANCE-GO-OFFLINE"
$deadline = (Get-Date).AddMinutes($WaitMinutes)
while ((Get-Date) -lt $deadline) {
    Start-Sleep -Milliseconds 500
    if ((Test-Path $Watch) -and (Select-String -Path $Watch -Pattern 'ACCEPTANCE-GO-OFFLINE' -Quiet)) {
        & $adb -s $Device shell cmd connectivity airplane-mode enable
        "$(Get-Date -Format HH:mm:ss) airplane mode ON (phone offline)"
        Start-Sleep -Seconds $Seconds
        & $adb -s $Device shell cmd connectivity airplane-mode disable
        "$(Get-Date -Format HH:mm:ss) airplane mode OFF (phone online)"
        exit 0
    }
}
"$(Get-Date -Format HH:mm:ss) no signal within $WaitMinutes minutes"
exit 1
