<?php
require_once 'config.php';

// --- Admin authentication ---
// Set ADMIN_PASSWORD in config.local.php
if (!defined('ADMIN_PASSWORD')) { define('ADMIN_PASSWORD', 'changeme'); }

// Brute force protection: IP-based fail counter stored in a temp file
define('ADMIN_MAX_FAILS', 5);
define('ADMIN_LOCKOUT_SECS', 900); // 15 minutes

function admin_lock_file() {
    return sys_get_temp_dir() . '/ds_adm_' . md5(($_SERVER['REMOTE_ADDR'] ?? 'x')) . '.json';
}
function admin_is_locked() {
    $f = admin_lock_file();
    if (!file_exists($f)) return false;
    $d = @json_decode(file_get_contents($f), true);
    return ($d && isset($d['until']) && $d['until'] > time()) ? $d['until'] : false;
}
function admin_record_fail() {
    $f = admin_lock_file();
    $d = @json_decode(@file_get_contents($f), true) ?: ['fails' => 0];
    $d['fails'] = ($d['fails'] ?? 0) + 1;
    $d['last']  = time();
    if ($d['fails'] >= ADMIN_MAX_FAILS) { $d['until'] = time() + ADMIN_LOCKOUT_SECS; }
    file_put_contents($f, json_encode($d), LOCK_EX);
}
function admin_clear_lock() { @unlink(admin_lock_file()); }

session_start();

$auth_error = '';

