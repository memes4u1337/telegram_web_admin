<?php
// new_limit.php — управление лимитами поиска (таблица search_limits)

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

/**
 * Русская плюрализация дней/месяцев
 */
if (!function_exists('ru_plural')) {
    function ru_plural($number, $form1, $form2, $form5)
    {
        $n  = abs((int)$number) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $form5;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $form2;
        }
        if ($n1 === 1) {
            return $form1;
        }
        return $form5;
    }
}

/**
 * Фолбэк-перевод через MyMemory
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
        'source' => $sourceLang,
        'target' => $targetLang,
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

    // 2) фолбэк — MyMemory
    return translateTextMyMemory($text, $targetLang, $sourceLang);
}

/**
 * Генерация "базы" кода лимита из названия
 */
function generateLimitCodeBase(string $title): string {
    $base = strtolower($title);
    $base = preg_replace('/[^a-z0-9]+/', '_', $base);
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'limit';
    }
    return $base;
}

/* ---------- строгая проверка ADMIN ---------- */
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

/* ---------- проверка админа в БД (таблица admins) ---------- */
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
    error_log('new_limit.php admin fetch error: ' . $e->getMessage());
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

/* ---------- AJAX-обработчики ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $dbStatusOk) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_POST['ajax'];

    // AJAX: проверка названия лимита (латиница + уникальность)
    if ($ajax === 'check_title') {
        $title = trim($_POST['title'] ?? '');
        $errors = [];

        if ($title === '') {
            $errors[] = 'Введите название лимита.';
        } elseif (preg_match('/[А-Яа-яЁё]/u', $title)) {
            $errors[] = 'Название только на английском (латиница, без кириллицы).';
        } elseif (!preg_match('/^[A-Za-z0-9 _\-\.\(\)]+$/', $title)) {
            $errors[] = 'Разрешены только латиница, цифры, пробелы и символы - _ . ( ).';
        }

        $is_unique = true;
        $existsByTitle = false;
        $base = '';
        $codePreview = '';

        if (empty($errors)) {
            // проверка по title
            try {
                $st = $pdo->prepare("SELECT 1 FROM search_limits WHERE title = :t LIMIT 1");
                $st->execute([':t' => $title]);
                if ($st->fetch()) {
                    $existsByTitle = true;
                    $is_unique = false;
                    $errors[] = 'Лимит с таким названием уже существует.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Ошибка проверки названия: ' . $e->getMessage();
            }

            // превью кода лимита (как будет сохранён)
            $base = generateLimitCodeBase($title);
            $code = $base;
            $suffix = 1;
            try {
                $check = $pdo->prepare("SELECT 1 FROM search_limits WHERE code = :c LIMIT 1");
                while (true) {
                    $check->execute([':c' => $code]);
                    if (!$check->fetch()) {
                        break;
                    }
                    $code = $base . '_' . $suffix;
                    $suffix++;
                }
                $codePreview = $code;
            } catch (Throwable $e) {
                $codePreview = $base;
            }
        }

        echo json_encode([
            'ok'          => empty($errors),
            'errors'      => $errors,
            'is_unique'   => $is_unique,
            'existsTitle' => $existsByTitle,
            'slug_base'   => $base,
            'code'        => $codePreview,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // AJAX: перевод описания лимита RU → EN и RU → ES
    if ($ajax === 'translate_description') {
        $textRu = trim($_POST['text_ru'] ?? '');
        if ($textRu === '') {
            echo json_encode([
                'ok'    => false,
                'error' => 'empty_text',
            ], JSON_UNESCAPED_UNICODE);
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
            echo json_encode([
                'ok'    => false,
                'error' => 'translate_failed',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    echo json_encode([
        'ok'    => false,
        'error' => 'unknown_ajax',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- обработка формы создания лимита ---------- */
$errors  = [];
$success = '';

$title           = '';
$description     = ''; // русская версия (основная)
$descriptionEn   = '';
$descriptionEs   = '';
$priceRaw        = '';
$searchLimit     = 10; // сколько успешных поисков за 1 день (дефолт)
$durationDays    = 1;  // ВСЕГДА 1 день

