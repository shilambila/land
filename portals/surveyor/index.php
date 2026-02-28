<?php
require_once __DIR__ . '/../common/bootstrap.php';
require_once __DIR__ . '/../common/layout.php';

ensureRoleAccess(['surveyor','admin']);

$stats = getPortalStats($conn);
$title = ucfirst(str_replace('_', ' ', 'surveyor')) . ' Portal Dashboard';

renderPortalHeader($title);
?>
<div class="row mb-4">
  <div class="col-12">
    <h1 class="h3 mb-1"><?php echo htmlspecialchars($title); ?></h1>
    <p class="text-muted mb-0">Dedicated folder-based portal using the same database connection and role access control.</p>
  </div>
</div>
<div class="row g-3">
  <div class="col-md-3"><div class="card"><div class="card-body"><h6>Total Users</h6><h3><?php echo number_format($stats['users']); ?></h3></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><h6>Total Parcels</h6><h3><?php echo number_format($stats['parcels']); ?></h3></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><h6>Total Applications</h6><h3><?php echo number_format($stats['applications']); ?></h3></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><h6>Completed Payments</h6><h3>SSP <?php echo number_format($stats['payments'], 2); ?></h3></div></div></div>
</div>
<?php renderPortalFooter(); ?>
