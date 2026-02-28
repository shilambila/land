  <footer class="admin-footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-0 text-muted">© 2026 SOUTH SUDAN LAND MANAGEMENT SYSTEM</p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <p class="mb-0 text-muted">Built  by <a href="https://nethub.world/" target="_blank" rel="noopener noreferrer">Nethub world Technologies</a></p>
                        </div>
                    </div>
                </div>
            </footer>
            <style>/* Green Shadow Effects for Analytics Dashboard */

/* Soft green glow for metric cards */
.metric-card {
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
}

.metric-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px -5px rgba(34, 197, 94, 0.3), 0 8px 10px -6px rgba(34, 197, 94, 0.2);
}

/* Card hover effects with green shadow */
.card {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.card:hover {
    box-shadow: 0 20px 30px -10px rgba(34, 197, 94, 0.25), 0 10px 15px -5px rgba(34, 197, 94, 0.2);
}

/* Specific green shadow variations */
.card.revenue:hover {
    box-shadow: 0 25px 30px -12px rgba(34, 197, 94, 0.35);
}

.card.visitors:hover {
    box-shadow: 0 25px 30px -12px rgba(16, 185, 129, 0.35);
}

.card.conversion:hover {
    box-shadow: 0 25px 30px -12px rgba(5, 150, 105, 0.35);
}

.card.bounce:hover {
    box-shadow: 0 25px 30px -12px rgba(4, 120, 87, 0.35);
}

/* Chart containers with subtle green shadow on hover */
.chart-container {
    transition: all 0.3s ease;
    border-radius: 0.75rem;
}

.chart-container:hover {
    box-shadow: 0 15px 25px -8px rgba(34, 197, 94, 0.2);
}

/* Stats icons with green shadow */
.stats-icon {
    transition: all 0.3s ease;
}

.stats-icon:hover {
    box-shadow: 0 10px 15px -3px rgba(34, 197, 94, 0.3);
    transform: scale(1.05);
}

/* Table rows with green glow on hover */
.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background-color: rgba(34, 197, 94, 0.05);
    box-shadow: 0 4px 8px -2px rgba(34, 197, 94, 0.15);
    transform: translateX(2px);
}

/* Geographic items with green shadow */
.border-bottom {
    transition: all 0.2s ease;
}

.border-bottom:hover {
    background-color: rgba(34, 197, 94, 0.03);
    box-shadow: 0 2px 8px -2px rgba(34, 197, 94, 0.15);
    transform: translateX(3px);
}

/* Device icons with green shadow */
.device-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    transition: all 0.3s ease;
}

.device-icon:hover {
    box-shadow: 0 8px 12px -4px rgba(34, 197, 94, 0.3);
    transform: scale(1.1);
}

/* Button hover effects with green shadow */
.btn-group .btn {
    transition: all 0.2s ease;
}

.btn-group .btn:hover {
    box-shadow: 0 4px 8px -2px rgba(34, 197, 94, 0.3);
}

/* Primary button with green shadow */
.btn-primary {
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px -1px rgba(34, 197, 94, 0.2);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(34, 197, 94, 0.4);
}

/* Badge with green glow */
.badge.bg-success {
    transition: all 0.2s ease;
}

.badge.bg-success:hover {
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
}

/* Sidebar navigation items with green shadow on active */
.sidebar-nav .nav-link.active {
    position: relative;
    overflow: hidden;
}

.sidebar-nav .nav-link.active::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, #22c55e, #10b981);
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
}

/* Animation for the green glow */
@keyframes greenPulse {
    0% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(34, 197, 94, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }
}

/* Apply pulse animation to the LIVE badge */
.badge.bg-success:contains("LIVE") {
    animation: greenPulse 2s infinite;
}

/* Progress bars with green shadow */
.progress-bar {
    transition: all 0.3s ease;
}

.progress-bar.bg-primary,
.progress-bar.bg-success {
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
}

/* Trending indicators with green shadow */
.trend-up {
    color: #22c55e;
    text-shadow: 0 0 8px rgba(34, 197, 94, 0.3);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card:hover {
        box-shadow: 0 15px 20px -8px rgba(34, 197, 94, 0.25);
    }
}

/* Optional: Add a custom class for elements you want to highlight with green shadow */
.green-shadow {
    transition: all 0.3s ease;
}

.green-shadow:hover {
    box-shadow: 0 15px 25px -8px #22c55e !important;
}

/* Dashboard header with subtle green glow */
.page-header h1 {
    position: relative;
    display: inline-block;
}

.page-header h1::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 50px;
    height: 3px;
    background: linear-gradient(90deg, #22c55e, #10b981);
    border-radius: 3px;
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
}

/* Green Shadow Effects for Sidebar */

/* Sidebar container with subtle green shadow */
.admin-sidebar {
    transition: all 0.3s ease;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.03);
}

/* Sidebar navigation items with green hover effect */
.sidebar-nav .nav-link {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    margin: 2px 8px;
    border-radius: 8px;
}

