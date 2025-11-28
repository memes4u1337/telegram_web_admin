<?php

/* ---------- сессия ---------- */
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

function roleLabel($r) {

    return 'Владелец';
}

/* ---------- строгая проверка ADMIN ---------- */
$LOGIN_URL = '/login.php';

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    header('Location: ' . $LOGIN_URL);
    exit;
}

$ADMIN_ID   = (int)$_SESSION['admin_id'];
$adminRole  = (string)$_SESSION['admin_role'];
$adminName  = (string)($_SESSION['admin_name']  ?? '');
$adminEmail = (string)($_SESSION['admin_email'] ?? '');

// доступ к этой странице — только у владельца
$ALLOWED_ROLES = ['owner'];

if (!in_array($adminRole, $ALLOWED_ROLES, true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- БД ---------- */
$pdo = db();

/* ---------- проверка текущего админа в БД ---------- */
$adminRow = [
    'name'      => $adminName,
    'email'     => $adminEmail,
    'role'      => $adminRole,
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
        $adminRole = (string)$adminRow['role'];
    }
} catch (Throwable $e) {
    error_log('create_admin.php admin fetch error: ' . $e->getMessage());
}

if (!$adminExists || (int)$adminRow['is_active'] !== 1 || $adminRole !== 'owner') {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    header('Location: ' . $LOGIN_URL);
    exit;
}

$adminDisplay = $adminRow['name'] ?: $adminRow['email'];

/* ---------- логика создания нового владельца ---------- */

$message = '';
$ok = false;

// user_id владельца (для FK admins.user_id -> users.id)
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameRaw  = $_POST['name'] ?? '';
    $emailRaw = $_POST['email'] ?? '';
    $passRaw  = $_POST['password'] ?? '';
    $pass2Raw = $_POST['password_confirm'] ?? '';

    $name  = trim($nameRaw);
    $email = trim(mb_strtolower($emailRaw));
    $pass  = (string)$passRaw;
    $pass2 = (string)$pass2Raw;

    // роль жёстко — только owner
    $role = 'owner';

    if ($name === '' || $email === '' || $pass === '' || $pass2 === '') {
        $message = 'Заполните все поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Некорректный e-mail.';
    } elseif ($pass !== $pass2) {
        $message = 'Пароли не совпадают.';
    } elseif (mb_strlen($pass) < 6) {
        $message = 'Пароль должен быть не короче 6 символов.';
    } else {
        try {
            // 1) проверяем, нет ли уже такого e-mail
            $st = $pdo->prepare("SELECT id FROM admins WHERE email = :email LIMIT 1");
            $st->execute([':email' => $email]);
            if ($st->fetch()) {
                $message = 'Админ с таким e-mail уже существует.';
            } else {
                // 2) определяем user_id владельца (как было у тебя)
                $ownerId = $currentUserId;

                if ($ownerId <= 0) {
                    $qOwner = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                    $ownerId = (int)($qOwner->fetchColumn() ?: 0);
                }

                if ($ownerId <= 0) {
                    throw new RuntimeException('В таблице users нет ни одной записи, некому привязать администратора.');
                }

                // 3) вставляем администратора
                $hash = password_hash($pass, PASSWORD_BCRYPT);

                $ins = $pdo->prepare("
                    INSERT INTO admins (user_id, email, password_hash, name, role, is_active)
                    VALUES (:uid, :email, :ph, :name, :role, 1)
                ");
                $ins->execute([
                    ':uid'   => $ownerId,
                    ':email' => $email,
                    ':ph'    => $hash,
                    ':name'  => $name,
                    ':role'  => $role,
                ]);

                $ok = true;
                $message = 'Владелец успешно создан.';

            
                $_POST = [];
            }
        } catch (Throwable $e) {
           
            $message = 'Ошибка при сохранении. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Новый владелец — Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

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
            min-height: 100vh;
        }

        .page-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: var(--bg-card);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 20px 20px 18px;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 0 0, rgba(139,92,246,0.18), transparent 55%),
                radial-gradient(circle at 100% 100%, rgba(59,130,246,0.18), transparent 55%);
            opacity: 0.7;
            pointer-events: none;
        }

        .card-inner {
            position: relative;
            z-index: 1;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .title-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .title-block h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .title-block p {
            margin: 0;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .admin-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(15,23,42,0.9);
            border: 1px solid rgba(148,163,184,0.4);
            font-size: 11px;
            color: var(--text-secondary);
        }

        .admin-pill i {
            font-size: 11px;
        }

        .hint-top {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .alert {
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            border-left: 3px solid transparent;
            font-size: 13px;
        }

        .alert-success {
            background: rgba(16,185,129,0.1);
            border-left-color: var(--success);
            color: #bbf7d0;
        }

        .alert-error {
            background: rgba(239,68,68,0.1);
            border-left-color: var(--danger);
            color: #fecaca;
        }

        form {
            display: grid;
            gap: 12px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .field label {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .field-inner {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: var(--text-muted);
        }

        .form-input {
            width: 100%;
            padding: 10px 12px 10px 34px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-input::placeholder {
            color: rgba(184,188,214,0.8);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 1px rgba(139,92,246,0.7), 0 0 0 6px rgba(139,92,246,0.2);
            background: #17172f;
        }

        .btn {
            padding: 11px 16px;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transform: translate(-50%,-50%);
            transition: width .4s, height .4s;
        }

        .btn:active::before {
            width: 220px;
            height: 220px;
        }

        .btn-primary {
            width: 100%;
            margin-top: 6px;
            background: radial-gradient(120px 120px at 20% 0%, rgba(255,255,255,.18), transparent 60%),
                        linear-gradient(135deg, var(--accent), #a855f7);
            color: #fff;
            box-shadow: 0 14px 40px rgba(139,92,246,0.5);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 46px rgba(139,92,246,0.7);
        }

        .card-footer {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .back-link a {
            color: var(--text-secondary);
            text-decoration: none;
            border-bottom: 1px dashed rgba(184,188,214,0.7);
        }

        .back-link a:hover {
            border-bottom-style: solid;
            color: #fff;
        }

        @media (max-width: 480px) {
            .card {
                border-radius: 16px;
                padding: 18px 16px 16px;
            }
            .title-block h1 {
                font-size: 20px;
            }
            .title-block p {
                font-size: 12px;
            }
            .admin-pill {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-inner">
            <div class="card-header">
                <div class="title-block">
                    <h1>Новый владелец</h1>
                    <p>Создаёшь ещё один аккаунт с полным доступом к панели.</p>
                </div>
                <div class="admin-pill">
                    <i class="fa-solid fa-crown"></i>
                    <span><?php echo h($adminDisplay); ?></span>
                </div>
            </div>

            <p class="hint-top">
                Введи имя, почту и пароль — система создаст нового владельца с ролью <strong>«Владелец»</strong>.
            </p>

            <?php if ($message): ?>
                <div class="alert <?php echo $ok ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo h($message); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="field">
                    <label for="name">Имя владельца</label>
                    <div class="field-inner">
                        <span class="field-icon"><i class="fa-regular fa-user"></i></span>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            class="form-input"
                            required
                            placeholder="Например: Главный админ"
                            value="<?php echo isset($_POST['name']) ? h($_POST['name']) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="email">E-mail</label>
                    <div class="field-inner">
                        <span class="field-icon"><i class="fa-regular fa-envelope"></i></span>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            class="form-input"
                            required
                            placeholder="owner@example.com"
                            value="<?php echo isset($_POST['email']) ? h($_POST['email']) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Пароль</label>
                    <div class="field-inner">
                        <span class="field-icon"><i class="fa-solid fa-key"></i></span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="form-input"
                            required
                            placeholder="Минимум 6 символов"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password_confirm">Повторите пароль</label>
                    <div class="field-inner">
                        <span class="field-icon"><i class="fa-regular fa-circle-check"></i></span>
                        <input
                            id="password_confirm"
                            name="password_confirm"
                            type="password"
                            class="form-input"
                            required
                            placeholder="Повторите пароль"
                        >
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="createBtn">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Создать владельца</span>
                </button>
            </form>

            <div class="card-footer">
                <div class="back-link">
                    <a href="index.php"><i class="fa-solid fa-arrow-left"></i> Назад в панель</a>
                </div>
                <div style="font-size:11px; color:var(--text-muted);">
                    Роль: <strong>Владелец</strong> · полный доступ
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // лёгкий эффект подсветки по мыши на кнопке
    (function () {
        var btn = document.getElementById('createBtn');
        if (!btn) return;
        btn.addEventListener('mousemove', function (e) {
            var r = btn.getBoundingClientRect();
            var x = e.clientX - r.left;
            var y = e.clientY - r.top;
            btn.style.setProperty('--mx', x + 'px');
            btn.style.setProperty('--my', y + 'px');
        });
    })();
</script>
</body>
</html>
