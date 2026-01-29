<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bot.php';
require_once __DIR__ . '/includes/telegram.php';

enlil_require_login();

$botInfo = enlil_bot_get();
$webhookInfo = null;
$webhookActive = false;
$editingBot = isset($_GET['edit_bot']) && $_GET['edit_bot'] === '1';
$botError = '';
$botSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bot_token'])) {
    $token = trim($_POST['bot_token']);
    if ($token === '') {
        $botError = 'El token del bot es obligatorio.';
    } else {
        $botInfo = enlil_bot_save($token);
        $botSuccess = 'Bot guardado.';
    }
}

if ($botInfo['token'] !== '') {
    $webhookInfo = enlil_telegram_get($botInfo['token'], 'getWebhookInfo');
    if ($webhookInfo['ok']) {
        $data = json_decode($webhookInfo['body'], true);
        $currentUrl = $data['result']['url'] ?? '';
        $desiredUrl = enlil_bot_webhook_url();
        if ($currentUrl !== '' && $desiredUrl !== '' && $currentUrl === $desiredUrl) {
            $webhookActive = true;
        }
    }
}

enlil_page_header('Panel');
?>
    <main class="container">
        <h1>Bienvenido, <?php echo htmlspecialchars($_SESSION['admin_username'] ?? ''); ?></h1>
        <p>Listo para organizar tus proyectos en Enlil.</p>

        <div class="section-card">
            <h2>Bot de Telegram de la instalación</h2>
            <?php if ($botSuccess): ?>
                <div class="alert success"><?php echo htmlspecialchars($botSuccess); ?></div>
            <?php endif; ?>
            <?php if ($botError): ?>
                <div class="alert"><?php echo htmlspecialchars($botError); ?></div>
            <?php endif; ?>

            <?php if ($botInfo['token'] !== ''): ?>
                <div class="table-wrap">
                    <table class="bot-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Business ID</th>
                                <th>Webhook</th>
                                <th>Editar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="name-cell">
                                        <?php if (enlil_bot_avatar_url()): ?>
                                            <img class="avatar" src="<?php echo htmlspecialchars(enlil_bot_avatar_url()); ?>" alt="">
                                        <?php else: ?>
                                            <?php
                                            $botInitial = $botInfo['name'] !== '' ? (function_exists('mb_substr') ? mb_substr($botInfo['name'], 0, 1) : substr($botInfo['name'], 0, 1)) : 'B';
                                            ?>
                                            <span class="avatar placeholder"><?php echo htmlspecialchars($botInitial); ?></span>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($botInfo['name'] !== '' ? $botInfo['name'] : 'Bot configurado'); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($botInfo['username'] !== ''): ?>
                                        <span class="mono">@<?php echo htmlspecialchars($botInfo['username']); ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mono"><?php echo htmlspecialchars($botInfo['business_connection_id'] ?: '—'); ?></span>
                                    <form method="post" action="/bot_refresh_business.php" class="inline-form">
                                        <button class="btn small" type="submit">Refrescar</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($webhookActive): ?>
                                        <span class="badge success">Activado</span>
                                        <form method="post" action="/bot_unset_webhook.php" class="inline-form">
                                            <button class="btn small danger" type="submit">Desactivar</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/bot_set_webhook.php" class="inline-form">
                                            <button class="btn small" type="submit">Activar webhook</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn small" href="/dashboard.php?edit_bot=1">Editar</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($editingBot): ?>
                    <form method="post" class="form-card" autocomplete="off">
                        <label>Token del bot
                            <input type="text" name="bot_token" value="<?php echo htmlspecialchars($botInfo['token']); ?>">
                        </label>
                        <button type="submit" class="btn secondary">Guardar cambios</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" class="form-card" autocomplete="off">
                    <label>Token del bot
                        <input type="text" name="bot_token" required placeholder="123456:ABC-DEF...">
                    </label>
                    <button type="submit">Guardar bot</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="menu-grid">
            <a class="menu-card" href="/equipos_personas.php">
                <h2>Equipos y personas</h2>
                <p>Gestiona equipos, tokens de bots y miembros.</p>
            </a>
            <a class="menu-card" href="/proyectos_list.php">
                <h2>Proyectos</h2>
                <p>Crea y revisa los proyectos existentes.</p>
            </a>
            <a class="menu-card" href="/calendarios_list.php">
                <h2>Calendarios</h2>
                <p>Consulta tareas por proyecto o por persona.</p>
            </a>
        </div>
    </main>
<?php enlil_page_footer(); ?>
