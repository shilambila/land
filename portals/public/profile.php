<?php
require_once __DIR__ . '/../common/bootstrap.php';
require_once __DIR__ . '/../common/layout.php';

ensureRoleAccess(['public','admin']);

$title = ucfirst(str_replace('_', ' ', 'public')) . ' Portal Profile';

$user = fetchOne($conn, 'SELECT id, username, role, email, last_login, created_at FROM users WHERE id = ?', [$_SESSION['user_id']]);

renderPortalHeader($title);
?>
<h1 class="h3 mb-3"><?php echo htmlspecialchars($title); ?></h1>
<div class="card">
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">User ID</dt><dd class="col-sm-9"><?php echo htmlspecialchars((string)$user['id']); ?></dd>
      <dt class="col-sm-3">Username</dt><dd class="col-sm-9"><?php echo htmlspecialchars($user['username']); ?></dd>
      <dt class="col-sm-3">Role</dt><dd class="col-sm-9"><?php echo htmlspecialchars($user['role']); ?></dd>
      <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?php echo htmlspecialchars((string)($user['email'] ?? 'N/A')); ?></dd>
      <dt class="col-sm-3">Last login</dt><dd class="col-sm-9"><?php echo htmlspecialchars((string)($user['last_login'] ?? 'N/A')); ?></dd>
    </dl>
  </div>
</div>
<?php renderPortalFooter(); ?>
