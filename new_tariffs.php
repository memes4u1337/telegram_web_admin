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

/* ---------- строгая проверка ADMIN как ---------- */
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

// роли, которым разрешён доступ
$ALLOWED_ROLES = ['owner', 'admin', 'manager'];

if (!in_array($userRole, $ALLOWED_ROLES, true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- БД ---------- */
$pdo = db();

/* ---------- проверка админа в БД ---------- */
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
    error_log('tariffs.php admin fetch error: ' . $e->getMessage());
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

/* ---------- обработка формы создания тарифа ---------- */
$errors  = [];
$success = '';

$title         = '';
$description   = '';
$priceRaw      = '';
$durationValue = 30;
$durationUnit  = 'days';
$dailyLimit    = 10; // дефолтный лимит

if ($dbStatusOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $priceRaw      = trim($_POST['price'] ?? '');
    $durationValue = (int)($_POST['duration_value'] ?? 0);
    $durationUnit  = $_POST['duration_unit'] ?? 'days';
    $dailyLimit    = (int)($_POST['daily_limit'] ?? 0);

    // Валидация: название только латиница / цифры / пробелы / - _ .
    if ($title === '') {
        $errors[] = 'Введите название тарифа.';
    } elseif (!preg_match('/^[A-Za-z0-9 _\-\.\(\)]+$/', $title)) {
        $errors[] = 'Название тарифа только на английском (латиница, цифры, пробелы, - _ .).';
    }

    if ($priceRaw === '' || !is_numeric(str_replace(',', '.', $priceRaw))) {
        $errors[] = 'Введите корректную цену.';
    } else {
        $price = (float)str_replace(',', '.', $priceRaw);
        if ($price < 0) {
            $errors[] = 'Цена не может быть меньше нуля.';
        }
    }

    if ($durationValue <= 0) {
        $errors[] = 'Укажите длительность тарифа.';
    }

    if ($durationUnit !== 'days' && $durationUnit !== 'months') {
        $durationUnit = 'days';
    }

    if ($dailyLimit < 0) {
        $errors[] = 'Лимит в сутки не может быть меньше нуля.';
    }

    if (empty($errors)) {
        $price = (float)str_replace(',', '.', $priceRaw);

        // Генерируем code на основе названия: lower-case slug
        $base = strtolower($title);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base);
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'plan';
        }

        // Проверка уникальности кода
        $code = $base;
        $suffix = 1;
        try {
            $check = $pdo->prepare("SELECT 1 FROM search_plans WHERE code = :c LIMIT 1");
            while (true) {
                $check->execute([':c' => $code]);
                if (!$check->fetch()) {
                    break;
                }
                $code = $base . '_' . $suffix;
                $suffix++;
            }
        } catch (Throwable $e) {
            // если вдруг что-то пошло не так, fallback
            $code = $base . '_' . substr(str_replace('.', '', uniqid('', true)), 0, 4);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO search_plans
                    (code, title, daily_limit, description, price, duration_value, duration_unit, sort_order, is_active)
                VALUES
                    (:code, :title, :daily_limit, :description, :price, :duration_value, :duration_unit, :sort_order, 1)
            ");
            $stmt->execute([
                ':code'           => $code,
                ':title'          => $title,              // то же название, что ввёл админ
                ':daily_limit'    => $dailyLimit,         // лимит по тарифу
                ':description'    => $description !== '' ? $description : null,
                ':price'          => $price,
                ':duration_value' => $durationValue,
                ':duration_unit'  => $durationUnit,
                ':sort_order'     => 0,
            ]);

            $success = 'Тариф успешно создан. Код тарифа: ' . $code;

            // сбрасываем форму
            $title         = '';
            $description   = '';
            $priceRaw      = '';
            $durationValue = 30;
            $durationUnit  = 'days';
            $dailyLimit    = 10;
        } catch (Throwable $e) {
            $errors[] = 'Ошибка при сохранении тарифа: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тарифы - Admin Panel</title>
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
            max-width: 700px;
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

        .tariff-form-title{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:12px;
        }
        .tariff-form-icon{
            width:40px;
            height:40px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:radial-gradient(circle at 30% 0%,#ffffff,transparent 55%),
                       linear-gradient(135deg,#6a5af9,#ff4fd8);
            color:#fff;
            font-size:22px;
            box-shadow:0 12px 30px rgba(0,0,0,.35);
        }
        .tariff-form-subtitle{
            font-size:13px;
            color:var(--text-secondary);
            margin-top:2px;
        }

        .field-label{
            font-size:13px;
            font-weight:500;
            color:#d4d4f5;
            margin-bottom:4px;
        }

        .input,
        .textarea,
        .tariff-select{
            width:100%;
            box-sizing:border-box;
            background:rgba(15,23,42,0.9);
            border-radius:var(--radius-md);
            border:1px solid var(--border);
            padding:9px 11px;
            color:var(--text-primary);
            font-size:13px;
            outline:none;
            transition:var(--transition);
        }
        .input:focus,
        .textarea:focus,
        .tariff-select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 1px rgba(139,92,246,0.5);
        }
        .textarea{
            resize:vertical;
            min-height:70px;
        }

        .tariff-grid{
            display:flex;
            flex-direction:column;
            gap:10px;
            margin-top:8px;
        }

        .tariff-duration-wrap{
            display:grid;
            grid-template-columns:2fr 1.2fr;
            gap:10px;
        }
        @media(max-width:540px){
            .tariff-duration-wrap{
                grid-template-columns:1fr;
            }
        }

        .tariff-msg{
            margin-bottom:10px;
            padding:10px 12px;
            border-radius:12px;
            font-size:13px;
            line-height:1.4;
        }
        .tariff-msg.success{
            background:rgba(16,185,129,0.08);
            border:1px solid rgba(16,185,129,0.7);
            color:var(--success);
        }
        .tariff-msg.error{
            background:rgba(239,68,68,0.06);
            border:1px solid rgba(248,113,113,0.8);
            color:var(--danger);
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
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="page-title">
            <h1>Тарифы</h1>
            <p>Создание тарифных планов для бота</p>
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

    <!-- Карточка -->
    <div class="card">
        <div class="card-header">
            <h2>
                <i class="fas fa-gem"></i>
                Новый тариф
            </h2>
            <div class="status-chip">
                <span class="status-dot <?= $dbStatusOk ? 'ok' : 'bad' ?>"></span>
                <span class="<?= $dbStatusOk ? 'status-text-ok' : 'status-text-bad' ?>">
                    <?= h($dbStatusMessage) ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="tariff-form-title">
                <div class="tariff-form-icon">💎</div>
                <div>
                    <div style="font-size:18px;font-weight:600;margin-bottom:2px;">
                        Создание тарифа
                    </div>
                    <div class="tariff-form-subtitle">
                        Название только на английском. Код тарифа формируется автоматически и использует это название.
                    </div>
                </div>
            </div>

            <?php if (!empty($success)): ?>
                <div class="tariff-msg success">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="tariff-msg error">
                    <?php foreach ($errors as $err): ?>
                        <div><?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$dbStatusOk): ?>
                <div class="tariff-msg error">
                    База данных недоступна. Проверь .env / соединение.
                </div>
            <?php else: ?>
                <form method="post" class="form">
                    <div class="tariff-grid">
                        <div>
                            <label class="field-label">Название тарифа (только EN)</label>
                            <input type="text"
                                   name="title"
                                   class="input"
                                   maxlength="100"
                                   required
                                   value="<?= h($title) ?>"
                                   placeholder="Например: Start, Premium, VIP">
                        </div>

                        <div>
                            <label class="field-label">Описание тарифа</label>
                            <textarea name="description"
                                      class="textarea"
                                      rows="3"
                                      placeholder="Что даёт тариф (лимиты, бонусы и т.д.)"><?= h($description) ?></textarea>
                        </div>

                        <div>
                            <label class="field-label">Цена (₽)</label>
                            <input type="number"
                                   name="price"
                                   class="input"
                                   step="0.01"
                                   min="0"
                                   required
                                   value="<?= h($priceRaw) ?>"
                                   placeholder="Например: 199 или 499.90">
                        </div>

                        <div class="tariff-duration-wrap">
                            <div>
                                <label class="field-label">Длительность</label>
                                <input type="number"
                                       name="duration_value"
                                       class="input"
                                       min="1"
                                       required
                                       value="<?= (int)$durationValue ?>"
                                       placeholder="Например: 7, 30, 90">
                            </div>
                            <div>
                                <label class="field-label">Период</label>
                                <select name="duration_unit" class="input tariff-select">
                                    <option value="days"   <?= $durationUnit === 'days'   ? 'selected' : '' ?>>дней</option>
                                    <option value="months" <?= $durationUnit === 'months' ? 'selected' : '' ?>>месяцев</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="field-label">Лимит успешных поисков в сутки</label>
                            <input type="number"
                                   name="daily_limit"
                                   class="input"
                                   min="0"
                                   required
                                   value="<?= (int)$dailyLimit ?>"
                                   placeholder="Например: 10, 20, 50">
                        </div>

                        <div style="margin-top:12px;">
                            <button type="submit"
                                    class="btn btn-primary"
                                    style="width:100%;justify-content:center;">
                                <i class="fas fa-plus-circle"></i>
                                Создать тариф
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
