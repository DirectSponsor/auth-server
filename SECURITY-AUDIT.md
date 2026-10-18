# Auth Server Security Audit
September 2026 — static analysis of auth/website/ + client auth.js
DO NOT COMMIT — contains remediation notes referencing secret values

---

## CRITICAL

**C1. JWT secret disclosed in git (roflfaucet/AGENTS.md line 132)**
The value "simple_secret_2025" is written out in a committed file. Anyone with repo access can forge tokens for any user on any site.
Fix: rotate secret now, remove from AGENTS.md, store only in /etc/jwt-secret.

**C2. JWT secret is trivially weak regardless of disclosure**
"simple_secret_2025" is dictionary-crackable offline in seconds even without the repo.
Fix: replace with output of `openssl rand -base64 32`.

**C3. api/sync.php has no authentication and wildcard CORS**
Access-Control-Allow-Origin: * with a TODO comment that was never acted on.
Any browser tab can POST to push balance, profile, or roles for any user with no credentials.
An attacker can right now grant themselves admin role or any coin balance.
Fix: require a valid signed JWT in Authorization header before accepting any write. Block at nginx until fixed.

**C4. Open redirect in validateRedirectUri() — JWT sent to attacker domain**
The prefix check (strpos($uri, $allowed) === 0) allows redirect_uri values like
"https://roflfaucet.com.evil.com/steal" because they start with "https://roflfaucet.com".
The JWT lands on the attacker's server as a query parameter.
Fix: parse with parse_url(), compare host exactly against an allowlist.

---

## HIGH

**H1. JWT delivered in URL query parameter (?jwt=) on every login**
Appears in Apache access logs, browser history, and Referer headers sent to third-party resources.
Files: jwt-login.php:74, verify-magic.php:68, jwt-signup.php:206
Fix: switch to fragment (#jwt=) so token never reaches the server. auth.js reads location.search — change to location.hash.

**H2. JWT/session stored in localStorage — readable by all page JS**
auth.js stores session + user_id + combined_user_id in localStorage.
A single XSS on any of the five network sites compromises the shared session for all of them.
Fix: use HttpOnly cookies for the JWT so JS cannot read it.

**H3. SSO bridge sends session data with postMessage target '*'**
sso-bridge.php sends user_id, username, email to window.parent with origin '*'.
Any page that iframes the bridge URL receives full session data.
Fix: replace '*' with explicit allowed parent origin. Also reconcile with X-Frame-Options: DENY in .htaccess which may already be breaking this.

**H4. api/faucet-sync.php — no auth, wildcard CORS, anyone can read/write any user's faucets**
Fix: require JWT matching the requested user_id.

**H5. api/get_balance.php — no auth, wildcard CORS, anyone can read any user's balance**
Fix: require authentication, or explicitly document as public if that's intentional.

**H6. Password change does not invalidate outstanding JWTs**
A stolen token works for the full 1-hour expiry even after victim changes password.
Fix: add token_version column to users table, increment on password change, check version in validateJWT().

---

## MEDIUM

**M1. validateJWT() doesn't check iss or aud claims**
Only signature and expiry are verified. Any token signed with the same secret is accepted regardless of issuer.
File: jwt-refresh.php:20-46

**M2. No CSRF protection on any form**
Login, signup, change-password, magic login — none have CSRF tokens.
Fix: add per-session CSRF token to all forms. Also set session cookie SameSite=Lax explicitly.

**M3. JWT accepted as GET parameter in change-password.php**
Ends up in server logs. File: change-password.php:24-25
Fix: accept token only in Authorization header.

**M4. SMTP TLS certificate verification disabled**
verify_peer => false in email-helper.php for both SSL and STARTTLS connections.
Magic links and password reset tokens can be intercepted by a network attacker.
Fix: remove the verify_peer overrides.

**M5. .htaccess CORS header conflicts with PHP dynamic CORS**
.htaccess sets a static "https://roflfaucet.com" header; PHP then overrides it.
Behaviour depends on Apache directive order and is hard to reason about.
Fix: remove CORS headers from .htaccess, let PHP own it entirely.

**M6. localhost and 127.0.0.1 in production redirect whitelist**
Development-only origins in config.php:38-43.
Fix: remove them from the live server config.

---

## LOW

**L1. Admin panel not IP-restricted**
admin-users.php reachable from any IP, password-only protection.
Fix: add IP allowlist in .htaccess (Require ip ...).

**L2. No rate limiting on standard password login**
Magic link has rate limiting; the password form does not. Allows unlimited brute force.
Fix: apply same checkRateLimit() pattern used in magic-login.php.

**L3. $jwt_algorithm config variable is silently ignored**
createJWT() hardcodes HS256 regardless of the config setting.
Fix: use the variable, or remove it to avoid confusion.

---

## INFORMATIONAL

**I1. Magic link token stored plaintext in DB**
magic_token column is unhashed. If DB is read, outstanding tokens are directly usable.
Fix: store sha256(token), compare hash on verify.

**I2. combined_user_id format is predictable**
Sequential int + public username = trivially enumerable. Amplifies unauthenticated API exposure.

**I3. X-Frame-Options: DENY conflicts with SSO bridge iframe design**
.htaccess applies DENY globally but sso-bridge.php is meant to be iframed.
Either the bridge is silently broken in production, or there's an override not in the local copy.

---

## Top 5 priorities (ordered by impact)

1. Rotate the JWT secret. Remove from AGENTS.md. [CRITICAL — do today]
2. Lock down api/sync.php — add JWT auth to all write actions. [CRITICAL — do today]
3. Fix validateRedirectUri() — use parse_url() + exact host match. [CRITICAL — quick code fix]
4. Stop delivering JWT in URL query string — switch to #fragment. [HIGH]
5. Add token_version to invalidate tokens on password change. [HIGH]

---

## Is JWT itself the problem?

No. HS256 with a strong secret is fine for this architecture.
Every vulnerability is in the surrounding implementation:
weak/disclosed secret, token in URL, token in localStorage, unauthenticated APIs, no revocation.
Fix those five things and this is a reasonable SSO system for its scale.
