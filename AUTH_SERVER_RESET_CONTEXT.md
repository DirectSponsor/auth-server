# Auth Server Password Reset Context

## Overview
The user wants to fix/enable the "Forgot Password" functionality on the Auth Server (`es3-auth`).
Logic for this exists in `forgot-password.php` and `reset-password.php` but seems unused or unlinked.

## Current State
*   **Path:** `/home/andy/work/projects/auth-server/auth/website/` (local) mapped to `/var/www/auth.directsponsor.org/public_html/` (remote).
*   **Files:**
    *   `jwt-login.php`: Main login page. **MISSING:** A visible "Forgot Password?" link.
    *   `forgot-password.php`:
        *   Accepts `email` via POST.
        *   Checks rate limits.
        *   Generates a token and stores inside `users` table (`reset_token`, `reset_token_expires`).
        *   Uses `email-helper.php` to send the email.
    *   `reset-password.php`:
        *   Validates `token` from URL.
        *   Allows user to set new password.
        *   Updates `password_hash` in `users` table.

## Required Tasks
1.  **Add Link to Login:**
    *   Edit `jwt-login.php`.
    *   Add a link to `forgot-password.php` near the password field or submit button.
    *   Pass `redirect_uri` param to it so users return to the correct site after resetting.

2.  **Verify Email Config:**
    *   Check `email-config.php` (referenced in `email-helper.php`) to ensure SMTP settings are correct for the new server environment.

3.  **Deploy:**
    *   Deploy changes to `es3-auth`.

## Code Snippets
**`jwt-login.php` insertion point (approx line 233):**
```html
<div class="form-group">
    <label for="password">Password:</label>
    <input type="password" id="password" name="password" required>
    <div style="text-align: right; margin-top: 5px; font-size: 0.9em;">
        <a href="forgot-password.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>" style="color: #667eea; text-decoration: none;">Forgot Password?</a>
    </div>
</div>
```

## Completed Features (Jan 2026)

### 1. Password Reset
- **Status:** ✅ Implemented & Deployed
- **Flow:**
    - User clicks "Forgot Password?" on login page.
    - Enters **Username**.
    - System looks up email and sends reset link.
    - User clicks link, enters new password.
    - User receives success message with links to main sites.

### 2. Magic Link Login
- **Status:** ✅ Implemented & Deployed
- **Flow:**
    - User clicks "Log in with Magic Link" on login page.
    - Checks input against **Email** OR **Username**.
    - Generates 5-minute token.
    - Sends email with magic link.
    - User clicks link -> `verify-magic.php` -> invalidates token -> logs user in -> redirects to `redirect_uri`.
    - **Robustness:** `redirect_uri` is embedded in the emailed link itself (`verify-magic.php?token=…&redirect_uri=…`, validated against `$allowed_redirects`), so it survives the link being opened in a different browser/mail client. Session value is kept as a fallback; final default is `https://directsponsor.net/`.

### Fixes (Oct 2026)
- **Token expiry timezone bug (all token flows):** expiry timestamps were written with PHP `date()` (PHP runs UTC) but verified with MySQL `NOW()` (server clock, BST during summer) — every magic/reset/verification link was stored ~1h expired during BST. Fixed by computing expiry DB-side: `magic_token_expires = DATE_ADD(NOW(), INTERVAL …)`. **Rule: never mix PHP `date()`/`time()` with MySQL `NOW()` on this server — do all comparisons on one clock.**

### 3. Database Schema
- Added `magic_token` and `magic_token_expires` to `users` table via SSH.

