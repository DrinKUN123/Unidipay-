param(
    [string]$ApiBase = "http://localhost/unidipaypro/php/api/mobile/auth.php",
    [string]$ResetEmail = "",
    [string]$ResetToken = "",
    [string]$ResetNewPassword = "",
    [string]$LoginEmail = "",
    [string]$LoginPassword = "",
    [string]$StudentId = "",
    [string]$NfcCardId = "",
    [switch]$SkipRfidLogin,
    [switch]$SkipEmailLogin,
    [switch]$SkipResetFlow
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Check {
    param(
        [string]$Name,
        [bool]$Passed,
        [string]$Detail
    )

    $status = if ($Passed) { "PASS" } else { "FAIL" }
    $color = if ($Passed) { "Green" } else { "Red" }
    Write-Host "[$status] $Name - $Detail" -ForegroundColor $color
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Url,
        [object]$Body = $null,
        [hashtable]$Headers = @{}
    )

    $reqParams = @{
        Method = $Method
        Uri = $Url
        Headers = $Headers
    }

    if ($null -ne $Body) {
        $reqParams.ContentType = "application/json"
        $reqParams.Body = ($Body | ConvertTo-Json -Depth 8)
    }

    try {
        $response = Invoke-WebRequest @reqParams
        $json = $null
        if ($response.Content) {
            $json = $response.Content | ConvertFrom-Json
        }

        return [PSCustomObject]@{
            StatusCode = [int]$response.StatusCode
            Json = $json
            Raw = $response.Content
            Error = $null
        }
    } catch {
        $statusCode = 0
        $content = ""
        $json = $null

        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode.value__
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $content = $reader.ReadToEnd()
            $reader.Close()
            if ($content) {
                try { $json = $content | ConvertFrom-Json } catch {}
            }
        }

        return [PSCustomObject]@{
            StatusCode = $statusCode
            Json = $json
            Raw = $content
            Error = $_.Exception.Message
        }
    }
}

$summary = New-Object System.Collections.Generic.List[object]

Write-Host "UniDiPay auth/reset smoke test" -ForegroundColor Cyan
Write-Host "API base: $ApiBase" -ForegroundColor DarkCyan

# 1) Contract sanity: missing credentials should fail.
$invalidLogin = Invoke-Api -Method "POST" -Url "$ApiBase?action=login" -Body @{}
$invalidLoginOk = $invalidLogin.StatusCode -ge 400
Write-Check -Name "Login validation" -Passed $invalidLoginOk -Detail "HTTP $($invalidLogin.StatusCode)"
$summary.Add([PSCustomObject]@{ Step = "Login validation"; Passed = $invalidLoginOk; Status = $invalidLogin.StatusCode })

$sessionToken = ""

# 2) Optional RFID login path.
if (-not $SkipRfidLogin -and $StudentId -and $NfcCardId) {
    $rfidResponse = Invoke-Api -Method "POST" -Url "$ApiBase?action=login" -Body @{
        student_id = $StudentId
        nfc_card_id = $NfcCardId
        device_name = "smoke-test"
    }

    $rfidOk = $rfidResponse.StatusCode -eq 200 -and $rfidResponse.Json.success -eq $true
    Write-Check -Name "RFID login" -Passed $rfidOk -Detail "HTTP $($rfidResponse.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "RFID login"; Passed = $rfidOk; Status = $rfidResponse.StatusCode })

    if ($rfidOk -and $rfidResponse.Json.token) {
        $sessionToken = [string]$rfidResponse.Json.token
    }
}

# 3) Optional email/password login path.
if (-not $SkipEmailLogin -and $LoginEmail -and $LoginPassword) {
    $emailLogin = Invoke-Api -Method "POST" -Url "$ApiBase?action=login" -Body @{
        identifier = $LoginEmail
        password = $LoginPassword
        device_name = "smoke-test"
    }

    $emailLoginOk = $emailLogin.StatusCode -eq 200 -and $emailLogin.Json.success -eq $true
    Write-Check -Name "Email login" -Passed $emailLoginOk -Detail "HTTP $($emailLogin.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "Email login"; Passed = $emailLoginOk; Status = $emailLogin.StatusCode })

    if ($emailLoginOk -and $emailLogin.Json.token) {
        $sessionToken = [string]$emailLogin.Json.token
    }
}

# 4) Optional authenticated me/logout checks.
if ($sessionToken) {
    $authHeaders = @{ Authorization = "Bearer $sessionToken" }

    $meResponse = Invoke-Api -Method "GET" -Url "$ApiBase?action=me" -Headers $authHeaders
    $meOk = $meResponse.StatusCode -eq 200 -and $meResponse.Json.success -eq $true
    Write-Check -Name "Session me" -Passed $meOk -Detail "HTTP $($meResponse.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "Session me"; Passed = $meOk; Status = $meResponse.StatusCode })

    $logoutResponse = Invoke-Api -Method "POST" -Url "$ApiBase?action=logout" -Headers $authHeaders
    $logoutOk = $logoutResponse.StatusCode -eq 200 -and $logoutResponse.Json.success -eq $true
    Write-Check -Name "Session logout" -Passed $logoutOk -Detail "HTTP $($logoutResponse.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "Session logout"; Passed = $logoutOk; Status = $logoutResponse.StatusCode })
}

# 5) Optional reset flow.
if (-not $SkipResetFlow -and $ResetEmail) {
    $requestReset = Invoke-Api -Method "POST" -Url "$ApiBase?action=request_password_reset" -Body @{ email = $ResetEmail }
    $requestResetOk = $requestReset.StatusCode -eq 200 -and $requestReset.Json.success -eq $true
    Write-Check -Name "Request password reset" -Passed $requestResetOk -Detail "HTTP $($requestReset.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "Request reset"; Passed = $requestResetOk; Status = $requestReset.StatusCode })
}

if (-not $SkipResetFlow -and $ResetToken) {
    $validate = Invoke-Api -Method "GET" -Url "$ApiBase?action=validate_reset_token&token=$([uri]::EscapeDataString($ResetToken))"
    $validateOk = $validate.StatusCode -eq 200 -and $validate.Json.valid -eq $true
    Write-Check -Name "Validate reset token" -Passed $validateOk -Detail "HTTP $($validate.StatusCode)"
    $summary.Add([PSCustomObject]@{ Step = "Validate token"; Passed = $validateOk; Status = $validate.StatusCode })

    if ($ResetNewPassword) {
        $reset = Invoke-Api -Method "POST" -Url "$ApiBase?action=reset_password" -Body @{
            token = $ResetToken
            new_password = $ResetNewPassword
            confirm_password = $ResetNewPassword
        }

        $resetOk = $reset.StatusCode -eq 200 -and $reset.Json.success -eq $true
        Write-Check -Name "Reset password" -Passed $resetOk -Detail "HTTP $($reset.StatusCode)"
        $summary.Add([PSCustomObject]@{ Step = "Reset password"; Passed = $resetOk; Status = $reset.StatusCode })
    }
}

Write-Host ""
Write-Host "Summary" -ForegroundColor Cyan
$summary | Format-Table -AutoSize

$failed = ($summary | Where-Object { -not $_.Passed }).Count
if ($failed -gt 0) {
    Write-Host "Smoke test completed with failures: $failed" -ForegroundColor Red
    exit 1
}

Write-Host "Smoke test completed successfully." -ForegroundColor Green
exit 0
