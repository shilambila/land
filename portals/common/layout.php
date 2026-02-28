<?php
function renderPortalHeader(string $title): void {
    echo '<!DOCTYPE html>';
    echo '<html lang="en"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light">';
    echo '<nav class="navbar navbar-expand-lg navbar-dark bg-success"><div class="container">';
    echo '<a class="navbar-brand" href="#">Land System Portal</a>';
    echo '<div class="ms-auto d-flex gap-2">';
    echo '<a class="btn btn-sm btn-light" href="modules.php">Modules</a>';
    echo '<a class="btn btn-sm btn-outline-light" href="profile.php">Profile</a>';
    echo '<a class="btn btn-sm btn-danger" href="/logout.php">Logout</a>';
    echo '</div></div></nav>';
    echo '<main class="container py-4">';
}

function renderPortalFooter(): void {
    echo '</main>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}
