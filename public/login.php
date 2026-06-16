<?php
/**
 * Login form + handler for the dashboard.
 */

use CompanyFinder\Auth;

$config = require __DIR__ . '/../src/bootstrap.php';
$auth = new Auth($config['auth']);

// Auth disabled, or already signed in -> go straight to the dashboard.
if ($auth->check()) {
    header('Location: index.php');
    exit;
}

$next = $_GET['next'] ?? 'index.php';
// Only allow relative redirect targets to avoid open-redirects.
if (!is_string($next) || $next === '' || preg_match('#^https?://#i', $next) || str_starts_with($next, '//')) {
    $next = 'index.php';
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ok = $auth->attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($ok) {
        header('Location: ' . $next);
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — CompanyFinder</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
    <form class="login-card" method="post" action="login.php?next=<?= htmlspecialchars(rawurlencode($next)) ?>">
        <h1>CompanyFinder</h1>
        <p class="sub">Sign in to continue</p>
        <?php if ($error !== ''): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <label>Username
            <input type="text" name="username" autocomplete="username" autofocus required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="primary">Sign in</button>
    </form>
</body>
</html>
