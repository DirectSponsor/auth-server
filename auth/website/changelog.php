<?php
/**
 * Meta-Changelog Aggregator
 *
 * Fetches changelog.html from each site, extracts the <!-- EMBED:changelog -->
 * block, merges all entries, sorts by date, and renders them as a single page.
 *
 * Caching: results are cached for 1 hour in /tmp to avoid fetching on every visit.
 *
 * Note (Option B - not implemented): if near-instant updates are ever needed,
 * each site's deploy.sh could ping a cache-clearing endpoint here after deploying.
 * For an informational changelog, hourly cache is perfectly adequate.
 */

define('CACHE_FILE', sys_get_temp_dir() . '/meta-changelog-cache.html');
define('CACHE_TTL',  3600); // 1 hour

$sites = [
    'ROFLFaucet'      => 'https://roflfaucet.com/changelog.html',
    'DirectSponsor'   => 'https://directsponsor.net/changelog.html',
    'ClickForCharity' => 'https://clickforcharity.net/changelog.html',
];

// Serve from cache if fresh
if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    echo file_get_contents(CACHE_FILE);
    exit;
}

// Fetch and parse each site's changelog
$all_entries = [];
$fetch_errors = [];

foreach ($sites as $site_name => $url) {
    $html = @file_get_contents($url);
    if ($html === false) {
        $fetch_errors[] = $site_name;
        continue;
    }
    // Extract content between EMBED tags
    if (preg_match('/<!--\s*EMBED:changelog\s*-->(.*?)<!--\s*\/EMBED:changelog\s*-->/s', $html, $m)) {
        // Extract individual <li> entries
        preg_match_all('/<li>.*?<\/li>/s', $m[1], $items);
        foreach ($items[0] as $li) {
            // Extract date for sorting (first YYYY-MM-DD in the entry)
            $date = '0000-00-00';
            if (preg_match('/<strong>(\d{4}-\d{2}-\d{2})<\/strong>/', $li, $d)) {
                $date = $d[1];
            }
            $all_entries[] = ['date' => $date, 'html' => $li];
        }
    }
}

// Sort all entries newest first
usort($all_entries, fn($a, $b) => strcmp($b['date'], $a['date']));

// Pagination
$per_page = 50;
$total    = count($all_entries);
$pages    = max(1, (int) ceil($total / $per_page));
$page     = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$offset   = ($page - 1) * $per_page;
$entries  = array_slice($all_entries, $offset, $per_page);

// Build HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Sites Changelog</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 860px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            margin-top: 0;
            color: #667eea;
            text-align: center;
        }
        .intro {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .site-links {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .site-links a {
            display: inline-block;
            padding: 6px 14px;
            border: 1px solid #667eea;
            border-radius: 4px;
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
        }
        .site-links a:hover { background: #667eea; color: white; }
        ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        li {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            line-height: 1.6;
            font-size: 14px;
        }
        li:last-child { border-bottom: none; }
        strong { color: #667eea; font-weight: 600; }
        .feature {
            color: #27ae60;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 8px;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 5px 11px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none;
            color: #667eea;
        }
        .pagination span { background: #667eea; color: white; border-color: #667eea; }
        .error-note {
            font-size: 12px;
            color: #c0392b;
            text-align: center;
            margin-bottom: 16px;
        }
        .cached-note {
            font-size: 11px;
            color: #aaa;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 All Sites Changelog</h1>
    <p class="intro">Combined updates from across the network — newest first</p>

    <div class="site-links">
        <a href="https://roflfaucet.com/changelog.html" target="_blank" rel="noopener">ROFLFaucet</a>
        <a href="https://directsponsor.net/changelog.html" target="_blank" rel="noopener">DirectSponsor</a>
        <a href="https://clickforcharity.net/changelog.html" target="_blank" rel="noopener">ClickForCharity</a>
    </div>

    <?php if ($fetch_errors): ?>
    <p class="error-note">Could not reach: <?= htmlspecialchars(implode(', ', $fetch_errors)) ?> (showing cached or partial data)</p>
    <?php endif; ?>

    <?php if (empty($entries)): ?>
    <p style="text-align:center;color:#888;">No changelog entries found.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($entries as $e): ?>
        <?= $e['html'] . "\n" ?>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">← Newer</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?>
                <span><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?>">Older →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p class="cached-note">Updated hourly &middot; <?= $total ?> total entries</p>
</div>
</body>
</html>
<?php
$output = ob_get_clean();

// Save to cache
file_put_contents(CACHE_FILE, $output);

echo $output;
