<?php
require_once 'config.php';

// --- Simple admin authentication ---
// Set ADMIN_PASSWORD in config.local.php as: define('ADMIN_PASSWORD', 'yourpassword');
// Falls back to a hard-coded env check if not defined.
if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

session_start();

$auth_error = '';

if (isset($_POST['admin_password'])) {
    if ($_POST['admin_password'] === ADMIN_PASSWORD) {
        $_SESSION['admin_authed'] = true;
    } else {
        $auth_error = 'Wrong password.';
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
$order_clause = match($sort) {
    'oldest'   => 'ORDER BY u.created_at ASC',
    'username' => 'ORDER BY u.username ASC',
    'site'     => 'ORDER BY u.signup_site ASC, u.created_at DESC',
    default    => 'ORDER BY u.created_at DESC',
};

// Search
$search = trim($_GET['q'] ?? '');
$where  = '';
$params = [];
if ($search !== '') {
    $where    = 'WHERE u.username LIKE ? OR u.email LIKE ?';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// Pagination
$per_page    = 50;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

$count_stmt = $db->prepare("SELECT COUNT(*) FROM users u $where");
$count_stmt->execute($params);
$total_users = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_users / $per_page));

$users_stmt = $db->prepare("
    SELECT u.id, u.username, u.email, u.email_verified,
           u.created_at, u.signup_site,
           COUNT(l.id) AS login_count,
           MAX(l.logged_in_at) AS last_login
    FROM users u
    LEFT JOIN login_log l ON l.user_id = u.id
    $where
    GROUP BY u.id
    $order_clause
    LIMIT $per_page OFFSET $offset
");
$users_stmt->execute($params);
$users = $users_stmt->fetchAll();

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

/* Two-column layout */
.cols { display: flex; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.col-side { flex: 0 0 220px; display: flex; flex-direction: column; gap: 1rem; }
.col-main { flex: 1 1 500px; }

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
th a { color: #4a4e9e; }
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
    </div>

    <!-- Side panels + main table -->
    <div class="cols">

        <div class="col-side">

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

        </div><!-- .col-side -->

        <div class="col-main">
            <div class="card">
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
                            <select name="sort" onchange="this.form.submit()">
                                <option value="newest"   <?php if ($sort==='newest')   echo 'selected'; ?>>Newest first</option>
                                <option value="oldest"   <?php if ($sort==='oldest')   echo 'selected'; ?>>Oldest first</option>
                                <option value="username" <?php if ($sort==='username') echo 'selected'; ?>>Username A–Z</option>
                                <option value="site"     <?php if ($sort==='site')     echo 'selected'; ?>>Signup site</option>
                            </select>
                        </form>
                    </div>

                    <!-- Table -->
                    <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Verified</th>
                                <th>Signed up</th>
                                <th>Signup site</th>
                                <th>Logins</th>
                                <th>Last login</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($users): ?>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo (int)$u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td style="color:#555;"><?php echo htmlspecialchars($u['email']); ?></td>
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
        </div><!-- .col-main -->

    </div><!-- .cols -->

</div><!-- .wrap -->
</body>
</html>
