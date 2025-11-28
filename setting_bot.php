<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- .env loader ---------- */
function loadEnv($path) {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\n\r\0\x0B\"'");
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}
loadEnv(__DIR__ . '/.env');

/* ---------- PDO из .env ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
    $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
    $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    if (!$host || !$user || !$name) {
        http_response_code(500);
        exit('DB env is not configured');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/* ---------- helpers ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- строгая проверка ADMIN как в index.php (через таблицу admins) ---------- */
$LOGIN_URL = '/login.php';

// проверяем сессию админа
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    header('Location: ' . $LOGIN_URL);
    exit;
}

$ADMIN_ID   = (int)$_SESSION['admin_id'];
$userRole   = (string)$_SESSION['admin_role'];
$username   = (string)($_SESSION['admin_name']  ?? '');
$userEmail  = (string)($_SESSION['admin_email'] ?? '');

// роли, которым разрешён доступ к настройкам бота
$ALLOWED_ROLES = ['owner', 'admin', 'manager'];

if (!in_array($userRole, $ALLOWED_ROLES, true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- БД ---------- */
$pdo = db();

/* ---------- проверка админа в БД  ---------- */
$adminRow = [
    'name'      => $username,
    'email'     => $userEmail,
    'role'      => $userRole,
    'is_active' => 0,
];

$adminExists = false;
try {
    $st = $pdo->prepare("
        SELECT name, email, role, is_active
        FROM admins
        WHERE id = :id AND is_active = 1
        LIMIT 1
    ");
    $st->execute([':id' => $ADMIN_ID]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $adminExists = true;
        $adminRow = array_merge($adminRow, $row);
        $userRole = (string)$adminRow['role'];
    }
} catch (Throwable $e) {
    error_log('setting_bot.php admin fetch error: ' . $e->getMessage());
}

if (!$adminExists || (int)$adminRow['is_active'] !== 1) {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    header('Location: ' . $LOGIN_URL);
    exit;
}

$adminDisplay = $adminRow['name'] ?: $adminRow['email'];

/* ---------- статус подключения к БД ---------- */
$dbStatusOk = false;
$dbStatusMessage = '';

try {
    $pdo->query('SELECT 1');
    $dbStatusOk = true;
    $dbStatusMessage = 'Подключение к базе активно';
} catch (Throwable $e) {
    $dbStatusOk = false;
    $dbStatusMessage = 'Ошибка подключения к БД: ' . $e->getMessage();
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bot_settings` (
          `id` TINYINT(1) UNSIGNED NOT NULL,
          `is_enabled` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1 = бот включен, 0 = бот выключен',
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    // гарантируем единственную строку с id = 1
    $pdo->exec("INSERT IGNORE INTO `bot_settings` (`id`, `is_enabled`) VALUES (1, 0)");
} catch (Throwable $e) {
    // статус БД уже покажем отдельно
    error_log('bot_settings create error: ' . $e->getMessage());
}

/* ---------- AJAX-переключение тумблера ---------- */
if (
    $dbStatusOk &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['ajax']) &&
    $_POST['ajax'] === 'toggle_bot'
) {
    header('Content-Type: application/json; charset=utf-8');

    $state = $_POST['state'] ?? '';
    $newState = ($state === 'on') ? 1 : 0;

    try {
        $upd = $pdo->prepare("UPDATE bot_settings SET is_enabled = :s WHERE id = 1");
        $upd->execute([':s' => $newState]);

        echo json_encode([
            'ok'      => true,
            'enabled' => (bool)$newState,
            'message' => $newState ? 'Бот включён.' : 'Бот выключен.',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode([
            'ok'      => false,
            'enabled' => null,
            'message' => 'Ошибка при изменении статуса бота: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- Читаем текущий статус бота для первичной отрисовки ---------- */
$botEnabled = false;
$notice = null;
$noticeType = null;

if ($dbStatusOk) {
    try {
        $stmt = $pdo->query("SELECT is_enabled FROM bot_settings WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        $botEnabled = $row ? ((int)$row['is_enabled'] === 1) : false;
    } catch (Throwable $e) {
        $botEnabled = false;
        $notice = 'Ошибка чтения статуса бота: ' . $e->getMessage();
        $noticeType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Настройки бота - Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >
    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: #242442;
            --bg-hover: #2d2d52;
            --border: #3a3a5c;
            --text-primary: #ffffff;
            --text-secondary: #b8b8d6;
            --text-muted: #6c6c8c;
            --accent: #8b5cf6;
            --accent-hover: #7c4dff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.35);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-title p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .admin-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.5);
            background: rgba(15,23,42,0.9);
            font-size: 11px;
            color: var(--text-secondary);
        }

        .admin-pill i {
            font-size: 11px;
            color: var(--accent);
        }

        .btn {
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            border: none;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-hover);
            transform: translateY(-1px);
        }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2 i {
            color: var(--accent);
        }

        .card-body {
            padding: 22px;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.9);
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            position: relative;
        }

        .status-dot.ok {
            background: var(--success);
        }

        .status-dot.ok::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: inherit;
            border: 2px solid rgba(16,185,129,0.3);
            animation: pulse 1.4s infinite;
        }

        .status-dot.bad {
            background: var(--danger);
        }

        .status-text-ok {
            color: var(--success);
        }

        .status-text-bad {
            color: var(--danger);
        }

        .alert {
            border-radius: var(--radius-md);
            border: 1px solid;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: rgba(16,185,129,0.08);
            border-color: rgba(16,185,129,0.7);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239,68,68,0.06);
            border-color: rgba(248,113,113,0.8);
            color: var(--danger);
        }

        .alert-info {
            background: rgba(59,130,246,0.05);
            border-color: rgba(59,130,246,0.8);
            color: var(--info);
        }

        .bot-settings-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-top: 10px;
        }

        .bot-text {
            max-width: 60%;
        }

        .bot-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .bot-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .toggle-wrapper {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .toggle {
            position: relative;
            width: 60px;
            height: 30px;
        }

        .toggle input {
            width: 0;
            height: 0;
            opacity: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--bg-secondary);
            border-radius: 999px;
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .slider::before {
            content: "";
            position: absolute;
            height: 22px;
            width: 22px;
            left: 4px;
            top: 50%;
            transform: translateY(-50%);
            background: #fff;
            border-radius: 50%;
            transition: var(--transition);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35);
        }

        input:checked + .slider {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            border-color: transparent;
        }

        input:checked + .slider::before {
            transform: translate(26px, -50%);
        }

        .toggle-status-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            min-height: 16px;
        }

        .toggle-status-label.on {
            color: var(--success);
        }

        .toggle-status-label.off {
            color: var(--text-muted);
        }

        .save-hint {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0%   { transform: scale(0.9); opacity: 0.8; }
            50%  { transform: scale(1.1); opacity: 0.2; }
            100% { transform: scale(1.2); opacity: 0; }
        }

        @media (max-width: 700px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .bot-settings-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .bot-text {
                max-width: 100%;
            }
            .toggle-wrapper {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="page-title">
            <h1>Настройки бота</h1>
            <p>Включение / отключение Telegram-бота в один клик</p>
        </div>
        <div class="header-actions">
            <div class="admin-pill">
                <i class="fa-solid fa-user-shield"></i>
                <span><?= h($adminDisplay) ?></span>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад
            </a>
        </div>
    </div>

    <!-- Карточка настроек -->
    <div class="card">
        <div class="card-header">
            <h2>
                <i class="fas fa-robot"></i>
                Управление ботом
            </h2>
        </div>
        <div class="card-body">

            <!-- Статус подключения к БД -->
            <div style="margin-bottom: 16px; display:flex; justify-content:space-between; align-items:center; gap:16px;">
                <div class="status-chip">
                    <span class="status-dot <?= $dbStatusOk ? 'ok' : 'bad' ?>"></span>
                    <span class="<?= $dbStatusOk ? 'status-text-ok' : 'status-text-bad' ?>">
                        <?= h($dbStatusMessage) ?>
                    </span>
                </div>
                <span style="font-size:12px; color:var(--text-secondary);">
                    хост: <?= h($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'не задан') ?>
                </span>
            </div>

            <?php if ($notice && $noticeType === 'error'): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?= h($notice) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$dbStatusOk): ?>
                <div class="alert alert-error">
                    <i class="fas fa-plug-circle-xmark"></i>
                    <span>Настройки бота недоступны: нет подключения к базе данных.</span>
                </div>
            <?php else: ?>

                <div class="bot-settings-row">
                    <div class="bot-text">
                        <div class="bot-title">
                            Текущий статус:
                            <?php if ($botEnabled): ?>
                                <span style="color:var(--success);font-weight:600;">Бот включен</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-weight:600;">Бот выключен</span>
                            <?php endif; ?>
                        </div>
                        <div class="bot-subtitle">
                            Используй тумблер справа, чтобы мгновенно включать или выключать бота.
                        </div>
                    </div>

                    <div class="toggle-wrapper">
                        <label class="toggle">
                            <input
                                type="checkbox"
                                id="botToggle"
                                <?= $botEnabled ? 'checked' : '' ?>
                            >
                            <span class="slider"></span>
                        </label>
                        <div id="toggleStateLabel"
                             class="toggle-status-label <?= $botEnabled ? 'on' : 'off' ?>">
                            <?= $botEnabled ? 'Бот включен' : 'Бот выключен' ?>
                        </div>
                        <div class="save-hint" id="saveHint">
                            &nbsp;
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.getElementById('botToggle');
    const label    = document.getElementById('toggleStateLabel');
    const hint     = document.getElementById('saveHint');

    if (!checkbox || !label) return;

    let isBusy = false;
    let hintTimer = null;

    function setHint(text, type) {
        if (!hint) return;
        hint.textContent = text || '';
        hint.style.color = 'var(--text-muted)';

        if (type === 'ok') {
            hint.style.color = 'var(--success)';
        } else if (type === 'error') {
            hint.style.color = 'var(--danger)';
        }

        if (hintTimer) clearTimeout(hintTimer);
        if (text) {
            hintTimer = setTimeout(() => {
                hint.textContent = '';
            }, 2500);
        }
    }

    function updateLabelLocal() {
        if (checkbox.checked) {
            label.textContent = 'Бот включен';
            label.classList.remove('off');
            label.classList.add('on');
        } else {
            label.textContent = 'Бот выключен';
            label.classList.remove('on');
            label.classList.add('off');
        }
    }

    checkbox.addEventListener('change', function () {
        if (isBusy) return;

        const desired = checkbox.checked ? 'on' : 'off';
        updateLabelLocal();
        setHint('Сохранение...', 'info');
        isBusy = true;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'setting_bot.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isBusy = false;

            if (xhr.status !== 200) {
                // откатываем тумблер
                checkbox.checked = !checkbox.checked;
                updateLabelLocal();
                setHint('Ошибка связи с сервером', 'error');
                return;
            }

            let resp;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (e) {
                checkbox.checked = !checkbox.checked;
                updateLabelLocal();
                setHint('Некорректный ответ сервера', 'error');
                return;
            }

            if (!resp.ok) {
                checkbox.checked = !checkbox.checked;
                updateLabelLocal();
                setHint(resp.message || 'Ошибка сохранения', 'error');
                return;
            }

            // всё ok
            setHint(resp.message || 'Сохранено', 'ok');
        };

        xhr.send('ajax=toggle_bot&state=' + encodeURIComponent(desired));
    });
});
</script>
</body>
</html>