if (isset($_POST['admin_password'])) {
    $locked_until = admin_is_locked();
    if ($locked_until) {
        $auth_error = 'Too many failed attempts. Try again in ' . ceil(($locked_until - time()) / 60) . ' minute(s).';
    } elseif ($_POST['admin_password'] === ADMIN_PASSWORD) {
        $_SESSION['admin_authed'] = true;
        admin_clear_lock();
    } else {
        admin_record_fail();
        $auth_error = 'Wrong credentials.';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_authed']);
}

if (empty($_SESSION['admin_authed'])) {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login</title>
<style>
body { font-family: sans-serif; background: #1a1a2e; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
.box { background: #fff; padding: 2rem; border-radius: 8px; width: 100%; max-width: 320px; }
h1 { margin: 0 0 1.5rem; font-size: 1.2rem; color: #333; }
input[type=password] { width: 100%; padding: .6rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; margin-bottom: .8rem; }
button { width: 100%; padding: .65rem; background: #4a4e9e; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
button:hover { background: #3a3e8e; }
.err { color: #c33; font-size: .9rem; margin-bottom: .8rem; }
</style>
</head>
<body>
<div class="box">
    <h1>Admin Access</h1>
    <?php if ($auth_error): ?><p class="err"><?php echo htmlspecialchars($auth_error); ?></p><?php endif; ?>
    <form method="POST">
        <input type="password" name="admin_password" placeholder="Password" autofocus required>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html><?php
    exit;
}

// --- Data queries ---
$db = getAuthDB();

// Sorting
$sort = $_GET['sort'] ?? 'newest';
$sort_map = [
    'newest'       => 'ORDER BY u.created_at DESC',
    'oldest'       => 'ORDER BY u.created_at ASC',
    'username'     => 'ORDER BY u.username ASC',
    'username_desc'=> 'ORDER BY u.username DESC',
    'site'         => 'ORDER BY u.signup_site ASC, u.created_at DESC',
    'site_desc'    => 'ORDER BY u.signup_site DESC, u.created_at DESC',
    'logins'       => 'ORDER BY login_count DESC, u.created_at DESC',
    'logins_asc'   => 'ORDER BY login_count ASC, u.created_at DESC',
    'last_login'   => 'ORDER BY last_login DESC',
    'last_login_asc'=> 'ORDER BY last_login ASC',
    'id'           => 'ORDER BY u.id ASC',
    'id_desc'      => 'ORDER BY u.id DESC',
    'verified'     => 'ORDER BY u.email_verified DESC, u.created_at DESC',
    'verified_asc' => 'ORDER BY u.email_verified ASC, u.created_at DESC',
];
$order_clause = $sort_map[$sort] ?? $sort_map['newest'];

// Balance sort needs PHP-side sorting (balances live in flat files, not the DB)
$balance_sort = in_array($sort, ['balance', 'balance_asc']);

// Load all balances from flat files into a uid => balance map
$balances = [];
$balance_dir = '/var/directsponsor-data/userdata/balances';
if (is_dir($balance_dir)) {
    foreach (glob($balance_dir . '/*.txt') as $bf) {
        $uid = (int)strtok(basename($bf, '.txt'), '-');
        if ($uid > 0) {
            $bdata = @json_decode(file_get_contents($bf), true);
            $bal = $bdata['balance'] ?? 0;
            $balances[$uid] = is_array($bal) ? (float)($bal['coins'] ?? 0) : (float)$bal;
        }
    }
}

// Search
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$where  = '';
$params = [];
$having = '';
if ($search !== '') {
    $where    = 'WHERE (u.username LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($filter === 'active') {
    $having = 'HAVING login_count > 0';
} elseif ($filter === 'inactive') {
    $having = 'HAVING login_count = 0';
} elseif ($filter === 'unverified') {
    $where .= ($where ? ' AND' : 'WHERE') . ' u.email_verified = 0';
}

// Pagination
$per_page    = 50;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

// For filtered counts, we need a subquery approach
$count_sql = "SELECT COUNT(*) FROM (
    SELECT u.id, COUNT(l.id) AS login_count
    FROM users u
    LEFT JOIN login_log l ON l.user_id = u.id
    $where
    GROUP BY u.id
    $having
) AS filtered";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_users = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_users / $per_page));

if ($balance_sort) {
    // Fetch all matching users (no LIMIT), sort by balance in PHP, then slice
    $users_stmt = $db->prepare("
        SELECT u.id, u.username, u.email, u.email_verified,
               u.created_at, u.signup_site,
               COUNT(l.id) AS login_count,
               MAX(l.logged_in_at) AS last_login
        FROM users u
        LEFT JOIN login_log l ON l.user_id = u.id
        $where
        GROUP BY u.id
        $having
    ");
    $users_stmt->execute($params);
    $all = $users_stmt->fetchAll();
    foreach ($all as &$u) { $u['balance'] = $balances[(int)$u['id']] ?? 0; }
    unset($u);
    usort($all, function($a, $b) use ($sort) {
        return $sort === 'balance'
            ? $b['balance'] <=> $a['balance']
            : $a['balance'] <=> $b['balance'];
    });
    $users = array_slice($all, $offset, $per_page);
} else {
    $users_stmt = $db->prepare("
        SELECT u.id, u.username, u.email, u.email_verified,
               u.created_at, u.signup_site,
               COUNT(l.id) AS login_count,
               MAX(l.logged_in_at) AS last_login
        FROM users u
        LEFT JOIN login_log l ON l.user_id = u.id
        $where
        GROUP BY u.id
        $having
        $order_clause
        LIMIT $per_page OFFSET $offset
    ");
    $users_stmt->execute($params);
    $users = $users_stmt->fetchAll();
    foreach ($users as &$u) { $u['balance'] = $balances[(int)$u['id']] ?? 0; }
    unset($u);
}

// Summary stats
$stats_stmt = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN 1 ELSE 0 END) AS last_7d,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last_30d,
        SUM(email_verified) AS verified
    FROM users
");
$stats = $stats_stmt->fetch();

// Active user stats (users who have actually logged in)
$active_users_stmt = $db->query("
    SELECT
        COUNT(DISTINCT user_id) AS active_7d
    FROM login_log
    WHERE logged_in_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$active_7d = (int)$active_users_stmt->fetchColumn();

$active_30d_stmt = $db->query("
    SELECT
        COUNT(DISTINCT user_id) AS active_30d
    FROM login_log
    WHERE logged_in_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$active_30d = (int)$active_30d_stmt->fetchColumn();

// Users who never logged in (likely spam)
$never_logged_stmt = $db->query("
    SELECT COUNT(*) FROM users u
    LEFT JOIN login_log l ON l.user_id = u.id
    WHERE l.id IS NULL
");
$never_logged = (int)$never_logged_stmt->fetchColumn();

// Signups per site
$site_stmt = $db->query("
    SELECT COALESCE(signup_site, 'unknown') AS site, COUNT(*) AS cnt
    FROM users
    GROUP BY site
    ORDER BY cnt DESC
");
$sites = $site_stmt->fetchAll();

// Most active sites (by login count)
$active_stmt = $db->query("
    SELECT COALESCE(site, 'unknown') AS site, COUNT(*) AS cnt
    FROM login_log
    GROUP BY site
    ORDER BY cnt DESC
    LIMIT 10
");
$active_sites = $active_stmt->fetchAll();

// Recent signups (last 10)
$recent_stmt = $db->query("
    SELECT id, username, signup_site, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 10
");
$recent = $recent_stmt->fetchAll();

function ago($dt) {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return $diff . 's ago';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('Y-m-d', strtotime($dt));
}

function qs(array $override = []) {
    $params = array_merge($_GET, $override);
    unset($params['logout']);
    return '?' . http_build_query($params);
}

// Helper: generate toggle sort link for table headers
function sortLink($column, $asc_key, $desc_key, $label) {
    global $sort;
    $next = ($sort === $asc_key) ? $desc_key : $asc_key;
    $arrow = '';
    if ($sort === $asc_key) $arrow = ' &#9650;';
    elseif ($sort === $desc_key) $arrow = ' &#9660;';
    return '<a href="' . qs(['sort' => $next, 'page' => 1]) . '#users">' . $label . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Admin — DirectSponsor</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: .9rem;
    background: #f0f2f5;
    color: #222;
    margin: 0;
    padding: 0;
}
a { color: #4a4e9e; text-decoration: none; }
a:hover { text-decoration: underline; }

header {
    background: #1a1a2e;
    color: #fff;
    padding: .75rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
header h1 { margin: 0; font-size: 1.1rem; font-weight: 600; }
header a { color: #aab; font-size: .85rem; }

.wrap { max-width: 1200px; margin: 0 auto; padding: 1.25rem 1rem; }

/* Stats row */
.stats { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.stat-card {
    background: #fff;
    border-radius: 6px;
    padding: .75rem 1.1rem;
    flex: 1 1 120px;
    border-left: 4px solid #4a4e9e;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
}
.stat-card .num { font-size: 1.6rem; font-weight: 700; color: #1a1a2e; line-height: 1; }
.stat-card .lbl { font-size: .75rem; color: #666; margin-top: .2rem; }

/* Side panels — horizontal row */
.side-panels { display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.side-panels .card { flex: 1 1 200px; min-width: 180px; }

.card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    overflow: hidden;
}
.card-head {
    padding: .6rem .9rem;
    background: #f7f8fa;
    border-bottom: 1px solid #e8e8e8;
    font-weight: 600;
    font-size: .82rem;
    color: #444;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.card-body { padding: .75rem .9rem; }

/* Site breakdown list */
.site-list { list-style: none; margin: 0; padding: 0; }
.site-list li {
    display: flex;
    justify-content: space-between;
    padding: .3rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: .85rem;
}
.site-list li:last-child { border-bottom: none; }
.site-list .cnt { font-weight: 600; color: #1a1a2e; }

/* Recent signups */
.recent-list { list-style: none; margin: 0; padding: 0; }
.recent-list li {
    padding: .35rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: .85rem;
    display: flex;
    justify-content: space-between;
    gap: .5rem;
}
.recent-list li:last-child { border-bottom: none; }
.recent-list .meta { color: #888; font-size: .78rem; white-space: nowrap; }

/* Controls bar */
.controls {
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: .75rem;
}
.controls form { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; }
.controls input[type=search] {
    padding: .4rem .6rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: .85rem;
    width: 200px;
}
.controls select, .controls button {
    padding: .4rem .6rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: .85rem;
    background: #fff;
    cursor: pointer;
}
.controls button { background: #4a4e9e; color: #fff; border-color: #4a4e9e; }

/* Users table */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .84rem; }
th {
    background: #f7f8fa;
    border-bottom: 2px solid #e0e0e0;
    padding: .5rem .7rem;
    text-align: left;
    white-space: nowrap;
    font-size: .78rem;
    color: #555;
    text-transform: uppercase;
    letter-spacing: .04em;
}
th a { color: #4a4e9e; text-decoration: none; }
th a:hover { text-decoration: underline; }
td {
    padding: .45rem .7rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbff; }

.badge {
    display: inline-block;
    padding: .15rem .45rem;
    border-radius: 3px;
    font-size: .75rem;
    font-weight: 600;
}
.badge-ok  { background: #d4edda; color: #155724; }
.badge-no  { background: #f8d7da; color: #721c24; }
.badge-site { background: #e8eaf6; color: #3949ab; }

/* Pagination */
.pager { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .9rem; align-items: center; font-size: .85rem; }
.pager a, .pager span {
    padding: .3rem .6rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
}
.pager .cur { background: #4a4e9e; color: #fff; border-color: #4a4e9e; }
.pager .disabled { color: #bbb; }

.empty { color: #888; padding: 1rem; text-align: center; }
</style>
</head>
<body>

<header>
    <h1>DirectSponsor &mdash; User Admin</h1>
    <a href="<?php echo qs(['logout' => '1']); ?>">Sign out</a>
</header>

<div class="wrap">

    <!-- Stats row -->
    <div class="stats">
        <div class="stat-card">
            <div class="num"><?php echo number_format($stats['total']); ?></div>
            <div class="lbl">Total users</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo number_format($stats['last_7d']); ?></div>
            <div class="lbl">New last 7 days</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo number_format($stats['last_30d']); ?></div>
            <div class="lbl">New last 30 days</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo number_format($stats['verified']); ?></div>
            <div class="lbl">Email verified</div>
        </div>
        <div class="stat-card" style="border-left-color: #2e7d32;">
            <div class="num"><?php echo number_format($active_7d); ?></div>
            <div class="lbl">Active last 7 days</div>
        </div>
        <div class="stat-card" style="border-left-color: #2e7d32;">
            <div class="num"><?php echo number_format($active_30d); ?></div>
            <div class="lbl">Active last 30 days</div>
        </div>
        <div class="stat-card" style="border-left-color: #c62828;">
            <div class="num"><?php echo number_format($never_logged); ?></div>
            <div class="lbl">Never logged in</div>
        </div>
    </div>

    <!-- Side panels — horizontal -->
    <div class="side-panels">

        <!-- Signups by site -->
        <div class="card">
            <div class="card-head">Signups by site</div>
            <div class="card-body" style="padding: .5rem .9rem;">
                <?php if ($sites): ?>
                <ul class="site-list">
                    <?php foreach ($sites as $s): ?>
                    <li>
                        <a href="<?php echo qs(['sort' => $sort, 'q' => '', 'page' => 1]); ?>"><?php echo htmlspecialchars($s['site']); ?></a>
                        <span class="cnt"><?php echo number_format($s['cnt']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="empty">No data yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Most active sites (logins) -->
        <div class="card">
            <div class="card-head">Logins by site</div>
            <div class="card-body" style="padding: .5rem .9rem;">
                <?php if ($active_sites): ?>
                <ul class="site-list">
                    <?php foreach ($active_sites as $s): ?>
                    <li>
                        <span><?php echo htmlspecialchars($s['site']); ?></span>
                        <span class="cnt"><?php echo number_format($s['cnt']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="empty">No logins logged yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent signups -->
        <div class="card">
            <div class="card-head">Recent signups</div>
            <div class="card-body" style="padding: .5rem .9rem;">
                <?php if ($recent): ?>
                <ul class="recent-list">
                    <?php foreach ($recent as $r): ?>
                    <li>
                        <span><?php echo htmlspecialchars($r['username']); ?></span>
                        <span class="meta"><?php echo ago($r['created_at']); ?> &middot; <?php echo htmlspecialchars($r['signup_site'] ?? '?'); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="empty">No users yet</p>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- .side-panels -->

            <div class="card" id="users">
                <div class="card-head">
                    Users
                    <span style="font-weight:400; color:#888; margin-left:.5rem;">(<?php echo number_format($total_users); ?> total)</span>
                </div>
                <div class="card-body">

                    <!-- Controls -->
                    <div class="controls">
                        <form method="GET" action="">
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                            <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search username or email">
                            <button type="submit">Search</button>
                            <?php if ($search): ?>
                            <a href="<?php echo qs(['q' => '', 'page' => 1]); ?>" style="font-size:.82rem; color:#888;">Clear</a>
                            <?php endif; ?>
                        </form>
                        <form method="GET" action="">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="page" value="1">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                            <select name="sort" onchange="this.form.submit()">
                                <option value="newest"       <?php if ($sort==='newest')       echo 'selected'; ?>>Newest first</option>
                                <option value="oldest"       <?php if ($sort==='oldest')       echo 'selected'; ?>>Oldest first</option>
                                <option value="username"     <?php if ($sort==='username')     echo 'selected'; ?>>Username A–Z</option>
                                <option value="site"         <?php if ($sort==='site')         echo 'selected'; ?>>Signup site</option>
                                <option value="balance"      <?php if ($sort==='balance')      echo 'selected'; ?>>Highest balance</option>
                                <option value="balance_asc"  <?php if ($sort==='balance_asc')  echo 'selected'; ?>>Lowest balance</option>
                            </select>
                        </form>
                        <form method="GET" action="">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                            <input type="hidden" name="page" value="1">
                            <select name="filter" onchange="this.form.submit()">
                                <option value=""          <?php if ($filter==='')           echo 'selected'; ?>>All users</option>
                                <option value="active"    <?php if ($filter==='active')     echo 'selected'; ?>>Active (logged in)</option>
                                <option value="inactive"  <?php if ($filter==='inactive')   echo 'selected'; ?>>Never logged in</option>
                                <option value="unverified" <?php if ($filter==='unverified') echo 'selected'; ?>>Unverified email</option>
                            </select>
                        </form>
                    </div>

                    <!-- Table -->
                    <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo sortLink('id', 'id', 'id_desc', 'ID'); ?></th>
                                <th><?php echo sortLink('username', 'username', 'username_desc', 'Username'); ?></th>
                                <th><?php echo sortLink('verified', 'verified', 'verified_asc', 'Verified'); ?></th>
                                <th><?php echo sortLink('created', 'oldest', 'newest', 'Signed up'); ?></th>
                                <th><?php echo sortLink('site', 'site', 'site_desc', 'Signup site'); ?></th>
                                <th><?php echo sortLink('logins', 'logins', 'logins_asc', 'Logins'); ?></th>
                                <th style="text-align:right;"><?php echo sortLink('balance', 'balance', 'balance_asc', 'Balance'); ?></th>
                                <th><?php echo sortLink('last_login', 'last_login', 'last_login_asc', 'Last login'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($users): ?>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo (int)$u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td>
                                    <?php if ($u['email_verified']): ?>
                                        <span class="badge badge-ok">yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-no">no</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;" title="<?php echo htmlspecialchars($u['created_at'] ?? ''); ?>">
                                    <?php echo ago($u['created_at']); ?>
                                </td>
                                <td>
                                    <?php if ($u['signup_site']): ?>
                                        <span class="badge badge-site"><?php echo htmlspecialchars($u['signup_site']); ?></span>
                                    <?php else: ?>
                                        <span style="color:#bbb;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$u['login_count']; ?></td>
                                <td style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo number_format($u['balance'] ?? 0, 0); ?></td>
                                <td style="white-space:nowrap; color:#666;" title="<?php echo htmlspecialchars($u['last_login'] ?? ''); ?>">
                                    <?php echo ago($u['last_login']); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="empty">No users found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pager">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo qs(['page' => $page - 1]); ?>">&laquo; Prev</a>
                        <?php else: ?>
                            <span class="disabled">&laquo; Prev</span>
                        <?php endif; ?>

                        <?php
                        $window = 2;
                        for ($p = 1; $p <= $total_pages; $p++):
                            if ($p === 1 || $p === $total_pages || abs($p - $page) <= $window):
                        ?>
                            <?php if ($p === $page): ?>
                                <span class="cur"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a href="<?php echo qs(['page' => $p]); ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php
                            elseif (abs($p - $page) === $window + 1):
                                echo '<span class="disabled">&hellip;</span>';
                            endif;
                        endfor;
                        ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo qs(['page' => $page + 1]); ?>">Next &raquo;</a>
                        <?php else: ?>
                            <span class="disabled">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div><!-- .card-body -->
            </div><!-- .card -->

</div><!-- .wrap -->
</body>
</html>
