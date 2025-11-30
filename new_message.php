<?php
// new_message.php — создание и список объявлений (панель админа)

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
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Фолбэк-перевод через MyMemory (RU → EN / RU → ES)
 */
function translateTextMyMemory(string $text, string $targetLang, string $sourceLang = 'ru'): string {
    $text = trim($text);
    if ($text === '') return '';

    $url = 'https://api.mymemory.translated.net/get?q='
        . urlencode($text)
        . '&langpair=' . urlencode($sourceLang . '|' . $targetLang);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return '';
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return '';

    $data = json_decode($response, true);
    if (!isset($data['responseData']['translatedText'])) return '';

    $translated = html_entity_decode($data['responseData']['translatedText'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if (trim(mb_strtolower($translated, 'UTF-8')) === trim(mb_strtolower($text, 'UTF-8'))) {
        return '';
    }

    return $translated;
}

/**
 * Более точный перевод RU → EN / ES:
 *  - запрашиваем LibreTranslate и MyMemory
 *  - выбираем лучший вариант по простым эвристикам (не пустой, не совпадает с исходником, более информативный)
 */
function translateText(string $text, string $targetLang, string $sourceLang = 'ru'): string {
    $text = trim($text);
    if ($text === '') return '';

    $lcSource = trim(mb_strtolower($text, 'UTF-8'));

    $candidateLt = '';
    $candidateMm = '';

    // 1) LibreTranslate
    $ltUrl = 'https://libretranslate.de/translate';
    $payload = http_build_query([
        'q'      => $text,
        'source' => $sourceLang,
        'target' => $targetLang,
        'format' => 'text',
    ]);

    $ch = curl_init($ltUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $response = curl_exec($ch);

    if ($response !== false) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $data = json_decode($response, true);
            if (isset($data['translatedText'])) {
                $tmp = trim((string)$data['translatedText']);
                if ($tmp !== '' && trim(mb_strtolower($tmp, 'UTF-8')) !== $lcSource) {
                    $candidateLt = $tmp;
                }
            }
        }
    } else {
        curl_close($ch);
    }

    // 2) MyMemory
    $candidateMm = translateTextMyMemory($text, $targetLang, $sourceLang);

    // 3) Выбор лучшего
    if ($candidateLt === '' && $candidateMm === '') {
        return '';
    }

    if ($candidateLt !== '' && $candidateMm === '') {
        return $candidateLt;
    }
    if ($candidateMm !== '' && $candidateLt === '') {
        return $candidateMm;
    }

    // Оба есть — берём более «информативный»:
    //   - не короче исходника
    //   - немного длиннее конкурента
    $lenLt = mb_strlen($candidateLt, 'UTF-8');
    $lenMm = mb_strlen($candidateMm, 'UTF-8');
    $lenSrc = mb_strlen($text, 'UTF-8');

    $scoreLt = $lenLt;
    $scoreMm = $lenMm;

    if ($lenLt < (int)($lenSrc * 0.6)) $scoreLt -= 10;
    if ($lenMm < (int)($lenSrc * 0.6)) $scoreMm -= 10;

    return ($scoreLt >= $scoreMm) ? $candidateLt : $candidateMm;
}

/* ---------- строгая проверка ADMIN через сессию + таблицу admins ---------- */
$LOGIN_URL = '/login.php';

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    header('Location: ' . $LOGIN_URL);
    exit;
}

$ADMIN_ID  = (int)$_SESSION['admin_id'];      // id из таблицы admins
$userRole  = (string)$_SESSION['admin_role'];
$username  = (string)($_SESSION['admin_name']  ?? '');
$userEmail = (string)($_SESSION['admin_email'] ?? '');

$ALLOWED_ROLES = ['owner', 'admin', 'manager'];

