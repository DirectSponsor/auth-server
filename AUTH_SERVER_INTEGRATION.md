# Network Authentication Playbook

## Status Update (Dec 29, 2025)

### Completed
- **Auth server repository created** at `/home/andy/work/projects/auth-server/`
- **Production code downloaded** from `hub:/var/www/auth.directsponsor.org/public_html/`
- **Credentials secured** - moved database passwords and JWT secrets to `config.local.php` (excluded from git)
- **Deploy script configured** - ready to use with `./deploy.sh` from auth-server directory
- **GitHub repo updated** - pushed to `https://github.com/DirectSponsor/auth-server` (credentials safe)

### Next Steps for satoshihost.top Integration
1. **Add domain to auth server whitelist** - edit `auth/website/config.php` and add satoshihost.top URLs
2. **Deploy auth server changes** - run `./deploy.sh` to push whitelist update
3. **Implement balance checking** - use auth server API to verify monthly coin contributions
4. **Track monthly eligibility** - store qualification status locally (database or flat files)

---

## Why this exists
All of our front-facing sites (e.g. clickforcharity.net, roflfaucet.com, satoshihost.top) share the same SSO experience out of `https://auth.directsponsor.org`. We issue JWTs from there, store the session in `localStorage`, and then call `/api/simple-profile.php` / `/api/update_balance.php` to keep each site's balances and roles in sync. This document captures the minimum context your AI or teammate needs to add another property safely.

---

## 1. Auth server configuration

### Local Repository
- **Location:** `/home/andy/work/projects/auth-server/`
- **GitHub:** `https://github.com/DirectSponsor/auth-server`
- **SSH alias:** `hub` (points to 86.38.200.119)
- **Production path:** `/var/www/auth.directsponsor.org/public_html/`

### Adding a New Site
1. Edit `auth/website/config.php` in the local repo and add to `$allowed_redirects`:
   ```php
   'https://satoshihost.top',
   'https://satoshihost.top/',
   'https://www.satoshihost.top',
   'https://www.satoshihost.top/',
   ```
2. Run `./deploy.sh` from `/home/andy/work/projects/auth-server/` to deploy changes
3. The deploy script will:
   - Auto-commit and push to GitHub
   - Back up local and remote code
   - Rsync to production (excluding `data/` and `config.local.php`)
   - Fix permissions

### Security Notes
- **Credentials are protected:** Database passwords and JWT secrets live in `config.local.php` (never committed to git)
- **Production has real secrets:** The server's `config.local.php` contains actual credentials
- **Template in git:** `config.php` shows placeholders (`YOUR_DATABASE_PASSWORD_HERE`) for documentation

---

## 2. Client-side integration (copy `js/auth.js`)
We bundle a shared `AuthSystem` class in `js/auth.js`. A few expectations for satoshihost.top:

1. **Instantiate the class** when the page loads and wire the login/logout buttons:
   ```js
   const auth = new AuthSystem();
   document.querySelector('#login-btn').addEventListener('click', () => auth.login());
   document.querySelector('#logout-btn').addEventListener('click', () => auth.logout());
   auth.handleAuthCallback(); // call on every page load to process JWTs from auth server
   ```
2. The script redirects users to `https://auth.directsponsor.org/jwt-login.php?redirect_uri=<current URL>`. The redirect URI must match one of the entries you added to `$allowed_redirects`.
3. When the auth server returns `jwt=...`, the callback code decodes the JWT, stores `combined_user_id`, `user_id`, `username`, and a shared `directsponsor_session` entry in `localStorage`, and removes stale caches (completed tasks/balance) so the new site can start fresh.
4. You can re-use the button markup/class names from the other sites; the auth module only depends on the `AuthSystem` class and the `localStorage` contract.
5. The `AuthSystem` instance exposes helpers like `getSession()`, `isLoggedIn()`, `getUserRole()`, and `isAdmin()` if you need role-aware UI.

