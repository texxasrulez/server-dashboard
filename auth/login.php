<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

$PAGE_TITLE = 'Sign in';
$PAGE_CSS = 'assets/css/pages/login.css';
$ASSETS_PREFIX = '../assets'; // from auth/ to assets/

ensure_default_admin(); // default admin if empty
$first_admin = $_SESSION['__first_admin_password'] ?? null;
unset($_SESSION['__first_admin_password']);

$redirect = $_GET['redirect'] ?? 'index.php';
$err = null;

function safe_redirect_target($rel){
  // Allow in-project absolute (starts with '/') or relative like 'index.php'
  $rel = (string)$rel;
  if ($rel === '') return 'index.php';
  // Block external or traversal
  if (stripos($rel, '://') !== false) return 'index.php';
  if (strpos($rel, '..') !== false) return 'index.php';
  return $rel; // keep leading '/' if present
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Invalid session. Please try again.';
  } else {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if ($u === '' || $p === '') {
      $err = 'Please enter both username and password.';
    } else if (auth_login($u, $p)) {
      $t = safe_redirect_target($redirect);
      if (isset($t[0]) && $t[0] === '/') {
        header('Location: ' . $t); // absolute in-site path
      } else {
        header('Location: ../' . $t); // relative to project root
      }
      exit;
    } else {
      $err = 'Incorrect username or password.';
    }
  }
}

// After variables are ready, render the head
require_once __DIR__ . '/../includes/head_public.php';
?>
<div class="login-wrap">
  <h1 class="login-title">Admin Dashboard Sign in</h1>
  <?php if ($err): ?><div class="error"><?= h($err) ?></div><?php endif; ?>
  <?php if ($first_admin): ?>
    <div class="note">
      <div><strong>First‑time setup:</strong> A default admin was created.</div>
      <div>Username: <code>admin</code></div>
      <div>Temporary password: <code><?= h($first_admin) ?></code></div>
      <div class="muted">Sign in and change it under <em>Users</em> as soon as possible.</div>
    </div>
  <?php endif; ?>
  <form method="post" action="login.php?redirect=<?= h($redirect) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="form-row">
      <label>Username</label>
      <input type="text" name="username" autofocus required />
    </div>
    <div class="form-row">
      <label>Password</label>
      <input type="password" name="password" required />
    </div>
    <div class="form-row">
      <button class="btn" type="submit">Sign in</button>
    </div>
  </form>
  <div class="muted">Need help? Contact your maintainer.</div>
</div>
<script src="assets/js/pages/login.check-admin.js"></script>
<?php include __DIR__ . '/../includes/foot_public.php'; ?>
