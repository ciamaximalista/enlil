<?php
require_once __DIR__ . '/config.php';

function enlil_page_header(string $title, bool $showNav = true): void {
    $config = require __DIR__ . '/config.php';
    $logoPath = __DIR__ . '/../enlil.png';
    $logoUrl = file_exists($logoPath) ? '/assets/enlil.png' : '';
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo htmlspecialchars($title); ?> | <?php echo htmlspecialchars($config['app_name']); ?></title>
        <?php $cssVer = file_exists(__DIR__ . '/../assets/styles.css') ? filemtime(__DIR__ . '/../assets/styles.css') : time(); ?>
        <link rel="icon" href="/assets/enlil.png?v=<?php echo $cssVer; ?>" type="image/png">
        <link rel="stylesheet" href="/assets/styles.css?v=<?php echo $cssVer; ?>">
    </head>
    <body>
        <header class="topbar">
            <div class="brand">
                <?php if ($logoUrl): ?>
                    <img class="logo small" src="<?php echo $logoUrl; ?>" alt="<?php echo htmlspecialchars($config['app_name']); ?>">
                <?php endif; ?>
                <strong><?php echo htmlspecialchars($config['app_name']); ?></strong>
            </div>
            <?php if ($showNav): ?>
            <nav class="main-nav">
                <a href="/dashboard.php">Panel</a>
                <a href="/equipos_personas.php">Equipos y personas</a>
                <a href="/proyectos_list.php">Proyectos</a>
                <a href="/calendarios_list.php">Calendarios</a>
                <a href="/logout.php">Cerrar sesión</a>
            </nav>
            <?php endif; ?>
        </header>
    <?php
}

function enlil_page_footer(): void {
    ?>
    </body>
    </html>
    <?php
}
