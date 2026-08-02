<?php
$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
$openAnomalies = 0;
try {
    $openAnomalies = (int)db()->query('SELECT COUNT(*) FROM ' . table_name('anomalies') . ' WHERE status = "OPEN"')->fetchColumn();
} catch (Throwable $e) {
    // Durante l'installazione le tabelle potrebbero non esistere ancora.
}
$flashes = consume_flashes();
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/app.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/../assets/app.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand app-brand" href="index.php" aria-label="<?= e(APP_NAME) ?>">
            <img
                class="navbar-logo"
                src="assets/logo.svg?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/../assets/logo.svg')) ?>"
                alt=""
            >
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="index.php"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'devices' ? 'active' : '' ?>" href="devices.php"><i class="bi bi-hdd-network"></i> Varchi</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'people' ? 'active' : '' ?>" href="people.php"><i class="bi bi-people"></i> Utenti</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'badges' ? 'active' : '' ?>" href="badges.php"><i class="bi bi-person-vcard"></i> Badge</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'events' ? 'active' : '' ?>" href="events.php"><i class="bi bi-door-open"></i> Ingressi</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'anomalies' ? 'active' : '' ?>" href="anomalies.php"><i class="bi bi-exclamation-triangle"></i> Anomalie<?php if ($openAnomalies): ?> <span class="badge text-bg-danger ms-1"><?= $openAnomalies ?></span><?php endif; ?></a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'logs' ? 'active' : '' ?>" href="logs.php"><i class="bi bi-card-list"></i> Log</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2 small">
                <a class="nav-link text-white-50 <?= $activeNav === 'profile' ? 'active text-white' : '' ?>" href="profile.php" title="Gestisci profilo">
                    <i class="bi bi-person-circle"></i>
                    <?= e(current_user()['display_name'] ?? current_user()['username'] ?? '') ?>
                </a>
                <a class="btn btn-sm btn-outline-light" href="logout.php">Esci</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-lg-4 py-4">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
