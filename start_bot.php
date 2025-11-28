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

function roleLabel($r) {
    switch ($r) {
        case 'owner':   return 'Владелец';
        case 'admin':   return 'Администратор';
        case 'manager': return 'Менеджер';
        default:        return 'Пользователь';
    }
}

/**
 * ВСЕГДА RU → EN или RU → ES, в зависимости от $targetLang
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
        CURLOPT_TIMEOUT        => 10,
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
 * Основной перевод: сначала LibreTranslate (RU → EN / RU → ES), потом MyMemory
 */
function translateText(string $text, string $targetLang, string $sourceLang = 'ru'): string {
    $text = trim($text);
    if ($text === '') return '';

    // 1) LibreTranslate: RU → EN или RU → ES 
    $ltUrl = 'https://libretranslate.de/translate';
    $payload = http_build_query([
        'q'      => $text,
        'source' => $sourceLang,     // RU
        'target' => $targetLang,     // EN или ES
        'format' => 'text',
    ]);

    $ch = curl_init($ltUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
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
                $translated = trim($data['translatedText']);
                if ($translated !== '' &&
                    trim(mb_strtolower($translated, 'UTF-8')) !== trim(mb_strtolower($text, 'UTF-8'))) {
                    return $translated;
                }
            }
        }
    } else {
        curl_close($ch);
    }

    // 2) Фолбэк — MyMemory: RU → EN / RU → ES
    return translateTextMyMemory($text, $targetLang, $sourceLang);
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

$ALLOWED_ROLES = ['owner', 'admin', 'manager'];

