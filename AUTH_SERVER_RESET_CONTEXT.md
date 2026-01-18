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

## Future Feature: Magic Link Login
*   **Goal:** Allow users to log in by clicking a link in their email (no password needed).
*   **Implementation Plan:**
    1.  **Create `magic-login.php`:**
        *   Accept `email` input.
        *   Generate a short-lived (5 min) random token.
        *   Store token in DB (new column `magic_token` or reuse `reset_token` with flag).
        *   Send email with link: `https://auth.directsponsor.org/verify-magic.php?token=XYZ`.
    2.  **Create `verify-magic.php`:**
        *   Validate token.
        *   If valid, issue standard Session/JWT (same logic as `jwt-login.php`).
        *   Redirect user to their intended destination (`redirect_uri`).
