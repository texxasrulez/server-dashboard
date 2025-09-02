<?php
$PAGE_TITLE = 'Users';
$PAGE_CSS   = 'assets/css/pages/users.css';
$PAGE_JS    = 'assets/js/pages/users.profile.js'; // page-scoped JS loaded via includes/foot.php
include __DIR__.'/includes/head.php';
require_once __DIR__ . '/includes/auth.php';


$isAdmin = user_is_admin();
$me      = current_user();
$amsg=$aerr=$msg=$err=null;

// Ensure the session user includes the latest saved profile so the page
// reflects what's in data/users.json even on a fresh visit or reload.
if (is_array($me) && (!isset($me['profile']) || !is_array($me['profile']))) {
  $__rec = user_find($me['username']);
  if (is_array($__rec) && isset($__rec['profile']) && is_array($__rec['profile'])) {
    $me['profile'] = $__rec['profile'];
  }
}


// Ensure the session user includes the latest saved profile so the page
// reflects what's in data/users.json even on a fresh visit or reload.
if (is_array($me) && (!isset($me['profile']) || !is_array($me['profile']))) {
  $__rec = user_find($me['username']);
  if (is_array($__rec) && isset($__rec['profile']) && is_array($__rec['profile'])) {
    $me['profile'] = $__rec['profile'];
  }
}


/* helpers */
function sanitize_url($u){ $u = trim((string)$u); return $u!=='' && !filter_var($u, FILTER_VALIDATE_URL) ? null : $u; }
function sanitize_email($e){ $e = trim((string)$e); return $e!=='' && !filter_var($e, FILTER_VALIDATE_EMAIL) ? null : $e; }

/* admin-only actions */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['admin_panel'])) {
  if (!csrf_check($_POST['csrf']??''))      $aerr='Invalid session.';
  elseif (!$isAdmin)                        $aerr='Admin only.';
  else {
    $act = $_POST['admin_panel'];
    try {
      if ($act==='test_storage') {
        $data = users_load(); users_save($data); $amsg='Storage test write OK.';
      } elseif ($act==='add_user') {
        [$ok,$msgx] = user_add($_POST['username']??'', $_POST['password']??'', ($_POST['role']??'user'));
        $ok ? $amsg=$msgx : $aerr=$msgx;
      } elseif ($act==='delete_user') {
        [$ok,$msgx] = user_delete($_POST['username']??''); $ok ? $amsg=$msgx : $aerr=$msgx;
      } elseif ($act==='set_role') {
        [$ok,$msgx] = user_set_role($_POST['username']??'', $_POST['role']??'user'); $ok ? $amsg=$msgx : $aerr=$msgx;
      }
    } catch (Throwable $e) { $aerr = $e->getMessage(); }
  }
}

/* self: profile save */
if (isset($_POST['action']) && $_POST['action']==='save_profile') {
  if (!csrf_check($_POST['csrf']??'')) { $err='Invalid session.'; }
  else {
    $first = trim((string)($_POST['first_name']??''));
    $last  = trim((string)($_POST['last_name']??''));
    $email = sanitize_email($_POST['email']??'');
    $site  = sanitize_url($_POST['website']??'');
    $tw    = sanitize_url($_POST['twitter']??'');
    $gh    = sanitize_url($_POST['github']??'');
    $mdn   = sanitize_url($_POST['mastodon']??'');
    $li    = sanitize_url($_POST['linkedin']??'');
    $avatar= sanitize_url($_POST['avatar_url']??'');
    $bio   = trim((string)($_POST['bio']??''));
    $loc   = trim((string)($_POST['location']??''));
    $tz    = trim((string)($_POST['timezone']??''));

    if ($email===null) $err = 'Please enter a valid email address (or leave blank).';
    if (!$err && in_array(null, [$site,$tw,$gh,$mdn,$li,$avatar], true)) $err = 'One or more links are invalid. Use full URLs (https://…).';

    if (!$err) {
      $data = users_load();
      $un = strtolower($me['username']);
      foreach ($data['users'] as &$u) {
        if (strtolower($u['username']) === $un) {
          if (!isset($u['profile']) || !is_array($u['profile'])) $u['profile'] = [];
          $u['profile'] = array_merge($u['profile'], [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string)($email ?? ''),
            'website'    => (string)($site ?? ''),
            'avatar_url' => (string)($avatar ?? ''),
            'bio'        => $bio,
            'location'   => $loc,
            'timezone'   => $tz,
            'socials'    => [
              'twitter'  => (string)($tw ?? ''),
              'github'   => (string)($gh ?? ''),
              'mastodon' => (string)($mdn ?? ''),
              'linkedin' => (string)($li ?? ''),
            ],
          ]);
          break;
        }
      }
      unset($u);
      users_save($data);
$msg = 'Profile updated.';
// Refresh session user with latest profile so the page reflects changes immediately
$updated = user_find($me['username']);
if ($updated) {
  $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], [
    'username' => $updated['username'],
    'role' => $updated['role'] ?? 'user',
    'profile' => $updated['profile'] ?? []
  ]);
  $me = $_SESSION['user'];
} else {
  $me = current_user();
}
}
  }
}