if ($dbStatusOk && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');      // RU
    $descriptionEn = trim($_POST['description_en'] ?? '');   // EN (черновик / ручной)
    $descriptionEs = trim($_POST['description_es'] ?? '');   // ES (черновик / ручной)
    $priceRaw      = trim($_POST['price'] ?? '');
    $searchLimit   = (int)($_POST['search_limit'] ?? 0);

    // Валидация: название только латиница / цифры / пробелы / - _ . ( )
    if ($title === '') {
        $errors[] = 'Введите название лимита.';
    } elseif (preg_match('/[А-Яа-яЁё]/u', $title)) {
        $errors[] = 'Название только на английском (латиница, без кириллицы).';
    } elseif (!preg_match('/^[A-Za-z0-9 _\-\.\(\)]+$/', $title)) {
        $errors[] = 'Разрешены только латиница, цифры, пробелы и символы - _ . ( ).';
    }

    if ($priceRaw === '' || !is_numeric(str_replace(',', '.', $priceRaw))) {
        $errors[] = 'Введите корректную цену.';
    } else {
        $price = (float)str_replace(',', '.', $priceRaw);
        if ($price < 0) {
            $errors[] = 'Цена не может быть меньше нуля.';
        }
    }

    if ($searchLimit <= 0) {
        $errors[] = 'Укажите количество успешных поисков (минимум 1).';
    }

    // Дополнительная проверка уникальности названия в БД
    if ($title !== '') {
        try {
            $st = $pdo->prepare("SELECT 1 FROM search_limits WHERE title = :t LIMIT 1");
            $st->execute([':t' => $title]);
            if ($st->fetch()) {
                $errors[] = 'Лимит с таким названием уже существует.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Ошибка проверки уникальности названия: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $price = (float)str_replace(',', '.', $priceRaw);

        // Генерируем code на основе названия: lower-case slug
        $base = generateLimitCodeBase($title);

        // Проверка уникальности кода
        $code = $base;
        $suffix = 1;
        try {
            $check = $pdo->prepare("SELECT 1 FROM search_limits WHERE code = :c LIMIT 1");
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

        // ---------- МУЛЬТИЯЗЫЧНОСТЬ ОПИСАНИЯ ----------
        $descriptionRu      = $description !== '' ? $description : null;
        $descriptionEnFinal = $descriptionEn !== '' ? $descriptionEn : null;
        $descriptionEsFinal = $descriptionEs !== '' ? $descriptionEs : null;

        if ($descriptionRu !== null) {
            // если EN не заполнен руками — переводим
            if ($descriptionEnFinal === null) {
                try {
                    $trEn = translateText($descriptionRu, 'en', 'ru');
                    $descriptionEnFinal = $trEn !== '' ? $trEn : $descriptionRu;
                } catch (Throwable $e) {
                    $descriptionEnFinal = $descriptionRu;
                }
            }
            // если ES не заполнен руками — переводим
            if ($descriptionEsFinal === null) {
                try {
                    $trEs = translateText($descriptionRu, 'es', 'ru');
                    $descriptionEsFinal = $trEs !== '' ? $trEs : $descriptionRu;
                } catch (Throwable $e) {
                    $descriptionEsFinal = $descriptionRu;
                }
            }
        }

        // ---------- МУЛЬТИЯЗЫЧНЫЙ ПЕРИОД (ВСЕГДА 1 ДЕНЬ) ----------
        $durationDays = 1;
        $durationLabelRu = $durationDays . ' ' . ru_plural($durationDays, 'день', 'дня', 'дней'); // "1 день"
        $durationLabelEn = $durationDays . ' ' . ((abs($durationDays) === 1) ? 'day' : 'days');  // "1 day"
        $durationLabelEs = $durationDays . ' ' . ((abs($durationDays) === 1) ? 'día' : 'días'); // "1 día"

        try {
            // ВСТАВКА С НОВЫМИ ПОЛЯМИ МУЛЬТИЯЗЫЧНОСТИ
            $stmt = $pdo->prepare("
                INSERT INTO search_limits
                    (
                        code,
                        title,
                        search_limit,
                        description,
                        description_ru,
                        description_en,
                        description_es,
                        price,
                        duration_days,
                        duration_label_ru,
                        duration_label_en,
                        duration_label_es,
                        sort_order,
                        is_active
                    )
                VALUES
                    (
                        :code,
                        :title,
                        :search_limit,
                        :description,
                        :description_ru,
                        :description_en,
                        :description_es,
                        :price,
                        :duration_days,
                        :duration_label_ru,
                        :duration_label_en,
                        :duration_label_es,
                        :sort_order,
                        1
                    )
            ");
            $stmt->execute([
                ':code'              => $code,
                ':title'             => $title,                 // EN-название
                ':search_limit'      => $searchLimit,
                ':description'       => $descriptionRu,         // старое поле: RU как базовый
                ':description_ru'    => $descriptionRu,
                ':description_en'    => $descriptionEnFinal,
                ':description_es'    => $descriptionEsFinal,
                ':price'             => $price,
                ':duration_days'     => $durationDays,
                ':duration_label_ru' => $durationLabelRu,
                ':duration_label_en' => $durationLabelEn,
                ':duration_label_es' => $durationLabelEs,
                ':sort_order'        => 0,
            ]);

            $success = 'Лимит успешно создан. Код лимита: ' . $code;

            // сбрасываем форму
            $title         = '';
            $description   = '';
            $descriptionEn = '';
            $descriptionEs = '';
            $priceRaw      = '';
            $searchLimit   = 10;
        } catch (Throwable $e) {
            $errors[] = 'Ошибка при сохранении лимита: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лимиты поиска - Admin Panel</title>
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
            transform: translate(-50%, -50%);
            transition: width .5s, height .5s;
        }
        .btn:active::before {
            width: 220px;
            height: 220px;
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
            gap: 12px;
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

        .limit-form-title{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:12px;
        }
        .limit-form-icon{
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
        .limit-form-subtitle{
            font-size:13px;
            color:var(--text-secondary);
            margin-top:2px;
        }

        .field-label{
            font-size:13px;
            font-weight:500;
            color:#d4d4f5;
            margin-bottom:4px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
        }

        .field-label .badge-mini{
            font-size:11px;
            padding:2px 8px;
            border-radius:999px;
            border:1px solid rgba(148,163,184,0.5);
            color:var(--text-secondary);
        }

        .input,
        .textarea,
        .limit-select{
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
        .limit-select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 1px rgba(139,92,246,0.5);
        }
        .textarea{
            resize:vertical;
            min-height:70px;
        }

        .limit-grid{
            display:flex;
            flex-direction:column;
            gap:12px;
            margin-top:8px;
        }

        .limit-msg{
            margin-bottom:10px;
            padding:10px 12px;
            border-radius:12px;
            font-size:13px;
            line-height:1.4;
        }
        .limit-msg.success{
            background:rgba(16,185,129,0.08);
            border:1px solid rgba(16,185,129,0.7);
            color:var(--success);
        }
        .limit-msg.error{
            background:rgba(239,68,68,0.06);
            border:1px solid rgba(248,113,113,0.8);
            color:var(--danger);
        }

        .inline-hint{
            font-size:11px;
            color:var(--text-muted);
            margin-top:4px;
        }

        .title-status{
            margin-top:5px;
            font-size:12px;
            display:flex;
            align-items:center;
            gap:6px;
        }
        .title-status i{
            font-size:12px;
        }
        .title-status.ok{
            color:var(--success);
        }
        .title-status.error{
            color:var(--danger);
        }
        .title-status.info{
            color:var(--text-secondary);
        }

        .code-preview{
            font-size:12px;
            color:var(--text-secondary);
            margin-top:4px;
        }

        .multilang-block{
            margin-top:8px;
            padding:10px 12px;
            border-radius:12px;
            border:1px dashed rgba(148,163,184,0.6);
            background:rgba(15,23,42,0.7);
        }
        .multilang-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:8px;
            font-size:13px;
            font-weight:500;
        }
        .multilang-status{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:11px;
            color:var(--text-muted);
        }
        .multilang-status i{
            font-size:11px;
        }
        .multilang-grid{
            display:grid;
            grid-template-columns:1fr 1fr 1fr;
            gap:8px;
        }
        @media(max-width:860px){
            .multilang-grid{
                grid-template-columns:1fr;
            }
        }

        .duration-preview{
            margin-top:10px;
            padding:10px 12px;
            border-radius:12px;
            background:linear-gradient(135deg, rgba(15,23,42,0.9), rgba(37,99,235,0.15));
            border:1px solid rgba(59,130,246,0.3);
            font-size:12px;
        }
        .duration-preview-title{
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:0.06em;
            color:var(--text-secondary);
            margin-bottom:6px;
        }
        .duration-preview-row{
            display:flex;
            flex-wrap:wrap;
            gap:6px 16px;
        }
        .duration-chip{
            padding:4px 8px;
            border-radius:999px;
            background:rgba(15,23,42,0.9);
            border:1px solid rgba(148,163,184,0.5);
            font-size:11px;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }
        .duration-chip span.lang{
            font-weight:500;
            opacity:.8;
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
    <!-- Заголовок -->
    <div class="header">
        <div class="page-title">
            <h1>Лимиты поиска</h1>
            <p>Создание лимитов на количество успешных поисков за 1 день</p>
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
                <i class="fas fa-layer-group"></i>
                Новый лимит
            </h2>
            <div class="status-chip">
                <span class="status-dot <?= $dbStatusOk ? 'ok' : 'bad' ?>"></span>
                <span class="<?= $dbStatusOk ? 'status-text-ok' : 'status-text-bad' ?>">
                    <?= h($dbStatusMessage) ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="limit-form-title">
                <div class="limit-form-icon">⚡</div>
                <div>
                    <div style="font-size:18px;font-weight:600;margin-bottom:2px;">
                        Создание лимита
                    </div>
                    <div class="limit-form-subtitle">
                        Название — только на английском. Лимит задаёт, сколько успешных поисков доступно в течение 1 дня.
                    </div>
                </div>
            </div>

            <?php if (!empty($success)): ?>
                <div class="limit-msg success js-flash-msg">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="limit-msg error js-flash-msg">
                    <?php foreach ($errors as $err): ?>
                        <div><?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$dbStatusOk): ?>
                <div class="limit-msg error js-flash-msg">
                    База данных недоступна. Проверь .env / соединение.
                </div>
            <?php else: ?>
                <form method="post" class="form" id="limit-form">
                    <div class="limit-grid">
                        <!-- Название -->
                        <div>
                            <label class="field-label">
                                <span>Название лимита</span>
                                <span class="badge-mini">EN only</span>
                            </label>
                            <input type="text"
                                   name="title"
                                   id="title-input"
                                   class="input"
                                   maxlength="100"
                                   required
                                   value="<?= h($title) ?>"
                                   placeholder="Например: Basic Pack, Pro Limit">
                            <div class="inline-hint">
                                Латиница, цифры, пробелы и символы - _ . ( ). Русские буквы запрещены.
                            </div>
                            <div id="title-status" class="title-status info">
                                <i class="fa-regular fa-circle-question"></i>
                                <span>Проверяем название по мере ввода…</span>
                            </div>
                            <div id="code-preview" class="code-preview" style="display:none;"></div>
                        </div>

                        <!-- Описание + мультиязычный блок -->
                        <div>
                            <label class="field-label">
                                <span>Описание лимита</span>
                                <span class="badge-mini">Основной текст — RU</span>
                            </label>
                            <textarea name="description"
                                      id="desc_ru"
                                      class="textarea"
                                      rows="3"
                                      placeholder="Что даёт лимит (сколько поисков, особенности и т.д.) — на русском"><?= h($description) ?></textarea>
                            <div class="inline-hint">
                                Русская версия будет сохранена в БД. Ниже — черновики EN / ES, генерируются автоматически (можно править).
                            </div>

                            <div class="multilang-block">
                                <div class="multilang-header">
                                    <span>Авто-перевод описания</span>
                                    <div class="multilang-status" id="desc-translate-status">
                                        <i class="fa-regular fa-circle-check"></i>
                                        <i class="fa-solid fa-spinner fa-spin" style="display:none;"></i>
                                        <span>Авто-перевод включен</span>
                                    </div>
                                </div>
                                <div class="multilang-grid">
                                    <div>
                                        <div class="inline-hint">Русский · ru (основной)</div>
                                        <textarea class="textarea" rows="4" readonly
                                                  style="opacity:0.7;cursor:not-allowed;"
                                                  id="desc_ru_shadow"></textarea>
                                    </div>
                                    <div>
                                        <div class="inline-hint">English · en</div>
                                        <textarea class="textarea"
                                                  rows="4"
                                                  id="desc_en"
                                                  name="description_en"
                                                  placeholder="Английская версия появится автоматически..."><?= h($descriptionEn) ?></textarea>
                                    </div>
                                    <div>
                                        <div class="inline-hint">Español · es</div>
                                        <textarea class="textarea"
                                                  rows="4"
                                                  id="desc_es"
                                                  name="description_es"
                                                  placeholder="Испанская версия появится автоматически..."><?= h($descriptionEs) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Цена -->
                        <div>
                            <label class="field-label">
                                <span>Цена (₽)</span>
                            </label>
                            <input type="number"
                                   name="price"
                                   class="input"
                                   step="0.01"
                                   min="0"
                                   required
                                   value="<?= h($priceRaw) ?>"
                                   placeholder="Например: 99 или 249.90">
                        </div>

                        <!-- Количество успешных поисков за 1 день -->
                        <div>
                            <label class="field-label">
                                <span>Доступно успешных поисков за 1 день</span>
                            </label>
                            <input type="number"
                                   name="search_limit"
                                   class="input"
                                   min="1"
                                   required
                                   value="<?= (int)$searchLimit ?>"
                                   placeholder="Например: 10, 25, 50">
                            <div class="inline-hint">
                                Именно столько успешных поисков пользователь сможет выполнить в течение одного дня.
                            </div>
                        </div>

                        <!-- Превью периода на 3 языках (всегда 1 день) -->
                        <div class="duration-preview">
                            <div class="duration-preview-title">Период действия лимита · фиксирован 1 день</div>
                            <div class="duration-preview-row">
                                <div class="duration-chip">
                                    <span class="lang">RU</span>
                                    <span>1 день</span>
                                </div>
                                <div class="duration-chip">
                                    <span class="lang">EN</span>
                                    <span>1 day</span>
                                </div>
                                <div class="duration-chip">
                                    <span class="lang">ES</span>
                                    <span>1 día</span>
                                </div>
                            </div>
                            <div class="inline-hint" style="margin-top:6px;">
                                Период менять нельзя — все лимиты рассчитаны ровно на 1 календарный день.
                            </div>
                        </div>

                        <!-- Кнопка -->
                        <div style="margin-top:12px;">
                            <button type="submit"
                                    class="btn btn-primary"
                                    style="width:100%;justify-content:center;">
                                <i class="fas fa-plus-circle"></i>
                                Создать лимит
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // ---------- Авто-скрытие уведомлений (успех / ошибки) ----------
    document.addEventListener('DOMContentLoaded', function () {
        const flashes = document.querySelectorAll('.js-flash-msg');
        flashes.forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s ease';
                el.style.opacity = '0';
                setTimeout(function () {
                    if (el && el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 450);
            }, 5000); // 5 секунд
        });
    });

    // ---------- AJAX-проверка названия лимита ----------
    (function () {
        const input = document.getElementById('title-input');
        const statusEl = document.getElementById('title-status');
        const codePreviewEl = document.getElementById('code-preview');

        if (!input || !statusEl) return;

        const icon = statusEl.querySelector('i') || document.createElement('i');
        if (!statusEl.contains(icon)) {
            statusEl.prepend(icon);
        }
        if (!statusEl.querySelector('span')) {
            const span = document.createElement('span');
            span.textContent = 'Проверяем название по мере ввода…';
            statusEl.appendChild(span);
        }

        let timer = null;
        let lastSent = '';

        function setStatus(type, text, iconClass) {
            statusEl.classList.remove('ok', 'error', 'info');
            statusEl.classList.add(type);
            statusEl.style.display = 'flex';
            statusEl.querySelector('span').textContent = text;
            icon.className = iconClass;
        }

        function clearCodePreview() {
            codePreviewEl.style.display = 'none';
            codePreviewEl.textContent = '';
        }

        function updateCodePreview(code) {
            if (!code) {
                clearCodePreview();
                return;
            }
            codePreviewEl.style.display = 'block';
            codePreviewEl.textContent = 'Код лимита будет: ' + code;
        }

        input.addEventListener('input', function () {
            const val = input.value.trim();

            clearTimeout(timer);

            if (!val) {
                setStatus('info', 'Проверяем название по мере ввода…', 'fa-regular fa-circle-question');
                clearCodePreview();
                return;
            }

            // мгновенная проверка на кириллицу
            if (/[А-Яа-яЁё]/.test(val)) {
                setStatus('error', 'Название только на английском (латиница, без кириллицы).', 'fa-solid fa-circle-xmark');
                clearCodePreview();
                return;
            }

            // проверка на допустимые символы
            if (!/^[A-Za-z0-9 _\-\.\(\)]+$/.test(val)) {
                setStatus('error', 'Разрешены только латиница, цифры, пробелы и символы - _ . ( ).', 'fa-solid fa-circle-xmark');
                clearCodePreview();
                return;
            }

            if (val === lastSent) {
                return;
            }

            setStatus('info', 'Проверяем в базе…', 'fa-solid fa-spinner fa-spin');

            timer = setTimeout(function () {
                lastSent = val;

                const body = new URLSearchParams();
                body.append('ajax', 'check_title');
                body.append('title', val);

                fetch('new_limit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                })
                    .then(r => r.json())
                    .then(data => {
                        const errs = Array.isArray(data.errors) ? data.errors : [];
                        if (!data.ok || errs.length) {
                            setStatus('error', errs.join(' ') || 'Ошибка проверки названия.', 'fa-solid fa-circle-xmark');
                        } else if (data.is_unique) {
                            setStatus('ok', 'Название свободно, можно использовать.', 'fa-regular fa-circle-check');
                        } else {
                            setStatus('error', 'Лимит с таким названием уже существует.', 'fa-solid fa-circle-xmark');
                        }

                        updateCodePreview(data.code || data.slug_base || '');
                    })
                    .catch(err => {
                        console.error(err);
                        setStatus('error', 'Ошибка соединения при проверке названия.', 'fa-solid fa-triangle-exclamation');
                        clearCodePreview();
                    });

            }, 500);
        });
    })();

    // ---------- Авто-перевод описания лимита RU → EN / ES ----------
    (function () {
        const ru = document.getElementById('desc_ru');
        const ruShadow = document.getElementById('desc_ru_shadow');
        const en = document.getElementById('desc_en');
        const es = document.getElementById('desc_es');
        const status = document.getElementById('desc-translate-status');
        if (!ru || !en || !es || !status) return;

        const icons = status.querySelectorAll('i');
        const iconOk = icons[0];
        const iconSpin = icons[1];
        const statusText = status.querySelector('span');

        let timer = null;
        let lastSent = '';

        function setIdle() {
            iconSpin.style.display = 'none';
            iconOk.style.display = 'inline-block';
            statusText.textContent = 'Авто-перевод включен';
        }

        function setTranslating() {
            iconOk.style.display = 'none';
            iconSpin.style.display = 'inline-block';
            statusText.textContent = 'Переводим описание…';
        }

        function setError() {
            iconOk.style.display = 'none';
            iconSpin.style.display = 'none';
            statusText.textContent = 'Ошибка перевода, можно дописать EN / ES вручную';
        }

        setIdle();

        ru.addEventListener('input', function () {
            const text = ru.value;
            ruShadow.value = text;

            const trimmed = text.trim();

            clearTimeout(timer);

            if (!trimmed) {
                en.value = '';
                es.value = '';
                setIdle();
                return;
            }

            if (trimmed === lastSent) return;

            setTranslating();

            timer = setTimeout(function () {
                lastSent = trimmed;

                const body = new URLSearchParams();
                body.append('ajax', 'translate_description');
                body.append('text_ru', trimmed);

                fetch('new_limit.php', {
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
                                // не перезатираем, если админ уже вручную правил
                                if (!en.value.trim() || en.value.trim() === lastSent) {
                                    en.value = data.en;
                                }
                                any = true;
                            }
                            if (typeof data.es === 'string' && data.es.trim() !== '') {
                                if (!es.value.trim() || es.value.trim() === lastSent) {
                                    es.value = data.es;
                                }
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

            }, 700);
        });

        // инициализируем тень, если описание уже было
        ruShadow.value = ru.value;
    })();
</script>
</body>
</html>
