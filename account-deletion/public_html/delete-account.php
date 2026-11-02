<?php
/**
 * Delete Account page — JWT-verified, grace-period flow.
 *
 * Sites link here as: delete-account.php?token=<jwt>&return_url=<url>
 * (same pattern as change-password.php). The token is verified server-side;
 * user_id is never taken from a URL/body parameter.
 */

require_once 'config.php';
require_once 'includes/deletion-auth.php';

$token = $_GET['token'] ?? '';
$returnUrl = $_GET['return_url'] ?? '';
if ($returnUrl && !validateRedirectUri($returnUrl)) {
    $returnUrl = '';
}

$payload = deletionVerifyJWT($token);
$username = $payload['username'] ?? '';
$request = $payload ? deletionLoadRequest($payload['sub']) : null;
$pending = $request && $request['status'] === 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Account Data - Auth Server</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f8f9fa; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 20px 0; color: #856404; }
        .danger { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 6px; margin: 20px 0; color: #721c24; }
        button { padding: 12px 24px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; margin: 5px; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .result { margin-top: 20px; padding: 15px; border-radius: 6px; display: none; }
        .success { background: #d4edda; color: #155724; display: block; }
        .error { background: #f8d7da; color: #721c24; display: block; }
    </style>
</head>
<body>
<div class="container">
    <h1>Delete Account Data</h1>

<?php if (!$payload): ?>
    <div class="danger">
        <b>Not signed in.</b> This page needs a valid login token from one of our sites.
        Sign in on the site you came from and use its delete-account button again.
    </div>
<?php elseif ($pending): ?>
    <div class="warning">
        <b>Deletion already scheduled for <?= htmlspecialchars(date('Y-m-d', $request['execute_at'])) ?>.</b>
        Your data will be permanently deleted after that date.
        Changed your mind? You can cancel below — after the scheduled date it's too late.
    </div>
    <button class="btn-secondary" id="cancel-btn">Cancel deletion</button>
<?php else: ?>
    <p>Signed in as <b><?= htmlspecialchars($username) ?></b>.</p>
    <div class="warning">
        <b>What happens:</b> your account data (balance, profile, tokens, avatar, chat history)
        will be scheduled for deletion. It is <b>not deleted immediately</b> — you have
        <?= DELETION_GRACE_DAYS ?> days to change your mind and cancel from this page.
        An email notice is sent to your account address as confirmation.
    </div>
    <div class="danger">
        After the <?= DELETION_GRACE_DAYS ?>-day wait the files are moved to a 30-day trash,
        then permanently gone. Deleted accounts cannot sign in or recover balances.
    </div>
    <button class="btn-danger" id="delete-btn">Schedule my account for deletion</button>
<?php endif; ?>

<?php if ($returnUrl): ?>
    <p><a href="<?= htmlspecialchars($returnUrl) ?>">← Back to site</a></p>
<?php endif; ?>

    <div class="result" id="result"></div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
const API = 'api/';

async function call(endpoint) {
    const res = await fetch(API + endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({token: TOKEN})
    });
    return res.json();
}

function show(data) {
    const el = document.getElementById('result');
    el.className = 'result ' + (data.success ? 'success' : 'error');
    el.textContent = data.error || data.message || (data.execute_date ? 'Scheduled for ' + data.execute_date : 'Done');
}

document.getElementById('delete-btn')?.addEventListener('click', async () => {
    if (!confirm('Schedule your account for deletion?')) return;
    show(await call('delete-user-account.php'));
    setTimeout(() => location.reload(), 1500);
});

document.getElementById('cancel-btn')?.addEventListener('click', async () => {
    show(await call('cancel-deletion.php'));
    setTimeout(() => location.reload(), 1500);
});
</script>
</body>
</html>
