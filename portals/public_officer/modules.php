<?php
require_once __DIR__ . '/../common/bootstrap.php';
require_once __DIR__ . '/../common/layout.php';

ensureRoleAccess(['public_officer','admin']);

$modules = getRoleModulePermissions($conn, 'public_officer');
$title = ucfirst(str_replace('_', ' ', 'public_officer')) . ' Portal Modules';

renderPortalHeader($title);
?>
<h1 class="h3 mb-3"><?php echo htmlspecialchars($title); ?></h1>
<div class="card">
  <div class="card-body">
    <p class="text-muted">Permissions are loaded from <code>permissions</code> and <code>role_permissions</code> tables.</p>
    <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr><th>Module</th><th>Permission</th><th>Key</th></tr></thead>
        <tbody>
          <?php foreach ($modules as $module): ?>
            <tr>
              <td><?php echo htmlspecialchars($module['module']); ?></td>
              <td><?php echo htmlspecialchars($module['permission_name']); ?></td>
              <td><code><?php echo htmlspecialchars($module['permission_key']); ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php renderPortalFooter(); ?>