.sidebar-nav .nav-link:hover {
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.08) 0%, rgba(34, 197, 94, 0.02) 100%);
    transform: translateX(5px);
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.15);
}

/* Active navigation item with green glow */
.sidebar-nav .nav-link.active {
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.12) 0%, rgba(34, 197, 94, 0.05) 100%);
    color: #22c55e !important;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.2);
    border-left: 3px solid #22c55e;
}

.sidebar-nav .nav-link.active i {
    color: #22c55e !important;
}

/* Icons with green hover effect */
.sidebar-nav .nav-link i {
    transition: all 0.3s ease;
    font-size: 1.2rem;
    margin-right: 10px;
}

.sidebar-nav .nav-link:hover i {
    color: #22c55e !important;
    transform: scale(1.1);
    filter: drop-shadow(0 2px 4px rgba(34, 197, 94, 0.3));
}

/* Section headers with green accent */
.sidebar-nav .text-muted.text-uppercase {
    position: relative;
    padding-left: 15px;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    color: #64748b !important;
}

.sidebar-nav .text-muted.text-uppercase::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 12px;
    background: linear-gradient(135deg, #22c55e, #10b981);
    border-radius: 3px;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
}

/* Submenu items with green effect */
.nav-submenu .nav-link {
    padding-left: 45px !important;
    font-size: 0.9rem;
    background: none;
}

.nav-submenu .nav-link:hover {
    background: rgba(34, 197, 94, 0.05);
    transform: translateX(3px);
    box-shadow: 0 2px 8px rgba(34, 197, 94, 0.1);
}

.nav-submenu .nav-link i {
    font-size: 0.5rem !important;
    color: #94a3b8;
    margin-right: 8px;
}

.nav-submenu .nav-link:hover i {
    color: #22c55e !important;
    transform: scale(1.2);
}

/* Badges with green glow */
.sidebar-nav .badge {
    transition: all 0.3s ease;
}

.sidebar-nav .badge.bg-primary {
    background: linear-gradient(135deg, #22c55e, #10b981) !important;
    box-shadow: 0 2px 6px rgba(34, 197, 94, 0.3);
}

.sidebar-nav .badge.bg-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
}

.sidebar-nav .badge.bg-warning {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
}

.sidebar-nav .badge.bg-success {
    background: linear-gradient(135deg, #22c55e, #16a34a) !important;
    box-shadow: 0 2px 6px rgba(34, 197, 94, 0.3);
}

.sidebar-nav .badge:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 10px rgba(34, 197, 94, 0.4);
}

/* Chevron icons with green effect */
.sidebar-nav .bi-chevron-down {
    transition: all 0.3s ease;
    font-size: 0.8rem;
    opacity: 0.7;
}

.sidebar-nav .nav-link:hover .bi-chevron-down {
    color: #22c55e;
    transform: translateX(3px);
    opacity: 1;
}

/* Collapse show animation */
.collapse.show {
    animation: slideDown 0.3s ease forwards;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Submenu items with green dot indicator */
.nav-submenu .nav-link {
    position: relative;
}

.nav-submenu .nav-link::before {
    content: '';
    position: absolute;
    left: 30px;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 4px;
    background-color: #cbd5e1;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.nav-submenu .nav-link:hover::before {
    background-color: #22c55e;
    box-shadow: 0 0 8px #22c55e;
    width: 6px;
    height: 6px;
}

/* Active submenu item */
.nav-submenu .nav-link.active {
    color: #22c55e !important;
    background: rgba(34, 197, 94, 0.05);
}

.nav-submenu .nav-link.active::before {
    background-color: #22c55e;
    box-shadow: 0 0 8px #22c55e;
    width: 6px;
    height: 6px;
}

/* Scrollbar with green accent */
.perfect-scrollbar::-webkit-scrollbar {
    width: 4px;
}

.perfect-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.perfect-scrollbar::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #22c55e, #10b981);
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
}

.perfect-scrollbar::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #16a34a, #059669);
    box-shadow: 0 0 15px rgba(34, 197, 94, 0.5);
}

/* Pulse animation for notification badges */
@keyframes greenPulse {
    0% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }
}

/* Apply pulse to badges with numbers */
.sidebar-nav .badge:not(.bg-primary):not(.bg-success) {
    animation: greenPulse 2s infinite;
}

/* Dark mode support */
[data-bs-theme="dark"] .sidebar-nav .nav-link:hover {
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.05) 100%);
}

[data-bs-theme="dark"] .sidebar-nav .nav-link.active {
    background: linear-gradient(90deg, rgba(34, 197, 94, 0.2) 0%, rgba(34, 197, 94, 0.1) 100%);
}

[data-bs-theme="dark"] .perfect-scrollbar::-webkit-scrollbar-track {
    background: #1e293b;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .sidebar-nav .nav-link:hover {
        transform: translateX(3px);
    }
    
    .nav-submenu .nav-link {
        padding-left: 40px !important;
    }
}
.admin-sidebar { height: 100vh; overflow-y: auto; }
</style>