---

## 3. Balance/profile sync
All sites call the shared PHP endpoints to keep their local user data aligned with the auth server.

- `api/simple-profile.php`: fetches the auth server profile via `https://auth.directsponsor.org/api/sync.php` and caches local copies. Use it whenever you need `username`, `roles`, `avatar`, etc.
- `api/update_balance.php`: similarly pulls balance data; call it after each task or on page load to refresh tokens/earned totals.

Both endpoints expect a `user_id` in the `id-username` format (available as `combined_user_id` in `localStorage`). Call them from the backend (PHP) or via `fetch` as the existing pages do.

---

## 4. Step-by-step instructions for satoshihost.top
1. **Auth server:** add `satoshihost.top` (with and without `www`/`/`) to the `$allowed_redirects` list.
2. **Client script:** copy `js/auth.js` from any current project, include it in your HTML, and instantiate it exactly as the other sites do. Keep the `sessionKey` (`directsponsor_session`) unchanged so other pages can read the same session.
3. **Login UI:** wire your Sign In/Sign Out buttons to call `auth.login()` / `auth.logout()`. Run `auth.handleAuthCallback()` on every page load before you read session data.
4. **Sync balance/profile:** call `api/simple-profile.php?action=profile&user_id=<combined_user_id>` (or run `api/update_balance.php` when a task completes) to stay in sync with roflfaucet/clickforcharity balances.
5. **Session contract:** always write/read `user_id`, `username`, `combined_user_id`, and `directsponsor_session` to `localStorage` so other client libs, caches, or background syncs can interoperate.
6. **Document the flow:** keep this file up to date and add future domains to the same doc so your AI teammate knows where to look for the redirect whitelist and client expectations.

---

## 5. Quick reference links
- **Auth server repo:** `/home/andy/work/projects/auth-server/`
- **Auth client source:** `js/auth.js` (in clickforcharity.net or roflfaucet.com repos)
- **Profile/balance sync endpoints:** `api/simple-profile.php`, `api/update_balance.php`
- **Production auth server:** `https://auth.directsponsor.org/`
- **Data sync API:** `https://auth.directsponsor.org/api/sync.php`
- **SSH config:** `~/.ssh/config` (see `hub` alias for auth server access)

---

## 6. satoshihost.top: Balance verification approach

### No VPS/Syncthing Required
Unlike full balance sync, satoshihost.top only needs to verify monthly coin contributions (e.g., 2000 coins/month for free hosting). This works perfectly on shared hosting.

### Implementation Strategy
1. **Query balance on-demand** via `https://auth.directsponsor.org/api/sync.php?user_id=<combined_user_id>`
2. **Track monthly contributions locally** - store when users hit monthly thresholds
3. **Verify eligibility** - check if user qualified this month before granting hosting access

### Storage Options
- **Database** (MySQL/SQLite): Standard approach, easy to query
- **Flat files** (JSON): Lightweight, no database overhead

Both work fine—choose what fits your workflow.

### Example Balance Check (PHP)
```php
// Get current balance from auth server
function getUserBalance($userId) {
    $url = "https://auth.directsponsor.org/api/sync.php?user_id=" . urlencode($userId);
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    return $data['balance'] ?? 0;
}

// Track monthly qualification locally
function checkMonthlyQualification($userId) {
    $currentBalance = getUserBalance($userId);
    $month = date('Y-m');
    
    // Check if user has contributed 2000+ coins this month
    // Store/update qualification status in your database or JSON file
    // Return true if qualified, false otherwise
}
```

### Integration Steps
1. Add satoshihost.top to auth server whitelist (section 1)
2. Deploy auth server changes
3. Copy `js/auth.js` for client-side login
4. Create balance verification endpoint
5. Implement monthly tracking (database table or JSON file)
6. Wire up eligibility checks before granting hosting access

---

Drop this file into any new repo or AI context bundle to keep everyone aligned.
