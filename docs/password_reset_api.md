# UniDiPay Student Auth + Password Reset API

Base URL (mobile):
- `/unidipaypro/php/api/mobile/auth.php`

Default reset web page:
- `/unidipaypro/mobile-reset.html?token=<raw-token>`

## Login

POST `?action=login`

Request body (email/password mode):
```json
{
  "identifier": "student@school.edu",
  "password": "P@ssw0rd!123",
  "device_name": "flutter-mobile"
}
```

Request body (first-time RFID mode):
```json
{
  "student_id": "2023-0001",
  "nfc_card_id": "04AABBCCDD",
  "device_name": "flutter-mobile"
}
```

Response:
```json
{
  "success": true,
  "token": "plain-session-token",
  "requires_password_setup": true,
  "student": {
    "id": 10,
    "name": "Student Name",
    "student_id": "2023-0001",
    "email": null,
    "nfc_card_id": "04AABBCCDD",
    "balance": 120.5
  }
}
```

## Set Initial Credentials (first-time users)

POST `?action=set_initial_credentials`

Headers:
- `Authorization: Bearer <token>`

Request body:
```json
{
  "email": "student@school.edu",
  "password": "StrongP@ssword1!",
  "confirm_password": "StrongP@ssword1!"
}
```

## Request Password Reset

POST `?action=request_password_reset`

Request body:
```json
{
  "email": "student@school.edu"
}
```

Returns generic success to prevent user enumeration.

Throttling:
- Per account: max 3 reset requests in 15 minutes
- Per IP: max 10 reset requests in 15 minutes

## Validate Reset Token

GET `?action=validate_reset_token&token=<raw-token>`

Response:
```json
{
  "valid": true,
  "expires_at": "2026-04-17 15:42:00",
  "email_hint": "s*****t@school.edu"
}
```

## Reset Password

POST `?action=reset_password`

Request body:
```json
{
  "token": "raw-token-from-email",
  "new_password": "N3wStrongP@ssword!",
  "confirm_password": "N3wStrongP@ssword!"
}
```

On success, all existing student sessions are revoked.

## Session Profile

GET `?action=me`

Headers:
- `Authorization: Bearer <token>`

Response includes `requires_password_setup` so Flutter can enforce setup flow.

## Security Notes

- Passwords use PHP bcrypt via `password_hash(..., PASSWORD_BCRYPT)`.
- Reset tokens are random 256-bit values and only SHA-256 hashes are stored.
- Reset tokens expire after 15 minutes.
- Tokens are one-time use (`used_at` is set on consumption).
- Account enumeration is mitigated by generic reset request responses.
- RFID login is blocked after password is set.
- Old/used reset tokens are cleaned up automatically.

## Environment Setup

- `UNIDIPAY_RESET_BASE_URL` (optional): override reset-link target URL.
- `APP_ENV` (optional): set to `production` in production environments.
- SMTP options (recommended in production):
  - `UNIDIPAY_SMTP_HOST` (enables SMTP when set)
  - `UNIDIPAY_SMTP_PORT` (default `587`)
  - `UNIDIPAY_SMTP_USERNAME`
  - `UNIDIPAY_SMTP_PASSWORD`
  - `UNIDIPAY_SMTP_ENCRYPTION` (`tls`, `ssl`, or `none`; default `tls`)
  - `UNIDIPAY_SMTP_FROM_EMAIL` (default `no-reply@unidipay.local`)
  - `UNIDIPAY_SMTP_FROM_NAME` (default `UniDiPay`)

Local/XAMPP option:
- Copy `php/api/mobile/mailer.local.example.php` to `php/api/mobile/mailer.local.php`
- Fill in your SMTP credentials there.
- Environment variables still take precedence over `mailer.local.php` when both are present.

SMTP delivery falls back to PHP `mail()` if SMTP is not configured or temporarily fails.

## Production Smoke Test Script

Path:
- `/unidipaypro/mobile/unidipay_mobile/tools/smoke-test-auth-reset.ps1`

Example usage:
```powershell
powershell -ExecutionPolicy Bypass -File ".\mobile\unidipay_mobile\tools\smoke-test-auth-reset.ps1" \
  -ApiBase "https://your-host/unidipaypro/php/api/mobile/auth.php" \
  -LoginEmail "student@school.edu" \
  -LoginPassword "CurrentPassword!1" \
  -ResetEmail "student@school.edu"
```

Optional token-validation and reset step:
```powershell
powershell -ExecutionPolicy Bypass -File ".\mobile\unidipay_mobile\tools\smoke-test-auth-reset.ps1" \
  -ApiBase "https://your-host/unidipaypro/php/api/mobile/auth.php" \
  -ResetToken "raw-token-from-email" \
  -ResetNewPassword "NewStrongPassword!2"
```
