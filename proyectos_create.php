<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/projects.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();

$teams = enlil_teams_all();
$teamIds = array_map(function ($team) {
    return $team['id'];
}, $teams);

$errors = [];
$name = '';
$description = '';
$selectedTeams = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $selectedTeams = $_POST['team_ids'] ?? [];
    if (!is_array($selectedTeams)) {
        $selectedTeams = [];
    }

    if ($name === '') {
        $errors[] = 'El nombre del proyecto es obligatorio.';
    }

    $selectedTeams = array_values(array_filter(array_map('intval', $selectedTeams), function ($id) use ($teamIds) {
        return in_array($id, $teamIds, true);
    }));

    if (!$errors) {
        enlil_projects_add($name, $description, $selectedTeams);
        header('Location: /proyectos_list.php');
        exit;
    }
}

enlil_page_header('Crear proyecto');
?>
    <main class="container">
        <div class="page-header">
            <h1>Crear proyecto</h1>
            <a class="btn secondary" href="/proyectos_list.php">Volver</a>
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
            <label>Nombre del proyecto
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <label>Descripción (opcional)
                <input type="text" name="description" value="<?php echo htmlspecialchars($description); ?>">
            </label>

            <fieldset>
                <legend>Equipos implicados</legend>
                <?php if (!$teams): ?>
                    <p class="empty">No hay equipos aún. Crea un equipo primero.</p>
                <?php else: ?>
                    <div class="checkbox-grid">
                        <?php foreach ($teams as $team): ?>
                            <?php $checked = in_array($team['id'], $selectedTeams, true); ?>
                            <label class="checkbox">
                                <input type="checkbox" name="team_ids[]" value="<?php echo $team['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($team['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <button type="submit">Guardar proyecto</button>
        </form>
    </main>
<?php enlil_page_footer(); ?>
