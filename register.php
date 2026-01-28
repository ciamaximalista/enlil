<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$config = require __DIR__ . '/includes/config.php';

if (enlil_admin_exists()) {
    header('Location: /login.php');
    exit;
}

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($username === '' || strlen($username) < 3) {
        $errors[] = 'El nombre de usuario debe tener al menos 3 caracteres.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if (!$errors) {
        enlil_admin_save($username, password_hash($password, PASSWORD_DEFAULT), date('c'));

        enlil_login($username, $password);
        header('Location: /dashboard.php');
        exit;
    }
}

$logoPath = __DIR__ . '/enlil.png';
$logoUrl = file_exists($logoPath) ? '/assets/enlil.png' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro admin | <?php echo htmlspecialchars($config['app_name']); ?></title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <main class="card">
        <?php if ($logoUrl): ?>
            <img class="logo" src="<?php echo $logoUrl; ?>" alt="<?php echo htmlspecialchars($config['app_name']); ?>">
        <?php endif; ?>
        <h1>Crear administrador</h1>
        <p>Este usuario será el único administrador del sistema.</p>

        <?php if ($errors): ?>
            <div class="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <label>Nombre de usuario
                <input type="text" name="username" required minlength="3" value="<?php echo htmlspecialchars($username); ?>">
            </label>
            <label>Contraseña
                <input type="password" name="password" required minlength="8">
            </label>
            <label>Confirmar contraseña
                <input type="password" name="password_confirm" required minlength="8">
            </label>
            <button type="submit">Crear cuenta</button>
        </form>
        <p class="hint">Si ya tienes cuenta, <a href="/login.php">inicia sesión</a>.</p>
    </main>
</body>
</html>