if (!in_array($adminRole, $ALLOWED_ROLES, true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- БД ---------- */
$pdo = db();

// Проверка админа в БД
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
    error_log('start_bot.php admin fetch error: ' . $e->getMessage());
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

/* ---------- таблица bot_messages с 3 языками ---------- */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bot_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_text TEXT NOT NULL,
        message_ru   TEXT NULL,
        message_en   TEXT NULL,
        message_es   TEXT NULL,
        photo_path   VARCHAR(500) NULL,
        created_by   INT UNSIGNED NOT NULL,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address   VARCHAR(45) NULL,
        user_agent   VARCHAR(255) NULL,
        PRIMARY KEY (id),
        KEY idx_created_by (created_by),
        CONSTRAINT fk_bot_messages_admins
            FOREIGN KEY (created_by) REFERENCES admins(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

try { $pdo->exec("ALTER TABLE bot_messages ADD COLUMN message_ru TEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE bot_messages ADD COLUMN message_en TEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE bot_messages ADD COLUMN message_es TEXT NULL"); } catch (Throwable $e) {}

/* ---------- AJAX: перевод RU → EN и RU → ES ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'translate') {
    header('Content-Type: application/json; charset=utf-8');
    $textRu = trim($_POST['text_ru'] ?? '');
    if ($textRu === '') {
        echo json_encode(['ok' => false, 'error' => 'empty_text'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // ВАЖНО: обе строки переводим ИМЕННО RU → EN и RU → ES
        $en = translateText($textRu, 'en', 'ru');
        $es = translateText($textRu, 'es', 'ru');

        echo json_encode([
            'ok' => true,
            'en' => $en,
            'es' => $es,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'translate_failed',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- Обработка сохранения сообщения (PRG — без дублей на F5) ---------- */
$save_errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_message']) && $_POST['save_message'] == '1') {
    $message_ru = trim($_POST['message_text_ru'] ?? '');
    $message_en = trim($_POST['message_text_en'] ?? '');
    $message_es = trim($_POST['message_text_es'] ?? '');

    $has_photo  = isset($_FILES['message_photo']) && $_FILES['message_photo']['error'] === UPLOAD_ERR_OK;

    if ($message_ru === '') {
        $save_errors[] = "Текст сообщения на русском обязателен";
    }

    if (empty($save_errors)) {
        try {
            if ($message_en === '') $message_en = $message_ru;
            if ($message_es === '') $message_es = $message_ru;

            $photo_path = null;
            if ($has_photo) {
                $upload_dir_fs  = __DIR__ . '/uploads/bot_messages/';
                $upload_dir_url = 'uploads/bot_messages/';

                if (!is_dir($upload_dir_fs)) {
                    mkdir($upload_dir_fs, 0755, true);
                }

                $file_extension = pathinfo($_FILES['message_photo']['name'], PATHINFO_EXTENSION);
                $filename       = uniqid('botmsg_', true) . '.' . $file_extension;

                $fs_path  = $upload_dir_fs . $filename;
                $url_path = $upload_dir_url . $filename;

                if (!move_uploaded_file($_FILES['message_photo']['tmp_name'], $fs_path)) {
                    throw new Exception("Ошибка загрузки изображения");
                }

                $photo_path = $url_path;
            }

            $stmt = $pdo->prepare("
                INSERT INTO bot_messages 
                    (message_text, message_ru, message_en, message_es, photo_path, created_by, ip_address, user_agent)
                VALUES 
                    (:message_text, :message_ru, :message_en, :message_es, :photo_path, :created_by, :ip_address, :user_agent)
            ");

            $stmt->execute([
                ':message_text' => $message_ru,
                ':message_ru'   => $message_ru,
                ':message_en'   => $message_en,
                ':message_es'   => $message_es,
                ':photo_path'   => $photo_path,
                ':created_by'   => $ADMIN_ID,
                ':ip_address'   => $_SERVER['REMOTE_ADDR']      ?? 'unknown',
                ':user_agent'   => $_SERVER['HTTP_USER_AGENT']  ?? 'unknown',
            ]);

            header('Location: start_bot.php?saved=1');
            exit;

        } catch (Exception $e) {
            $save_errors[] = "Ошибка при сохранении сообщения: " . $e->getMessage();
        }
    }
}

$message_saved = (isset($_GET['saved']) && $_GET['saved'] == '1');

/* ---------- История сообщений ---------- */
$stmt = $pdo->prepare("
    SELECT 
        bm.*,
        a.name  AS admin_name,
        a.email AS admin_email,
        a.role  AS admin_role
    FROM bot_messages bm
    LEFT JOIN admins a ON bm.created_by = a.id
    ORDER BY bm.created_at DESC
    LIMIT 50
");
$stmt->execute();
$message_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление ботом - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

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
            align-items: flex-start;
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

        .admin-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.4);
            font-size: 12px;
            color: var(--text-secondary);
        }
        .admin-pill i { font-size: 12px; }

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
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width .5s, height .5s;
        }
        .btn:active::before {
            width: 220px;
            height: 220px;
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

        .btn-success {
            background: var(--success);
            color: #fff;
            box-shadow: var(--shadow);
        }

        .btn-success:hover {
            background: #0da271;
            transform: translateY(-2px);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 968px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .message-form-container {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        .form-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .form-title {
            font-size: 18px;
            font-weight: 600;
        }

        .translate-status {
            font-size: 12px;
            color: var(--text-muted);
            display:flex;
            align-items:center;
            gap:6px;
        }
        .translate-status i {
            font-size: 12px;
        }

        .form-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 13px;
        }

        .form-label span.lang-tag{
            font-size:11px;
            padding:2px 7px;
            border-radius:999px;
            border:1px solid var(--border);
            color:var(--text-secondary);
        }

        .form-label .required {
            color: var(--danger);
            margin-left:4px;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 14px;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.1);
        }

        .form-textarea {
            min-height: 110px;
            resize: vertical;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .file-upload {
            position: relative;
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            padding: 22px 18px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--accent);
            background: rgba(139, 92, 246, 0.05);
        }

        .file-upload i {
            font-size: 22px;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: block;
        }

        .file-upload input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-info {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            margin-top: 10px;
        }

        .history-container {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: slideUp 0.8s ease-out;
        }

        .history-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-title {
            font-size: 18px;
            font-weight: 600;
        }

        .history-body {
            padding: 0;
            max-height: 600px;
            overflow-y: auto;
        }

        .message-list {
            display: flex;
            flex-direction: column;
        }

        .message-item {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
            font-size:13px;
        }

        .message-item:hover {
            background: var(--bg-hover);
        }

        .message-item:last-child {
            border-bottom: none;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .message-author {
            font-weight: 600;
            color: var(--accent);
            font-size: 13px;
        }

        .message-date {
            font-size: 11px;
            color: var(--text-muted);
        }

        .msg-lang-block{
            margin-top:4px;
            padding-top:4px;
            border-top:1px dashed rgba(148,163,184,.4);
        }
        .msg-lang-label{
            font-size:11px;
            color:var(--text-muted);
            margin-bottom:2px;
        }

        .message-text {
            line-height: 1.4;
            white-space:pre-wrap;
        }

        .message-photo {
            max-width: 200px;
            border-radius: var(--radius-sm);
            margin-top: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
            display: block;
        }

        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 18px;
            border-left: 4px solid transparent;
            font-size: 13px;
            animation: slideUp 0.4s ease-out;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .alert ul {
            margin: 6px 0 0 20px;
        }

        .alert li {
            margin-bottom: 2px;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="page-title">
                <h1>Управление ботом</h1>
                <p>
                    Стартовые сообщения для Telegram бота на русском, английском и испанском<br>
                    <span style="font-size: 12px; color: var(--text-muted);">
                        <?php echo h($adminDisplay); ?> · <?php echo h(roleLabel($adminRole)); ?>
                    </span>
                </p>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div class="admin-pill">
                    <i class="fa-solid fa-user-shield"></i>
                    <span><?php echo h($adminDisplay); ?></span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>Назад
                    </a>
                </div>
            </div>
        </div>

        <!-- Уведомления -->
        <?php if ($message_saved): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Сообщение на всех языках успешно сохранено!
            </div>
        <?php endif; ?>

        <?php if (!empty($save_errors)): ?>
            <div class="alert alert-error">
                <strong><i class="fas fa-exclamation-circle"></i> Ошибки при сохранении:</strong>
                <ul>
                    <?php foreach ($save_errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Основной контент -->
        <div class="content-grid">
            <!-- Форма -->
            <div class="message-form-container">
                <div class="form-header">
                    <div class="form-title">Новое стартовое сообщение</div>
                    <div class="translate-status" id="translate-status">
                        <i class="fa-regular fa-circle-check"></i>
                        <i class="fa-solid fa-spinner fa-spin" style="display:none;"></i>
                        <span>Авто-перевод включен</span>
                    </div>
                </div>

                <div class="form-body">
                    <form method="POST" enctype="multipart/form-data">
                        <!-- RU -->
                        <div class="form-group">
                            <label class="form-label">
                                <span>Текст на русском <span class="required">*</span></span>
                                <span class="lang-tag">Русский · ru</span>
                            </label>
                            <textarea class="form-textarea" id="msg_ru" name="message_text_ru"
                                      placeholder="Введите текст сообщения на русском..."
                                      required><?php
                                echo isset($save_errors[0]) && !empty($_POST['message_text_ru'])
                                    ? h($_POST['message_text_ru'])
                                    : '';
                            ?></textarea>
                            <div class="form-hint">Пишешь здесь — английский и испанский подтягиваются сами.</div>
                        </div>

                        <!-- EN -->
                        <div class="form-group">
                            <label class="form-label">
                                <span>Текст на английском</span>
                                <span class="lang-tag">English · en</span>
                            </label>
                            <textarea class="form-textarea" id="msg_en" name="message_text_en"
                                      placeholder="Английская версия появится автоматически..."><?php
                                echo isset($save_errors[0]) && !empty($_POST['message_text_en'])
                                    ? h($_POST['message_text_en'])
                                    : '';
                            ?></textarea>
                            <div class="form-hint">Можно править руками, авто-перевод — черновик.</div>
                        </div>

                        <!-- ES -->
                        <div class="form-group">
                            <label class="form-label">
                                <span>Текст на испанском</span>
                                <span class="lang-tag">Español · es</span>
                            </label>
                            <textarea class="form-textarea" id="msg_es" name="message_text_es"
                                      placeholder="Испанская версия появится автоматически..."><?php
                                echo isset($save_errors[0]) && !empty($_POST['message_text_es'])
                                    ? h($_POST['message_text_es'])
                                    : '';
                            ?></textarea>
                            <div class="form-hint">Тоже можно допилить вручную.</div>
                        </div>

                        <!-- Файл -->
                        <div class="form-group">
                            <label class="form-label">
                                <span>Изображение для сообщения</span>
                            </label>
                            <div class="file-upload">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div>Нажмите для загрузки изображения</div>
                                <div class="file-info">PNG, JPG до 5MB</div>
                                <input type="file" name="message_photo" accept="image/*">
                            </div>
                            <div class="form-hint">Опционально, картинка к стартовому сообщению.</div>
                        </div>

                        <!-- Сабмит -->
                        <div class="form-actions">
                            <button type="submit" name="save_message" value="1" class="btn btn-success">
                                <i class="fas fa-save"></i>Сохранить сообщение
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- История -->
            <div class="history-container">
                <div class="history-header">
                    <div class="history-title">История сообщений</div>
                    <div class="history-count">
                        <span style="font-size: 12px; color: var(--text-muted);">
                            <?php echo count($message_history); ?> сообщений
                        </span>
                    </div>
                </div>

                <div class="history-body">
                    <?php if (empty($message_history)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>Нет сообщений</h3>
                            <p>Сообщения появятся здесь после их создания</p>
                        </div>
                    <?php else: ?>
                        <div class="message-list">
                            <?php foreach ($message_history as $message): ?>
                                <div class="message-item">
                                    <div class="message-header">
                                        <div class="message-author">
                                            <?php
                                            $aName  = trim($message['admin_name'] ?? '');
                                            $aEmail = trim($message['admin_email'] ?? '');
                                            $label  = $aName ?: $aEmail;
                                            if ($label === '') {
                                                $label = 'Админ #' . (int)$message['created_by'];
                                            }
                                            echo h($label) . ' · ' . h(roleLabel($message['admin_role'] ?? 'admin'));
                                            ?>
                                        </div>
                                        <div class="message-date">
                                            <?php echo date('d.m.Y H:i', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>

                                    <!-- RU -->
                                    <?php if (!empty($message['message_ru']) || !empty($message['message_text'])): ?>
                                        <div class="msg-lang-block">
                                            <div class="msg-lang-label">Русский</div>
                                            <div class="message-text">
                                                <?php
                                                $txtRu = $message['message_ru'] ?? '';
                                                if ($txtRu === '') $txtRu = $message['message_text'];
                                                echo nl2br(h($txtRu));
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- EN -->
                                    <?php if (!empty($message['message_en'])): ?>
                                        <div class="msg-lang-block">
                                            <div class="msg-lang-label">English</div>
                                            <div class="message-text">
                                                <?php echo nl2br(h($message['message_en'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- ES -->
                                    <?php if (!empty($message['message_es'])): ?>
                                        <div class="msg-lang-block">
                                            <div class="msg-lang-label">Español</div>
                                            <div class="message-text">
                                                <?php echo nl2br(h($message['message_es'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($message['photo_path'])): ?>
                                        <img src="<?php echo h($message['photo_path']); ?>"
                                             alt="Прикрепленное изображение"
                                             class="message-photo"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Авто-перевод RU → EN и RU → ES при вводе русского текста
        (function() {
            const ru = document.getElementById('msg_ru');
            const en = document.getElementById('msg_en');
            const es = document.getElementById('msg_es');
            const status = document.getElementById('translate-status');
            const icons = status.querySelectorAll('i');
            const iconOk = icons[0];
            const iconSpin = icons[1];
            const statusText = status.querySelector('span');

            let timer = null;
            let lastSentText = '';

            function setIdle() {
                iconSpin.style.display = 'none';
                iconOk.style.display = 'inline-block';
                statusText.textContent = 'Авто-перевод включен';
            }

            function setTranslating() {
                iconOk.style.display = 'none';
                iconSpin.style.display = 'inline-block';
                statusText.textContent = 'Переводим...';
            }

            function setError() {
                iconOk.style.display = 'none';
                iconSpin.style.display = 'none';
                statusText.textContent = 'Ошибка перевода, можно дописать вручную';
            }

            setIdle();

            ru.addEventListener('input', function() {
                const text = ru.value.trim();

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

                    fetch('start_bot.php', {
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

                            if (any) {
                                setIdle();
                            } else {
                                setError();
                            }
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

        // Обработка выбора файла
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const fileInfo = document.querySelector('.file-info');
                fileInfo.textContent = `Выбран файл: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;

                if (file.size > 5 * 1024 * 1024) {
                    alert('Файл слишком большой. Максимальный размер: 5MB');
                    e.target.value = '';
                    fileInfo.textContent = 'PNG, JPG до 5MB';
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const historyBody = document.querySelector('.history-body');
            if (historyBody) historyBody.scrollTop = 0;
        });
    </script>
</body>
</html>
