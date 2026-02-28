<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-content">
        <!-- Sidebar Header with Logo (fixed) -->
        <div class="sidebar-nav perfect-scrollbar">
            <div class="nav-link  justify-content-between p-3">
                <a href="index.php" class="nav-link text-decoration-none">
                   <i class="bi bi-speedometer2"></i>
                        <span>Dashboard</span>
                </a>
           
            </div>
        </div>

        <!-- Scrollable Sidebar Menu -->
        <nav class="sidebar-nav perfect-scrollbar">
            <ul class="nav flex-column">
                
                <!-- DASHBOARD SECTION -->
               
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="bi bi-book"></i>
                        <span>Reports</span>
                    </a>
                </li>

                <!-- ADMINISTRATION SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">ADMINISTRATION</small>
                </li>
                
                <!-- Users Management -->
                <li class="nav-item">
                    <a class="nav-link" href="#usersSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-people"></i>
                        <span>User Management</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="usersSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="users.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Users</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="users-roles-permission.php">
                                    <i class="bi bi-circle"></i>
                                    <span>User Roles & Permissions</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="users-audit-logs.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Audit Logs</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Parties (Individuals/Organizations) -->
                <li class="nav-item">
                    <a class="nav-link" href="#partiesSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-person-badge"></i>
                        <span>Parties</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="partiesSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="parties.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Parties</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parties-individuals.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Individuals</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parties-organizations.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Organizations</span>
                                </a>
                            </li>
                           
                        </ul>
                    </div>
                </li>

                <!-- GEOGRAPHICAL HIERARCHY SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">GEOGRAPHICAL HIERARCHY</small>
                </li>

                <!-- States -->
                <li class="nav-item">
                    <a class="nav-link" href="states.php">
                        <i class="bi bi-map"></i>
                        <span>States</span>
                    </a>
                </li>

                <!-- Counties -->
                <li class="nav-item">
                    <a class="nav-link" href="counties.php">
                        <i class="bi bi-pin-map"></i>
                        <span>Counties</span>
                    </a>
                </li>

                <!-- Payams -->
                <li class="nav-item">
                    <a class="nav-link" href="payams.php">
                        <i class="bi bi-pin"></i>
                        <span>Payams</span>
                    </a>
                </li>

                <!-- Bomas -->
                <li class="nav-item">
                    <a class="nav-link" href="bomas.php">
                        <i class="bi bi-geo-alt"></i>
                        <span>Bomas</span>
                    </a>
                </li>

                <!-- LAND MANAGEMENT SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">LAND MANAGEMENT</small>
                </li>

                <!-- Parcels -->
                <li class="nav-item">
                    <a class="nav-link" href="#parcelsSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        <span>Parcels</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="parcelsSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="parcels.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Parcels</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parcels-create.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Register New Parcel</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parcels-map.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Parcel Map View</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parcels-history.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Parcel History</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Titles -->
                <li class="nav-item">
                    <a class="nav-link" href="#titlesSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Titles</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="titlesSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="titles.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Titles</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="titles-issue.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Issue New Title</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="titles-freehold.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Freehold Titles</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="titles-leasehold.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Leasehold Titles</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="titles-customary.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Customary Titles</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Ownerships -->
                <li class="nav-item">
                    <a class="nav-link" href="ownerships.php">
                        <i class="bi bi-person-check"></i>
                        <span>Ownerships</span>
                    </a>
                </li>

                <!-- Applications & Workflow -->
                <li class="nav-item">
                    <a class="nav-link" href="#applicationsSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-file-check"></i>
                        <span>Applications</span>
                        <span class="badge bg-primary rounded-pill ms-2 me-2">New</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="applicationsSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="applications.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Applications</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-new-title.php">
                                    <i class="bi bi-circle"></i>
                                    <span>New Title</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-transfer.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Transfer</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-subdivision.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Subdivision</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-consolidation.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Consolidation</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-lease.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Lease</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="applications-change-use.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Change of Use</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="workflow-steps.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Workflow Steps</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Transactions -->
                <li class="nav-item">
                    <a class="nav-link" href="transactions.php">
                        <i class="bi bi-arrow-left-right"></i>
                        <span>Transactions</span>
                    </a>
                </li>

                <!-- FINANCIAL SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">FINANCIAL</small>
                </li>

                <!-- Payments -->
                <li class="nav-item">
                    <a class="nav-link" href="#paymentsSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-credit-card"></i>
                        <span>Payments</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="paymentsSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="payments.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Payments</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="payments.php?id=pending">
                                    <i class="bi bi-circle"></i>
                                    <span>Pending Payments</span>
                                    <span class="badge bg-warning rounded-pill ms-auto">3</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="payments.php?id=completed">
                                    <i class="bi bi-circle"></i>
                                    <span>Completed Payments</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="payments.php?id=failed">
                                    <i class="bi bi-circle"></i>
                                    <span>Failed Payments</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Tax Assessments -->
                <li class="nav-item">
                    <a class="nav-link" href="#taxSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-calculator"></i>
                        <span>Tax Management</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="taxSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="tax.php?id=assessments">
                                    <i class="bi bi-circle"></i>
                                    <span>Tax Assessments</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="tax.php?id=payments">
                                    <i class="bi bi-circle"></i>
                                    <span>Tax Payments</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="tax.php?id=overdue">
                                    <i class="bi bi-circle"></i>
                                    <span>Overdue Taxes</span>
                                    <span class="badge bg-danger rounded-pill ms-auto">!</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="tax.php?id=reports">
                                    <i class="bi bi-circle"></i>
                                    <span>Tax Reports</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- LEGAL SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">LEGAL & DOCUMENTS</small>
                </li>

                <!-- Disputes -->
                <li class="nav-item">
                    <a class="nav-link" href="#disputesSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span>Disputes</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="disputesSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="disputes.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Disputes</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="disputes.php?id=open">
                                    <i class="bi bi-circle"></i>
                                    <span>Open Disputes</span>
                                    <span class="badge bg-danger rounded-pill ms-auto">3</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="disputes.php?id=resolved">
                                    <i class="bi bi-circle"></i>
                                    <span>Resolved Disputes</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Encumbrances -->
                <li class="nav-item">
                    <a class="nav-link" href="encumbrances.php">
                        <i class="bi bi-shield-exclamation"></i>
                        <span>Encumbrances</span>
                    </a>
                </li>

                <!-- Documents -->
                <li class="nav-item">
                    <a class="nav-link" href="#documentsSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-files"></i>
                        <span>Documents</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="documentsSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="documents.php">
                                    <i class="bi bi-circle"></i>
                                    <span>All Documents</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="documents.php?id=parcels">
                                    <i class="bi bi-circle"></i>
                                    <span>Parcel Documents</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="documents.php?id=title_deeds">
                                    <i class="bi bi-circle"></i>
                                    <span>Title Documents</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="documents.php?id=party">
                                    <i class="bi bi-circle"></i>
                                    <span>Party Documents</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- SURVEY & ZONING SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">SURVEY & ZONING</small>
                </li>

                <!-- Zoning -->
                <li class="nav-item">
                    <a class="nav-link" href="#zoningSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                        <i class="bi bi-bricks"></i>
                        <span>Zoning</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse" id="zoningSubmenu">
                        <ul class="nav nav-submenu">
                            <li class="nav-item">
                                <a class="nav-link" href="zoning.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Zoning Codes</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parcel-zoning-history.php">
                                    <i class="bi bi-circle"></i>
                                    <span>Parcel Zoning History</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Survey Records -->
                <li class="nav-item">
                    <a class="nav-link" href="survey-records.php">
                        <i class="bi bi-rulers"></i>
                        <span>Survey Records</span>
                    </a>
                </li>

                <!-- Valuations -->
                <li class="nav-item">
                    <a class="nav-link" href="valuations.php">
                        <i class="bi bi-cash-stack"></i>
                        <span>Valuations</span>
                    </a>
                </li>

                <!-- SYSTEM SECTION -->
                <li class="nav-item mt-3">
                    <small class="text-muted px-3 text-uppercase fw-bold">SYSTEM</small>
                </li>

                <!-- Audit Logs -->
                <li class="nav-item">
                    <a class="nav-link" href="audit-logs.php">
                        <i class="bi bi-journal-text"></i>
                        <span>Audit Logs</span>
                    </a>
                </li>

                <!-- System Settings -->
                <li class="nav-item">
                    <a class="nav-link" href="system-settings.php">
                        <i class="bi bi-gear-wide"></i>
                        <span>System Settings</span>
                    </a>
                </li>

                <!-- Reports -->
                <li class="nav-item">
                    <a class="nav-link" href="reports.php" 
                    >
                        <i class="bi bi-graph-up"></i>
                        <span>Reports</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                   
                </li>
            </ul>
        </nav>
    </div>
</aside>