<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/people.php';
require_once __DIR__ . '/includes/teams.php';

enlil_require_login();

$teams = enlil_teams_all();
$teamIds = array_map(function ($team) {
    return $team['id'];
}, $teams);

$errors = [];
$name = '';
$telegramUser = '';
$selectedTeams = [];
// existing people for validation
$people = enlil_people_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $telegramUser = trim($_POST['telegram_user'] ?? '');
    $selectedTeams = $_POST['team_ids'] ?? [];
    if (!is_array($selectedTeams)) {
        $selectedTeams = [];
    }

    if ($name === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($telegramUser === '') {
        $errors[] = 'El usuario de Telegram es obligatorio.';
    }

    if ($telegramUser !== '' && $telegramUser[0] !== '@') {
        $telegramUser = '@' . $telegramUser;
    }

    $selectedTeams = array_values(array_filter(array_map('intval', $selectedTeams), function ($id) use ($teamIds) {
        return in_array($id, $teamIds, true);
    }));

    if (!$errors) {
        enlil_people_add($name, $telegramUser, '', $selectedTeams);
        header('Location: /personas_list.php');
        exit;
    }
}

enlil_page_header('Crear persona');
?>
    <main class="container">
        <div class="page-header">
            <h1>Crear persona</h1>
            <a class="btn secondary" href="/personas_list.php">Volver</a>
        </div>

        <div class="tabs">
            <a class="tab" href="/equipos_list.php">Equipos</a>
            <a class="tab active" href="/personas_list.php">Personas</a>
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
            <label>Nombre
                <input type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>">
            </label>
            <label>Usuario de Telegram
                <input type="text" name="telegram_user" required value="<?php echo htmlspecialchars($telegramUser); ?>" placeholder="@usuario">
            </label>
            <fieldset>
                <legend>Equipos</legend>
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

            <button type="submit">Guardar persona</button>
        </form>
    </main>
<?php enlil_page_footer(); ?>
