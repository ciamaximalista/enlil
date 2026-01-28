<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$config = require __DIR__ . '/includes/config.php';

if (!enlil_admin_exists()) {
    header('Location: /register.php');
    exit;
}

if (enlil_is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!enlil_login($username, $password)) {
        $errors[] = 'Credenciales inválidas.';
    } else {
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
    <title>Login | <?php echo htmlspecialchars($config['app_name']); ?></title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <main class="card">
        <?php if ($logoUrl): ?>
            <img class="logo" src="<?php echo $logoUrl; ?>" alt="<?php echo htmlspecialchars($config['app_name']); ?>">
        <?php endif; ?>
        <h1>Iniciar sesión</h1>

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
                <input type="password" name="password" required>
            </label>
            <button type="submit">Entrar</button>
        </form>
        <p class="hint">¿No tienes cuenta? <a href="/register.php">Crear administrador</a>.</p>
    </main>
</body>
</html>
