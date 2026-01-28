<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/teams.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();

$teams = enlil_teams_all();
$people = enlil_people_all();

// Bot management moved to dashboard

enlil_page_header('Equipos y personas');
?>
    <main class="container">
        <h1>Equipos y personas</h1>
        <p>Administra los equipos de trabajo y los miembros asociados.</p>

        <div class="menu-grid">
            <a class="menu-card" href="/equipos_list.php">
                <h2>Equipos</h2>
                <p><?php echo count($teams); ?> equipos registrados.</p>
            </a>
            <a class="menu-card" href="/personas_list.php">
                <h2>Personas</h2>
                <p><?php echo count($people); ?> personas registradas.</p>
            </a>
        </div>
    </main>
<?php enlil_page_footer(); ?>
