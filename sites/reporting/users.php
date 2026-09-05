<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 2 — user management.
 *
 * Admin-only CRUD over the users table. Every action is a plain form POST, so the
 * page is fully functional with JavaScript disabled — including the delete
 * confirmation, which is a real interstitial page rather than a confirm() dialog.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';

Auth::requireAdmin();

$me     = Auth::user();
$errors = [];
$ok     = null;

/* ------------------------------------------------------------- validation -- */

function valid_username(string $u): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $u);
}

/**
 * Guard against locking everyone out of user management.
 *
 * Demoting the last super_admin leaves a system nobody can administer, recoverable
 * only by hand-editing the database. Cheap to prevent, expensive to undo.
 *
 * On the UPDATE path this is live: an admin demoting themselves is a real and easy
 * mistake. On the DELETE path it is currently unreachable by construction — only a
 * super_admin can load this page, self-deletion is refused separately, so the actor
 * always counts as "another admin" for whoever they are deleting. It stays because
 * HW5 introduces roles that may reach user management without being super_admin,
 * at which point the delete path stops being safe by accident.
 */
function other_admins_exist(int $excludingId): bool
{
    $row = Db::one(
        "SELECT COUNT(*) AS n FROM users WHERE role = 'super_admin' AND id <> ?",
        [$excludingId]
    );
    return (int) ($row['n'] ?? 0) > 0;
}

/* ----------------------------------------------------------------- actions -- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $id       = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = (string) ($_POST['role'] ?? 'viewer');

        if (!valid_username($username)) {
            $errors[] = 'Username must be 3–64 characters, letters/digits/._- only.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!in_array($role, Auth::ROLES, true)) {
            $errors[] = 'Unknown role.';
        }
        if ($action === 'create' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($action === 'update' && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters (leave blank to keep the current one).';
        }
        // Demoting yourself out of the last admin seat locks the door from inside.
        if ($action === 'update' && $id === (int) $me['id']
            && $role !== 'super_admin' && !other_admins_exist($id)) {
            $errors[] = 'You are the only administrator — promote someone else before changing your own role.';
        }

        if ($errors === []) {
            try {
                if ($action === 'create') {
                    Db::conn()->prepare(
                        'INSERT INTO users (username, email, password_hash, role, created_at)
                         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
                    )->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                    $ok = 'Created ' . $username . '.';
                } else {
                    // is_admin is a generated column derived from role; it is never
                    // written directly and cannot drift out of step with it.
                    if ($password === '') {
                        Db::conn()->prepare(
                            'UPDATE users SET username = ?, email = ?, role = ?,
                                    updated_at = UTC_TIMESTAMP() WHERE id = ?'
                        )->execute([$username, $email, $role, $id]);
                    } else {
                        Db::conn()->prepare(
                            'UPDATE users SET username = ?, email = ?, role = ?,
                                    password_hash = ?, updated_at = UTC_TIMESTAMP()
                             WHERE id = ?'
                        )->execute([$username, $email, $role,
                                    password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                    $ok = 'Updated ' . $username . '.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That username or email is already taken.';
                } else {
                    error_log('[cse135/users] ' . $e->getMessage());
                    $errors[] = 'Could not save that user.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id === (int) $me['id']) {
            $errors[] = 'You cannot delete the account you are signed in as.';
        } elseif (!other_admins_exist($id)) {
            $errors[] = 'That is the only administrator account; deleting it would leave nobody able to manage users.';
        } else {
            $st = Db::conn()->prepare('DELETE FROM users WHERE id = ?');
            $st->execute([$id]);
            $ok = $st->rowCount() > 0 ? 'User deleted.' : 'That user no longer exists.';
        }
    }
}

/* -------------------------------------------------------------- page state -- */

$editing = null;
if (isset($_GET['edit'])) {
    $editing = Db::one('SELECT id, username, email, role FROM users WHERE id = ?',
                       [(int) $_GET['edit']]);
}

$confirming = null;
if (isset($_GET['delete'])) {
    $confirming = Db::one('SELECT id, username, email, role FROM users WHERE id = ?',
                          [(int) $_GET['delete']]);
}

$users = Db::all(
    'SELECT id, username, email, password_hash, role, is_admin, created_at, updated_at
       FROM users ORDER BY role DESC, username ASC'
);

layout_header('User management', [
    'subtitle' => 'Create, edit and remove accounts that can sign in to this dashboard.',
    'wide'     => true,
]);

foreach ($errors as $err) {
    echo '<p class="notice notice-error">' . e($err) . '</p>';
}
if ($ok !== null) {
    echo '<p class="notice notice-ok">' . e($ok) . '</p>';
}

/* ------------------------------------------------- delete confirmation step -- */
if ($confirming !== null):
?>
<div class="card">
  <h2>Delete this user?</h2>
  <p>You are about to permanently delete
     <strong><?= e($confirming['username']) ?></strong>
     (<?= e($confirming['email']) ?>, <?= e(str_replace('_', ' ', $confirming['role'])) ?>).
     This cannot be undone.</p>
  <form method="post" action="/users.php" style="display:flex;gap:10px;align-items:center">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= e((string) $confirming['id']) ?>">
    <button class="btn btn-danger" type="submit">Yes, delete</button>
    <a class="btn btn-quiet" href="/users.php">Cancel</a>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= $editing ? 'Edit user' : 'Add a user' ?></h2>
  <form class="stack" method="post" action="/users.php">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
<?php if ($editing): ?>
    <input type="hidden" name="id" value="<?= e((string) $editing['id']) ?>">
<?php endif; ?>
    <div class="filters" style="margin:0">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required
               value="<?= e($editing['username'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required
               value="<?= e($editing['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="password">Password<?= $editing ? ' (blank = unchanged)' : '' ?></label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
      </div>
      <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role">
<?php foreach (Auth::ROLES as $r): ?>
          <option value="<?= e($r) ?>"<?= ($editing['role'] ?? 'viewer') === $r ? ' selected' : '' ?>>
            <?= e(str_replace('_', ' ', $r)) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="min-width:auto">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create user' ?></button>
      </div>
<?php if ($editing): ?>
      <div class="field" style="min-width:auto">
        <a class="btn btn-quiet" href="/users.php">Cancel</a>
      </div>
<?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>All users</h2>
  <p class="card-question"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?>.
     The stored password hash is shown because the assignment asks for it — it is a
     one-way <code>password_hash()</code> digest, not a recoverable password.</p>
  <div class="scroll-x">
  <table class="data">
    <thead>
      <tr>
        <th>Username</th><th>Email</th><th>Role</th><th>Admin</th>
        <th>Password hash</th><th>Created</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['username']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="role">you</span>' : '' ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e(str_replace('_', ' ', $u['role'])) ?></td>
        <td><?= ((int) $u['is_admin'] === 1) ? 'yes' : 'no' ?></td>
        <td class="wrap"><code><?= e($u['password_hash']) ?></code></td>
        <td><?= e($u['created_at']) ?></td>
        <td>
          <a class="btn btn-quiet btn-small" href="/users.php?edit=<?= e((string) $u['id']) ?>">Edit</a>
<?php if ((int) $u['id'] !== (int) $me['id']): ?>
          <a class="btn btn-quiet btn-small" href="/users.php?delete=<?= e((string) $u['id']) ?>">Delete</a>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
layout_footer();