/* self: change password */
if (isset($_POST['action']) && $_POST['action']==='change_pass') {
  if (!csrf_check($_POST['csrf']??'')) { $err='Invalid session.'; }
  else {
    $cur = (string)($_POST['current']??'');
    $n1  = (string)($_POST['new1']??'');
    $n2  = (string)($_POST['new2']??'');
    if ($n1 === '' || $n1 !== $n2) $err = 'Passwords do not match.';
    else {
      $record = user_find($me['username']);
      if (!$record || !password_verify($cur, $record['password_hash'])) $err = 'Current password is incorrect.';
      else {
        $data = users_load();
        foreach ($data['users'] as &$uu) {
          if (strtolower($uu['username'])===strtolower($me['username'])) {
            $uu['password_hash']=password_hash($n1,PASSWORD_DEFAULT); break;
          }
        }
        unset($uu);
        users_save($data);
        $msg = 'Password updated.';
      }
    }
  }
}

/* data for page */
$data = users_load();
$p = array_merge([
  'first_name'=>'','last_name'=>'','email'=>'','website'=>'','avatar_url'=>'',
  'bio'=>'','location'=>'','timezone'=>'',
  'socials'=>['twitter'=>'','github'=>'','mastodon'=>'','linkedin'=>'']
], (array)(user_profile_of($me['username'] ?? '') ?? ($me['profile'] ?? [])));
if (!isset($p['socials'])) $p['socials']=['twitter'=>'','github'=>'','mastodon'=>'','linkedin'=>''];
?>
<div class="users-page">

  <?php if ($isAdmin): ?>
  <div class="card users-admin">
    <div class="card-title">User Administration <span class="chip">Admin only</span></div>
    <?php if ($amsg): ?><div class="note"><?= h($amsg) ?></div><?php endif; ?>
    <?php if ($aerr): ?><div class="error"><?= h($aerr) ?></div><?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr class="muted"><th>Username</th><th>Role</th><th class="actions">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($data['users'] as $u): ?>
          <tr>
            <td><?= h($u['username']) ?></td>
            <td>
              <form method="post" class="form-actions">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="admin_panel" value="set_role">
                <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                <select name="role">
                  <option value="user"  <?= ($u['role']??'user')==='user'  ? 'selected':'' ?>>user</option>
                  <option value="admin" <?= ($u['role']??'user')==='admin' ? 'selected':'' ?>>admin</option>
                </select>
                <button class="btn" type="submit">Update</button>
              </form>
            </td>
            <td class="actions">
              <?php if (strtolower($_SESSION['user']['username']??'') !== strtolower($u['username'])): ?>
              <form method="post" onsubmit="return confirm('Delete user <?= h($u['username']) ?>?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="admin_panel" value="delete_user">
                <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                <button class="btn" type="submit">Delete</button>
              </form>
              <?php else: ?>
                <span class="muted">current user</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-add">
      <div class="card-subtitle">Add User</div>
      <form method="post" class="admin-add-form form-actions">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="admin_panel" value="add_user">
        <div class="form-row"><label>Username</label><input type="text" name="username" placeholder="Username"></div>
        <div class="form-row"><label>Password</label><input type="password" name="password" placeholder="Password"></div>
        <div class="form-row"><label>Role</label>
        <select name="role"><option value="user">user</option><option value="admin">admin</option></select></div>
        <button class="btn" type="submit">Create</button>
      </form>
      <div class="muted">Passwords are stored using PHP password hashing.</div>
    </div>

    <div class="card users-storage">
      <div class="card-title">Users Storage (debug)</div>
      <?php
        $u_path = defined('USERS_FILE') ? USERS_FILE : (__DIR__ . '/data/users.json');
        $u_real = @realpath($u_path) ?: $u_path;
        $u_exists = file_exists($u_path);
        $u_dir = dirname($u_path);
        $u_dir_w = is_writable($u_dir);
        $u_file_w = $u_exists ? is_writable($u_path) : $u_dir_w;
        $u_size = $u_exists ? filesize($u_path) : 0;
        $u_mtime = $u_exists ? date('Y-m-d H:i:s', filemtime($u_path)) : '—';
      ?>
      <ul class="small users-meta">
        <li>Resolved path: <code><?= h($u_real) ?></code></li>
        <li>Directory writable: <strong><?= $u_dir_w ? 'yes' : 'no' ?></strong></li>
        <li>File exists: <strong><?= $u_exists ? 'yes' : 'no' ?></strong>; writable: <strong><?= $u_file_w ? 'yes' : 'no' ?></strong></li>
        <li>Size: <?= number_format($u_size) ?> bytes; Updated: <?= h($u_mtime) ?></li>
      </ul>
      <form method="post" class="form-inline mt-6">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="admin_panel" value="test_storage">
        <button class="btn" type="submit">Test write</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Self-service -->
  <div class="users-grid">
    <div class="card profile-card">
      <div class="card-title">My Profile</div>
      <?php if ($msg && (!isset($_POST['action']) || $_POST['action']==='save_profile')): ?>
        <div class="note"><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if ($err && (isset($_POST['action']) && $_POST['action']==='save_profile')): ?>
        <div class="error"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="profile-preview">
        <img id="avatarPreview" src="<?= h(user_avatar_url($me, 96)) ?>" data-fallback="<?= h(project_url('/assets/images/avatar-default.png')) ?>" alt="Avatar preview" />
        <div class="small muted">Avatar preview (uses Avatar URL if present, otherwise Gravatar from Email).</div>
      </div>

      <form method="post" class="profile-form" novalidate>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_profile">

        <div class="grid-2">
          <div class="form-row"><label>First name</label><input type="text" name="first_name" value="<?= h($p['first_name']) ?>"></div>
          <div class="form-row"><label>Last name</label><input type="text" name="last_name" value="<?= h($p['last_name']) ?>"></div>
        </div>

        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= h($p['email']) ?>"></div>
        <div class="form-row"><label>Website</label><input type="url" name="website" value="<?= h($p['website']) ?>" placeholder="https://…"></div>
        <div class="form-row"><label>Avatar URL</label><input type="url" name="avatar_url" value="<?= h($p['avatar_url']) ?>" placeholder="https://…"></div>

        <div class="grid-2">
          <div class="form-row"><label>Location</label><input type="text" name="location" value="<?= h($p['location']) ?>"></div>
          <div class="form-row"><label>Timezone</label><input type="text" name="timezone" value="<?= h($p['timezone']) ?>" placeholder="e.g. America/Chicago"></div>
        </div>

        <div class="form-row"><label>Bio</label><textarea name="bio" rows="4"><?= h($p['bio']) ?></textarea></div>

        <div class="card-subtitle">Social</div>
        <div class="grid-2">
          <div class="form-row"><label>Twitter</label><input type="url" name="twitter" value="<?= h($p['socials']['twitter']??'') ?>" placeholder="https://twitter.com/…"></div>
          <div class="form-row"><label>GitHub</label><input type="url" name="github" value="<?= h($p['socials']['github']??'') ?>" placeholder="https://github.com/…"></div>
          <div class="form-row"><label>Mastodon</label><input type="url" name="mastodon" value="<?= h($p['socials']['mastodon']??'') ?>" placeholder="https://mastodon.social/@…"></div>
          <div class="form-row"><label>LinkedIn</label><input type="url" name="linkedin" value="<?= h($p['socials']['linkedin']??'') ?>" placeholder="https://www.linkedin.com/in/…"></div>
        </div>

        <div class="form-actions mt-6">
          <button class="btn" type="submit">Save profile</button>
        </div>
      </form>
    </div>

    <div class="card users-card">
      <div class="card-title">Change Password</div>
      <?php if ($msg && (isset($_POST['action']) && $_POST['action']==='change_pass')): ?>
        <div class="note"><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if ($err && (isset($_POST['action']) && $_POST['action']==='change_pass')): ?>
        <div class="error"><?= h($err) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_pass">
        <div class="form-row"><label>Current password</label><input type="password" name="current" required /></div>
        <div class="form-row"><label>New password</label><input type="password" name="new1" required /></div>
        <div class="form-row"><label>Confirm new password</label><input type="password" name="new2" required /></div>
        <div class="form-actions"><button class="btn"  type="submit">Save</button></div>
        <div class="muted">Signed in as <?= h($_SESSION['user']['username']??'') ?> (<?= h($_SESSION['user']['role']??'user') ?>).</div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/foot.php'; ?>