if (!in_array($userRole, $ALLOWED_ROLES, true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- БД ---------- */
$pdo = db();

/* ---------- проверка админа в БД и user_id для created_by ---------- */

$adminRow = [
    'user_id'   => null,
    'name'      => $username,
    'email'     => $userEmail,
    'role'      => $userRole,
    'is_active' => 0,
];

$adminExists    = false;
$ADMIN_USER_ID  = 0; // users.id, нужен для announcements.created_by

try {
    $st = $pdo->prepare("
        SELECT id, user_id, name, email, role, is_active
        FROM admins
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $ADMIN_ID]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $adminRow      = array_merge($adminRow, $row);
        $adminExists   = ((int)$row['is_active'] === 1);
        $userRole      = (string)$row['role'];
        $ADMIN_USER_ID = (int)$row['user_id'];
    }
} catch (Throwable $e) {
    error_log('new_message.php admin fetch error: ' . $e->getMessage());
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

/* ---------- AJAX: перевод RU → EN и RU → ES ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'translate') {
    header('Content-Type: application/json; charset=utf-8');
    $textRu = trim($_POST['text_ru'] ?? '');
    if ($textRu === '') {
        echo json_encode(['ok' => false, 'error' => 'empty_text'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $en = translateText($textRu, 'en', 'ru');
        $es = translateText($textRu, 'es', 'ru');

        echo json_encode([
            'ok' => true,
            'en' => $en,
            'es' => $es,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('new_message.php translate error: ' . $e->getMessage());
        echo json_encode([
            'ok'    => false,
            'error' => 'translate_failed',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- статус подключения к БД ---------- */

$dbStatusOk          = false;
$dbStatusMessage     = '';
$successMessage      = '';
$errorMessage        = '';
$oldContentRu        = '';
$oldContentEn        = '';
$oldContentEs        = '';
$totalAnnouncements  = 0;
$pages               = 1;
$page                = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize            = 10;
$announcements       = [];

try {
    $pdo->query('SELECT 1');
    $dbStatusOk      = true;
    $dbStatusMessage = 'Подключение к базе активно';
} catch (Throwable $e) {
    $dbStatusOk      = false;
    $dbStatusMessage = 'Ошибка подключения к БД: ' . $e->getMessage();
}

/* ---------- Обработка отправки объявления (RU + EN + ES) ---------- */

if ($dbStatusOk && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $contentRu = trim($_POST['content_ru'] ?? '');
    $contentEn = trim($_POST['content_en'] ?? '');
    $contentEs = trim($_POST['content_es'] ?? '');

    $oldContentRu = $contentRu;
    $oldContentEn = $contentEn;
    $oldContentEs = $contentEs;

    if ($contentRu === '') {
        $errorMessage = 'Введите текст объявления на русском.';
    } else {
        if ($contentEn === '') $contentEn = $contentRu;
        if ($contentEs === '') $contentEs = $contentRu;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (content, content_ru, content_en, content_es, created_by, created_at)
                VALUES (:content, :content_ru, :content_en, :content_es, :created_by, NOW())
            ");
            $stmt->execute([
                ':content'    => $contentRu,
                ':content_ru' => $contentRu,
                ':content_en' => $contentEn,
                ':content_es' => $contentEs,
                ':created_by' => $ADMIN_USER_ID ?: null,
            ]);

            if (!headers_sent()) {
                header('Location: new_message.php?success=1');
                exit;
            } else {
                $successMessage = 'Объявление успешно добавлено.';
                $oldContentRu = $oldContentEn = $oldContentEs = '';
            }
        } catch (Throwable $e) {
            error_log('new_message.php insert announcement error: ' . $e->getMessage());
            $errorMessage = 'Ошибка сохранения объявления. Проверьте структуру таблицы announcements.';
        }
    }
}

if ($dbStatusOk && isset($_GET['success']) && $_GET['success'] == '1') {
    $successMessage = 'Объявление успешно добавлено.';
}

/* ---------- Получение объявлений + пагинация ---------- */

if ($dbStatusOk) {
    try {
        $countSql           = "SELECT COUNT(*) FROM announcements";
        $totalAnnouncements = (int)$pdo->query($countSql)->fetchColumn();
        $pages              = max(1, (int)ceil($totalAnnouncements / $pageSize));

        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $pageSize;

        $listSql = "
            SELECT a.id,
                   a.content,
                   a.content_ru,
                   a.content_en,
                   a.content_es,
                   a.created_by,
                   a.created_at,
                   u.username,
                   u.first_name,
                   u.last_name
            FROM announcements a
            LEFT JOIN users u ON u.id = a.created_by
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($listSql);
        $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        $announcements = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('new_message.php fetch announcements error: ' . $e->getMessage());
        $errorMessage = $errorMessage ?: 'Ошибка загрузки списка объявлений.';
    }
}

/* ---------- форматирование имени автора ---------- */

function formatAuthor(array $row): string {
    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }
    if (!empty($row['username'])) {
        return '@' . $row['username'];
    }
    if (!empty($row['created_by'])) {
        return 'Пользователь #' . (int)$row['created_by'];
    }
    return 'Администратор';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Объявления - Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Fonts / Icons -->
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
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
            gap: 12px;
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
            flex-wrap: wrap;
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

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            gap: 24px;
        }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
            position: relative;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
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
            padding: 20px;
        }

        .card-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 16px;
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
            background: rgba(15,23,42,0.9);
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
            margin-bottom: 8px;
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

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .form-label .required {
            color: var(--danger);
            margin-left: 4px;
        }

        .lang-tag {
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--text-secondary);
        }

        .form-textarea {
            width: 100%;
            min-height: 110px;
            resize: vertical;
            padding: 12px 14px;
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.5;
            transition: var(--transition);
        }

        .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.15);
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .char-counter {
            font-size: 12px;
            color: var(--text-muted);
        }

        .translate-status {
            font-size: 12px;
            color: var(--text-muted);
            display:flex;
            align-items:center;
            gap:6px;
        }
        .translate-status i{font-size:12px;}

        .ann-list-wrapper {
            max-height: 540px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .ann-empty {
            text-align: center;
            padding: 40px 10px;
            color: var(--text-muted);
            font-size: 14px;
        }

        .ann-item {
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            padding: 12px 14px;
            margin-bottom: 10px;
            transition: var(--transition);
            font-size:13px;
        }

        .ann-item:hover {
            background: var(--bg-hover);
            transform: translateY(-1px);
        }

        .ann-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .ann-author {
            font-weight: 500;
        }

        .ann-date {
            font-size: 12px;
            opacity: 0.85;
        }

        .ann-lang-block{
            margin-top:4px;
            padding-top:4px;
            border-top:1px dashed rgba(148,163,184,.4);
        }
        .ann-lang-label{
            font-size:11px;
            color:var(--text-muted);
            margin-bottom:2px;
        }
        .ann-content {
            font-size: 14px;
            color: var(--text-primary);
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .pagination {
            display: flex;
            justify-content: center;
            padding: 12px 0 4px;
            margin-top: 8px;
            border-top: 1px solid var(--border);
        }

        .pagination-items {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination-item {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-item:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
            transform: translateY(-1px);
        }

        .pagination-item.active {
            background: var(--accent);
            color: #fff;
            transform: scale(1.08);
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

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }
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
            <h1>Объявления</h1>
            <p>Всего объявлений: <?= number_format($totalAnnouncements, 0, ',', ' ') ?></p>
        </div>
        <div class="header-actions">
            <div class="admin-pill">
                <i class="fa-solid fa-user-shield"></i>
                <span><?= h($adminDisplay) ?></span>
                <?php if (!empty($userRole)): ?>
                    <span style="opacity:0.9;">· <?= h($userRole) ?></span>
                <?php endif; ?>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <span>Назад</span>
            </a>
        </div>
    </div>

    <div class="layout">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class="fas fa-bullhorn"></i>
                        Создать объявление
                    </h2>
                    <div class="status-chip">
                        <span class="status-dot <?= $dbStatusOk ? 'ok' : 'bad' ?>"></span>
                        <span class="<?= $dbStatusOk ? 'status-text-ok' : 'status-text-bad' ?>">
                            <?= h($dbStatusMessage) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="card-subtitle">
                        Пишешь объявление на русском — английский и испанский переводятся автоматически по двум сервисам, берётся более точный вариант.
                    </div>

                    <?php if (!$dbStatusOk): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-plug-circle-xmark"></i>
                            <span>Создание объявлений временно недоступно: нет подключения к базе данных.</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($successMessage): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <span><?= h($successMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMessage): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span><?= h($errorMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="new_message.php" onsubmit="return handleSubmitAnnouncement(this);">
                        <div class="form-group">
                            <label class="form-label" for="content_ru">
                                <span>Текст на русском <span class="required">*</span></span>
                                <span class="lang-tag">Русский · ru</span>
                            </label>
                            <textarea
                                id="content_ru"
                                name="content_ru"
                                class="form-textarea"
                                placeholder="Введите текст объявления на русском..."
                                oninput="updateCharCounter(this)"
                                <?= $dbStatusOk ? '' : 'disabled' ?>
                            ><?= h($oldContentRu) ?></textarea>
                            <div class="form-hint">
                                На основе этого текста строятся английская и испанская версии.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="content_en">
                                <span>Текст на английском</span>
                                <span class="lang-tag">English · en</span>
                            </label>
                            <textarea
                                id="content_en"
                                name="content_en"
                                class="form-textarea"
                                placeholder="Английская версия появится автоматически..."
                                <?= $dbStatusOk ? '' : 'disabled' ?>
                            ><?= h($oldContentEn) ?></textarea>
                            <div class="form-hint">Авто-перевод можно поправить вручную перед сохранением.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="content_es">
                                <span>Текст на испанском</span>
                                <span class="lang-tag">Español · es</span>
                            </label>
                            <textarea
                                id="content_es"
                                name="content_es"
                                class="form-textarea"
                                placeholder="Испанская версия появится автоматически..."
                                <?= $dbStatusOk ? '' : 'disabled' ?>
                            ><?= h($oldContentEs) ?></textarea>
                            <div class="form-hint">Тоже доступна ручная правка.</div>
                        </div>

                        <div class="form-footer">
                            <div class="char-counter" id="charCounter">0 символов</div>
                            <div class="translate-status" id="translateStatus">
                                <i class="fa-regular fa-circle-check"></i>
                                <i class="fa-solid fa-spinner fa-spin" style="display:none;"></i>
                                <span>Авто-перевод включён</span>
                            </div>
                            <button type="submit" class="btn btn-primary" <?= $dbStatusOk ? '' : 'disabled' ?>>
                                <i class="fas fa-paper-plane"></i>
                                <span>Отправить</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class="fas fa-list-ul"></i>
                        Список объявлений
                    </h2>
                </div>
                <div class="card-body">
                    <div class="card-subtitle">
                        В боте пользователь увидит только одну версию объявления — в зависимости от языка, выбранного при регистрации.
                    </div>

                    <div class="ann-list-wrapper">
                        <?php if (!$dbStatusOk): ?>
                            <div class="ann-empty">
                                <i class="fas fa-plug-circle-xmark" style="font-size: 32px; margin-bottom: 8px; opacity: 0.6; display: block;"></i>
                                Невозможно загрузить список объявлений: нет подключения к БД.
                            </div>
                        <?php elseif (empty($announcements)): ?>
                            <div class="ann-empty">
                                <i class="fas fa-bullhorn" style="font-size: 32px; margin-bottom: 8px; opacity: 0.6; display: block;"></i>
                                Пока нет ни одного объявления
                            </div>
                        <?php else: ?>
                            <?php foreach ($announcements as $ann): ?>
                                <div class="ann-item">
                                    <div class="ann-meta">
                                        <span class="ann-author">
                                            <i class="fas fa-user"></i>
                                            <?= h(formatAuthor($ann)) ?>
                                        </span>
                                        <span class="ann-date">
                                            <i class="far fa-clock"></i>
                                            <?= h(date('d.m.Y H:i', strtotime($ann['created_at']))) ?>
                                        </span>
                                    </div>

                                    <?php
                                    $ru = $ann['content_ru'] ?? '';
                                    if ($ru === '') $ru = $ann['content'] ?? '';
                                    if ($ru !== ''):
                                    ?>
                                        <div class="ann-lang-block">
                                            <div class="ann-lang-label">Русский</div>
                                            <div class="ann-content"><?= nl2br(h($ru)) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ann['content_en'])): ?>
                                        <div class="ann-lang-block">
                                            <div class="ann-lang-label">English</div>
                                            <div class="ann-content"><?= nl2br(h($ann['content_en'])) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ann['content_es'])): ?>
                                        <div class="ann-lang-block">
                                            <div class="ann-lang-label">Español</div>
                                            <div class="ann-content"><?= nl2br(h($ann['content_es'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($dbStatusOk && $pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-items">
                                <?php
                                $base = strtok($_SERVER['REQUEST_URI'], '?');
                                $qs   = $_GET;
                                unset($qs['page']);
                                $build = function($p) use ($base, $qs) {
                                    $qs['page'] = $p;
                                    return h($base . '?' . http_build_query($qs));
                                };
                                ?>
                                <a class="pagination-item" href="<?= $build(max(1, $page - 1)); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($pages, $page + 2);
                                if ($start > 1): ?>
                                    <a class="pagination-item" href="<?= $build(1); ?>">1</a>
                                    <?php if ($start > 2): ?>
                                        <span class="pagination-item">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p = $start; $p <= $end; $p++): ?>
                                    <a class="pagination-item <?= $p == $page ? 'active' : ''; ?>"
                                       href="<?= $build($p); ?>"><?= $p; ?></a>
                                <?php endfor; ?>
                                <?php if ($end < $pages): ?>
                                    <?php if ($end < $pages - 1): ?>
                                        <span class="pagination-item">...</span>
                                    <?php endif; ?>
                                    <a class="pagination-item" href="<?= $build($pages); ?>"><?= $pages; ?></a>
                                <?php endif; ?>
                                <a class="pagination-item" href="<?= $build(min($pages, $page + 1)); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function updateCharCounter(textarea) {
        const counter = document.getElementById('charCounter');
        if (!counter) return;
        const len = textarea.value.length;
        let text;
        if (len === 1) {
            text = len + ' символ';
        } else if (len >= 2 && len <= 4) {
            text = len + ' символа';
        } else {
            text = len + ' символов';
        }
        counter.textContent = text;
    }

    function handleSubmitAnnouncement(form) {
        const textarea = form.querySelector('#content_ru');
        if (!textarea || textarea.disabled) return false;
        const value = textarea.value.trim();
        if (!value) {
            alert('Введите текст объявления на русском.');
            textarea.focus();
            return false;
        }
        return true;
    }

    (function() {
        const ru = document.getElementById('content_ru');
        const en = document.getElementById('content_en');
        const es = document.getElementById('content_es');
        const status = document.getElementById('translateStatus');
        if (!ru || !en || !es || !status) return;

        const icons = status.querySelectorAll('i');
        const iconOk   = icons[0];
        const iconSpin = icons[1];
        const statusText = status.querySelector('span');

        let timer = null;
        let lastSentText = '';

        function setIdle() {
            iconSpin.style.display = 'none';
            iconOk.style.display   = 'inline-block';
            statusText.textContent = 'Авто-перевод включён';
        }

        function setTranslating() {
            iconOk.style.display   = 'none';
            iconSpin.style.display = 'inline-block';
            statusText.textContent = 'Переводим...';
        }

        function setError() {
            iconOk.style.display   = 'none';
            iconSpin.style.display = 'none';
            statusText.textContent = 'Ошибка перевода, можно править вручную';
        }

        setIdle();

        ru.addEventListener('input', function() {
            const text = ru.value.trim();

            updateCharCounter(ru);

            if (!text) {
                clearTimeout(timer);
                en.value = '';
                es.value = '';
                setIdle();
                return;
            }

            if (text === lastSentText) return;

            clearTimeout(timer);
            setTranslating();

            timer = setTimeout(function() {
                lastSentText = text;

                const body = new URLSearchParams();
                body.append('ajax', 'translate');
                body.append('text_ru', text);

                fetch('new_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.ok) {
                            let any = false;

                            if (typeof data.en === 'string' && data.en.trim() !== '') {
                                en.value = data.en;
                                any = true;
                            }
                            if (typeof data.es === 'string' && data.es.trim() !== '') {
                                es.value = data.es;
                                any = true;
                            }

                            if (any) setIdle(); else setError();
                        } else {
                            setError();
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        setError();
                    });
            }, 600);
        });
    })();

    document.addEventListener('DOMContentLoaded', function () {
        const textarea = document.getElementById('content_ru');
        if (textarea) {
            updateCharCounter(textarea);
        }
    });
</script>
</body>
</html>
