<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();

$errors = [];
$name = '';
$telegramGroup = '';
$telegramBotToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $telegramGroup = trim($_POST['telegram_group'] ?? '');
    if ($name === '') {
        $errors[] = 'El nombre del equipo es obligatorio.';
    }

    if (!$errors) {
        enlil_teams_add($name, $telegramGroup, '');
        header('Location: /equipos_list.php');
        exit;
    }
}

enlil_page_header('Crear equipo');
?>
    <main class="container">
        <div class="page-header">
            <h1>Crear equipo</h1>
            <a class="btn secondary" href="/equipos_list.php">Volver</a>
        </div>

        <div class="tabs">
            <a class="tab active" href="/equipos_list.php">Equipos</a>
            <a class="tab" href="/personas_list.php">Personas</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="form-card" autocomplete="off">
            <label>Nombre del equipo
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <div class="alert">
                Guarda el equipo para poder buscar el ID del grupo con el bot.
            </div>
            <button type="submit">Guardar equipo</button>
        </form>

    </main>
<?php enlil_page_footer(); ?>
