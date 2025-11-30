<?php
// all_tariffs.php — список и редактирование тарифов (search_plans)

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

/* ---------- перевод (тот же стек, что в new_tariffs.php) ---------- */

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

function translateText(string $text, string $targetLang, string $sourceLang = 'ru'): string {
    $text = trim($text);
    if ($text === '') return '';

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

    return translateTextMyMemory($text, $targetLang, $sourceLang);
}

/* ---------- plural helpers ---------- */

function plural_ru_days(int $n): string {
    $n = abs($n);
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return 'день';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'дня';
    return 'дней';
}
function plural_ru_months(int $n): string {
    $n = abs($n);
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return 'месяц';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'месяца';
    return 'месяцев';
}
function plural_en_days(int $n): string { return abs($n) === 1 ? 'day' : 'days'; }
function plural_en_months(int $n): string { return abs($n) === 1 ? 'month' : 'months'; }
function plural_es_days(int $n): string { return abs($n) === 1 ? 'día' : 'días'; }
function plural_es_months(int $n): string { return abs($n) === 1 ? 'mes' : 'meses'; }

/**
 * duration_label_* на 3 языках
 */
function makeDurationLabels(int $value, string $unit): array {
    if ($value <= 0) {
        return ['ru' => null, 'en' => null, 'es' => null];
    }
    $unit = ($unit === 'months') ? 'months' : 'days';

    if ($unit === 'days') {
        return [
            'ru' => $value . ' ' . plural_ru_days($value),
            'en' => $value . ' ' . plural_en_days($value),
            'es' => $value . ' ' . plural_es_days($value),
        ];
    }

    return [
        'ru' => $value . ' ' . plural_ru_months($value),
        'en' => $value . ' ' . plural_en_months($value),
        'es' => $value . ' ' . plural_es_months($value),
    ];
}

/* ---------- строгая проверка ADMIN как в users.php ---------- */

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

$pdo = db();

// Проверяем, что админ активен в БД
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
    error_log('all_tariffs.php admin fetch error: ' . $e->getMessage());
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

/* ---------- статус БД ---------- */

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

/* ---------- работа с тарифами ---------- */

function normalizePlanRow(array $row): array {
    $desc      = $row['description']      ?? null;
    $desc_ru   = $row['description_ru']   ?? null;
    $desc_en   = $row['description_en']   ?? null;
    $desc_es   = $row['description_es']   ?? null;

    if ($desc_ru === null && $desc !== null) $desc_ru = $desc;
    if ($desc_en === null) $desc_en = '';
    if ($desc_es === null) $desc_es = '';

    $value = (int)($row['duration_value'] ?? 0);
    $unit  = $row['duration_unit'] ?? 'days';

    $lab_ru = $row['duration_label_ru'] ?? null;
    $lab_en = $row['duration_label_en'] ?? null;
    $lab_es = $row['duration_label_es'] ?? null;

    if (($lab_ru === null || $lab_en === null || $lab_es === null) && $value > 0) {
        $labels = makeDurationLabels($value, $unit);
        if ($lab_ru === null) $lab_ru = $labels['ru'];
        if ($lab_en === null) $lab_en = $labels['en'];
        if ($lab_es === null) $lab_es = $labels['es'];
    }

    $price = (float)($row['price'] ?? 0);

    return [
        'code'              => $row['code'],
        'title'             => $row['title'],
        'daily_limit'       => (int)$row['daily_limit'],
        'description'       => $desc,
        'description_ru'    => $desc_ru,
        'description_en'    => $desc_en,
        'description_es'    => $desc_es,
        'price'             => $price,
        'duration_value'    => $value,
        'duration_unit'     => $unit,
        'duration_label_ru' => $lab_ru,
        'duration_label_en' => $lab_en,
        'duration_label_es' => $lab_es,
        'sort_order'        => (int)$row['sort_order'],
        'is_active'         => (int)$row['is_active'],
    ];
}

function fetchAllPlans(PDO $pdo): array {
    $plans = [];
    try {
        $st = $pdo->query("
            SELECT code, title, daily_limit, description,
                   description_ru, description_en, description_es,
                   price, duration_value, duration_unit,
                   duration_label_ru, duration_label_en, duration_label_es,
                   sort_order, is_active
            FROM search_plans
            ORDER BY sort_order ASC, price ASC, daily_limit ASC, code ASC
        ");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $plans[] = normalizePlanRow($row);
        }
    } catch (Throwable $e) {
        error_log('all_tariffs.php fetchAllPlans error: ' . $e->getMessage());
    }
    return $plans;
}

function fetchPlanByCode(PDO $pdo, string $code): ?array {
    try {
        $st = $pdo->prepare("
            SELECT code, title, daily_limit, description,
                   description_ru, description_en, description_es,
                   price, duration_value, duration_unit,
                   duration_label_ru, duration_label_en, duration_label_es,
                   sort_order, is_active
            FROM search_plans
            WHERE code = :code
            LIMIT 1
        ");
        $st->execute([':code' => $code]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            return normalizePlanRow($row);
        }
    } catch (Throwable $e) {
        error_log('all_tariffs.php fetchPlanByCode error: ' . $e->getMessage());
    }
    return null;
}

/* ---------- AJAX ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $dbStatusOk) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_POST['ajax'];

    // перевод описания RU → EN/ES
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
            error_log('all_tariffs.php translate_description error: ' . $e->getMessage());
            echo json_encode([
                'ok'    => false,
                'error' => 'translate_failed',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // обновление тарифа
    if ($ajax === 'update_plan') {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            echo json_encode([
                'ok'    => false,
                'error' => 'empty_code',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $title          = trim($_POST['title'] ?? '');
        $dailyLimit     = (int)($_POST['daily_limit'] ?? 0);
        $priceRaw       = trim($_POST['price'] ?? '');
        $durationValue  = (int)($_POST['duration_value'] ?? 0);
        $durationUnit   = $_POST['duration_unit'] ?? 'days';
        $sortOrder      = (int)($_POST['sort_order'] ?? 0);
        $isActive       = isset($_POST['is_active']) && (int)$_POST['is_active'] === 1 ? 1 : 0;

        $descriptionRu  = trim($_POST['description_ru'] ?? '');
        $descriptionEn  = trim($_POST['description_en'] ?? '');
        $descriptionEs  = trim($_POST['description_es'] ?? '');

        $errors = [];

        $existing = fetchPlanByCode($pdo, $code);
        if (!$existing) {
            echo json_encode([
                'ok'    => false,
                'error' => 'plan_not_found',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($title === '') {
            $errors[] = 'Введите название тарифа.';
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

        // проверка уникальности title, кроме текущего
        if ($title !== '') {
            try {
                $st = $pdo->prepare("SELECT code FROM search_plans WHERE title = :t AND code <> :c LIMIT 1");
                $st->execute([':t' => $title, ':c' => $code]);
                if ($st->fetch()) {
                    $errors[] = 'Тариф с таким названием уже существует.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Ошибка проверки уникальности названия: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            echo json_encode([
                'ok'     => false,
                'error'  => 'validation',
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $price = (float)str_replace(',', '.', $priceRaw);
        $labels = makeDurationLabels($durationValue, $durationUnit);

        $descriptionBase = $descriptionRu !== '' ? $descriptionRu : $existing['description'];

        try {
            $st = $pdo->prepare("
                UPDATE search_plans
                SET title             = :title,
                    daily_limit       = :daily_limit,
                    description       = :description,
                    description_ru    = :description_ru,
                    description_en    = :description_en,
                    description_es    = :description_es,
                    price             = :price,
                    duration_value    = :duration_value,
                    duration_unit     = :duration_unit,
                    duration_label_ru = :duration_label_ru,
                    duration_label_en = :duration_label_en,
                    duration_label_es = :duration_label_es,
                    sort_order        = :sort_order,
                    is_active         = :is_active
                WHERE code = :code
                LIMIT 1
            ");
            $st->execute([
                ':title'             => $title,
                ':daily_limit'       => $dailyLimit,
                ':description'       => $descriptionBase,
                ':description_ru'    => $descriptionRu !== '' ? $descriptionRu : null,
                ':description_en'    => $descriptionEn !== '' ? $descriptionEn : null,
                ':description_es'    => $descriptionEs !== '' ? $descriptionEs : null,
                ':price'             => $price,
                ':duration_value'    => $durationValue,
                ':duration_unit'     => $durationUnit,
                ':duration_label_ru' => $labels['ru'],
                ':duration_label_en' => $labels['en'],
                ':duration_label_es' => $labels['es'],
                ':sort_order'        => $sortOrder,
                ':is_active'         => $isActive,
                ':code'              => $code,
            ]);

            $updated = fetchPlanByCode($pdo, $code);

            echo json_encode([
                'ok'   => true,
                'plan' => $updated,
                'msg'  => 'Тариф сохранён',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('all_tariffs.php update_plan error: ' . $e->getMessage());
            echo json_encode([
                'ok'    => false,
                'error' => 'db_error',
                'msg'   => 'Ошибка при сохранении тарифа: ' . $e->getMessage(),
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

/* ---------- обычный вывод ---------- */

$plans = $dbStatusOk ? fetchAllPlans($pdo) : [];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тарифы поиска (search_plans)</title>
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
            background: radial-gradient(circle at top, #1f2937 0, #020617 50%, #000 100%);
            color: var(--text-primary);
            line-height: 1.5;
            padding: 20px;
            min-height: 100vh;
            animation: fadeIn .4s ease-out;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
        }

        .header {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            margin-bottom:24px;
            animation: slideUp .4s ease-out;
        }

        .page-title h1 {
            font-size:24px;
            font-weight:700;
            margin-bottom:6px;
            background:linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
        }

        .page-title p {
            color:var(--text-secondary);
            font-size:13px;
        }

        .admin-pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:6px 10px;
            border-radius:999px;
            background:rgba(15,23,42,.85);
            border:1px solid rgba(148,163,184,.4);
            font-size:12px;
            color:var(--text-secondary);
            box-shadow:0 10px 30px rgba(0,0,0,.45);
            backdrop-filter:blur(12px);
        }
        .admin-pill i{font-size:12px;}

        .btn {
            padding:8px 14px;
            border-radius:var(--radius-sm);
            border:1px solid transparent;
            font-weight:500;
            font-size:13px;
            cursor:pointer;
            transition:var(--transition);
            display:inline-flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
            position:relative;
            overflow:hidden;
        }
        .btn::before{
            content:'';
            position:absolute;
            top:50%;left:50%;
            width:0;height:0;
            background:rgba(255,255,255,.15);
            border-radius:50%;
            transform:translate(-50%,-50%);
            transition:width .5s,height .5s;
        }
        .btn:active::before{
            width:220px;height:220px;
        }
        .btn-primary{
            background:var(--accent);
            color:#fff;
            box-shadow:var(--shadow);
        }
        .btn-primary:hover{
            background:var(--accent-hover);
            transform:translateY(-2px) scale(1.02);
            box-shadow:0 14px 35px rgba(88,28,135,.8);
        }
        .btn-secondary{
            background:var(--bg-card);
            color:var(--text-primary);
            border-color:var(--border);
        }
        .btn-secondary:hover{
            background:var(--bg-hover);
            transform:translateY(-1px);
        }

        /* статус БД */
        .status-chip{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid var(--border);
            background:rgba(15,23,42,.9);
            font-size:11px;
        }
        .status-dot{
            width:9px;
            height:9px;
            border-radius:999px;
            position:relative;
        }
        .status-dot.ok{
            background:var(--success);
        }
        .status-dot.ok::after{
            content:'';
            position:absolute;
            inset:-4px;
            border-radius:inherit;
            border:2px solid rgba(16,185,129,.4);
            animation:pulse 1.4s infinite;
        }
        .status-dot.bad{
            background:var(--danger);
        }
        .status-text-ok{color:var(--success);}
        .status-text-bad{color:var(--danger);}

        /* карточка-обертка для тарифов */
        .tariffs-card{
            background:var(--bg-card);
            border-radius:var(--radius-lg);
            border:1px solid var(--border);
            padding:18px 18px 18px;
            box-shadow:0 14px 40px rgba(0,0,0,.55);
            animation: slideUp .4s ease-out;
        }

        .tariffs-header-row{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:12px;
            gap:10px;
        }

        .tariffs-header-row-title{
            font-size:15px;
            font-weight:600;
            margin-bottom:2px;
        }
        .tariffs-header-row-sub{
            font-size:12px;
            color:var(--text-secondary);
        }

        .tariffs-toolbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            margin-bottom:14px;
        }
        .toolbar-left{
            font-size:12px;
            color:var(--text-secondary);
        }
        .toolbar-right{
            display:flex;
            gap:8px;
            align-items:center;
        }
        .pill-filter{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:5px 10px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--bg-secondary);
            font-size:11px;
            color:var(--text-secondary);
        }
        .pill-filter input{
            accent-color:#22c55e;
        }

        .tariffs-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
            gap:14px;
        }

        .tariff-card{
            position:relative;
            background:var(--bg-secondary);
            border-radius:var(--radius-md);
            border:1px solid var(--border);
            padding:14px 14px 12px;
            overflow:hidden;
            cursor:pointer;
            transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
            animation:slideUp .5s ease-out;
        }
        .tariff-card::before{
            content:'';
            position:absolute;
            top:0;left:-40%;right:-40%;
            height:3px;
            background:linear-gradient(90deg,var(--accent),#a855f7);
            opacity:.8;
            transform:translateX(-20%);
            animation: shimmer 2.6s linear infinite;
        }
        .tariff-card:hover{
            transform:translateY(-6px) scale(1.01);
            box-shadow:0 18px 40px rgba(0,0,0,.7);
            border-color:rgba(139,92,246,.8);
        }

        .tariff-header{
            display:flex;
            justify-content:space-between;
            gap:8px;
            margin-bottom:8px;
        }
        .tariff-name{
            font-size:15px;
            font-weight:600;
        }
        .tariff-code-badge{
            font-size:11px;
            color:var(--text-secondary);
            padding:2px 8px;
            border-radius:999px;
            border:1px solid var(--border);
            background:rgba(15,23,42,.9);
            margin-top:4px;
            display:inline-block;
        }

        .tariff-tag{
            font-size:10px;
            padding:3px 8px;
            border-radius:999px;
            text-transform:uppercase;
            letter-spacing:.06em;
            border:1px solid var(--border);
            background:rgba(15,23,42,.9);
            color:var(--text-secondary);
        }
        .tariff-tag.active{
            background:rgba(22,163,74,.95);
            border-color:rgba(74,222,128,.9);
            color:#ecfdf5;
        }
        .tariff-tag.inactive{
            background:rgba(127,29,29,.95);
            border-color:rgba(248,113,113,.9);
            color:#fee2e2;
        }

        .tariff-main-row{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:8px;
            margin-bottom:6px;
        }
        .tariff-price{
            font-size:14px;
            font-weight:600;
        }
        .tariff-price span{
            font-size:11px;
            color:var(--text-secondary);
            margin-left:4px;
        }
        .tariff-limit{
            font-size:12px;
            color:var(--text-secondary);
        }

        .tariff-desc{
            font-size:12px;
            color:var(--text-secondary);
            margin-top:4px;
            max-height:3.4em;
            overflow:hidden;
            position:relative;
        }
        .tariff-desc::after{
            content:'';
            position:absolute;
            right:0;bottom:0;
            width:40%;
            height:100%;
            background:linear-gradient(90deg, transparent, var(--bg-secondary));
        }

        .tariff-footer{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-top:7px;
            font-size:11px;
            color:var(--text-muted);
        }
        .tariff-sort{
            padding:3px 6px;
            border-radius:999px;
            border:1px solid var(--border);
            background:rgba(15,23,42,.9);
            font-size:10px;
        }
        .tariff-footer-hint{
            display:flex;
            align-items:center;
            gap:4px;
        }
        .tariff-footer-hint i{
            font-size:10px;
        }

        .empty-state{
            text-align:center;
            padding:40px 20px;
            color:var(--text-muted);
            font-size:14px;
            animation:fadeIn .4s ease-out;
        }

        .flash-msg{
            margin-top:10px;
            padding:9px 11px;
            border-radius:12px;
            font-size:12px;
            display:none;
        }
        .flash-msg.success{
            display:block;
            background:rgba(16,185,129,0.12);
            border:1px solid rgba(16,185,129,0.8);
            color:#bbf7d0;
        }
        .flash-msg.error{
            display:block;
            background:rgba(239,68,68,0.10);
            border:1px solid rgba(248,113,113,0.85);
            color:#fecaca;
        }

        /* модалка как в users.php */
        .modal-overlay{
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.8);
            display:none;
            align-items:center;
            justify-content:center;
            z-index:999;
            padding:16px;
            opacity:0;
            pointer-events:none;
            transition:opacity .25s ease-out;
        }
        .modal-overlay.active{
            display:flex;
            opacity:1;
            pointer-events:auto;
        }
        .modal{
            background:var(--bg-card);
            border-radius:var(--radius-lg);
            border:1px solid var(--border);
            max-width:720px;
            width:100%;
            max-height:90vh;
            display:flex;
            flex-direction:column;
            overflow:hidden;
            box-shadow:0 20px 50px rgba(0,0,0,.7);
            animation:modalPop .35s ease-out;
        }
        .modal-header{
            padding:14px 18px;
            border-bottom:1px solid var(--border);
            background:var(--bg-secondary);
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .modal-title{
            font-size:16px;
            font-weight:600;
        }
        .modal-subtitle{
            font-size:11px;
            color:var(--text-secondary);
            margin-top:2px;
        }
        .modal-header-left{
            display:flex;
            flex-direction:column;
        }
        .modal-close{
            border:none;
            background:rgba(15,23,42,.9);
            width:30px;
            height:30px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:var(--text-secondary);
            cursor:pointer;
            transition:var(--transition);
        }
        .modal-close:hover{
            background:var(--bg-hover);
            color:#fff;
            transform:rotate(6deg) scale(1.05);
        }
        .modal-body{
            flex:1;
            overflow-y:auto;
            padding:16px 18px 14px;
        }
        .modal-footer{
            padding:10px 18px 12px;
            border-top:1px solid var(--border);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            font-size:11px;
            color:var(--text-muted);
        }

        /* форма внутри модалки */
        .field{
            margin-bottom:10px;
        }
        .field-label{
            font-size:12px;
            font-weight:500;
            color:#e5e7eb;
            margin-bottom:3px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .field-label .mini{
            font-size:10px;
            color:var(--text-secondary);
        }

        .input,
        .textarea,
        .select{
            width:100%;
            background:var(--bg-secondary);
            border-radius:var(--radius-sm);
            border:1px solid var(--border);
            padding:8px 9px;
            color:var(--text-primary);
            font-size:12px;
            outline:none;
            transition:var(--transition);
        }
        .input:focus,
        .textarea:focus,
        .select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 1px rgba(139,92,246,.5), 0 0 25px rgba(139,92,246,.35);
            transform:translateY(-1px);
        }
        .textarea{
            resize:vertical;
            min-height:60px;
        }

        .inline-hint{
            font-size:10px;
            color:var(--text-muted);
            margin-top:3px;
        }

        .flex-row{
            display:flex;
            gap:8px;
        }
        .flex-row > div{
            flex:1;
        }

        .duration-preview{
            margin-top:6px;
            padding:7px 9px;
            border-radius:10px;
            background:linear-gradient(135deg, rgba(15,23,42,0.9), rgba(37,99,235,0.2));
            border:1px solid rgba(59,130,246,0.45);
            font-size:11px;
        }
        .duration-preview-row{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-top:4px;
        }
        .duration-chip{
            padding:3px 8px;
            border-radius:999px;
            background:rgba(15,23,42,.9);
            border:1px solid var(--border);
            display:inline-flex;
            align-items:center;
            gap:5px;
            font-size:10px;
        }
        .duration-chip span.lang{
            font-weight:500;
        }

        .multilang-box{
            margin-top:6px;
            padding:8px 9px;
            border-radius:10px;
            border:1px dashed rgba(148,163,184,0.6);
            background:rgba(15,23,42,.9);
        }
        .multilang-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:6px;
            font-size:11px;
        }
        .multilang-status{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:10px;
            color:var(--text-secondary);
        }

        .btn-link{
            border:none;
            background:none;
            padding:0;
            color:#93c5fd;
            font-size:11px;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .btn-link:hover{
            text-decoration:underline;
        }

        .switch-row{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            margin-top:6px;
        }
        .switch{
            position:relative;
            width:34px;
            height:18px;
        }
        .switch input{
            opacity:0;
            width:0;
            height:0;
        }
        .slider{
            position:absolute;
            cursor:pointer;
            inset:0;
            background:#4b5563;
            transition:.2s;
            border-radius:999px;
        }
        .slider:before{
            position:absolute;
            content:"";
            height:14px;
            width:14px;
            left:2px;
            top:2px;
            background:white;
            border-radius:50%;
            transition:.2s;
        }
        .switch input:checked + .slider{
            background:#22c55e;
        }
        .switch input:checked + .slider:before{
            transform:translateX(16px);
        }

        .btn-save{
            padding:8px 14px;
            border-radius:var(--radius-sm);
            border:none;
            background:linear-gradient(135deg,#22c55e,#16a34a);
            color:#ecfdf5;
            font-size:13px;
            font-weight:600;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:7px;
            box-shadow:0 12px 30px rgba(22,163,74,0.5);
            transition:var(--transition);
        }
        .btn-save[disabled]{
            opacity:0.7;
            cursor:default;
            box-shadow:none;
        }
        .btn-save:hover:not([disabled]){
            transform:translateY(-1px);
            box-shadow:0 14px 36px rgba(22,163,74,0.75);
        }

        .modal-errors{
            margin-top:6px;
            font-size:11px;
            color:#fecaca;
        }

        @keyframes slideUp{
            from{opacity:0;transform:translateY(30px);}
            to{opacity:1;transform:translateY(0);}
        }
        @keyframes fadeIn{
            from{opacity:0;}
            to{opacity:1;}
        }
        @keyframes modalPop{
            from{opacity:0;transform:translateY(26px) scale(.96);}
            to{opacity:1;transform:translateY(0) scale(1);}
        }
        @keyframes shimmer{
            0%{transform:translateX(-40%);}
            100%{transform:translateX(40%);}
        }
        @keyframes pulse{
            0%{transform:scale(0.9);opacity:0.8;}
            50%{transform:scale(1.1);opacity:0.2;}
            100%{transform:scale(1.2);opacity:0;}
        }

        @media (max-width: 768px){
            body{padding:14px;}
            .header{flex-direction:column;gap:12px;}
            .tariffs-toolbar{flex-direction:column;align-items:flex-start;}
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="page-title">
            <h1>Все Тарифы</h1>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
            <div class="admin-pill">
                <i class="fa-solid fa-user-shield"></i>
                <span><?= h($adminDisplay) ?></span>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i>Назад
                </a>
            </div>
        </div>
    </div>

    <div class="tariffs-card">
        <div class="tariffs-header-row">
            <div>
                <div class="tariffs-header-row-title">Все тарифы</div>
            </div>
            <div class="status-chip">
                <span class="status-dot <?= $dbStatusOk ? 'ok' : 'bad' ?>"></span>
                <span class="<?= $dbStatusOk ? 'status-text-ok' : 'status-text-bad' ?>">
                    <?= h($dbStatusMessage) ?>
                </span>
            </div>
        </div>

        <?php if ($dbStatusOk): ?>
            <div class="tariffs-toolbar">
                <div class="toolbar-left">
                    <?php if (count($plans)): ?>
                        Найдено тарифов: <strong><?= count($plans) ?></strong>
                    <?php else: ?>
                        Тарифы не найдены.
                    <?php endif; ?>
                </div>
                <div class="toolbar-right">
                    <label class="pill-filter">
                        <input type="checkbox" id="filter-active" checked>
                        <span>Показывать только активные</span>
                    </label>
                </div>
            </div>

            <?php if (!count($plans)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-credit-card" style="font-size:34px;margin-bottom:8px;display:block;opacity:.6;"></i>
                    В таблице <code>search_plans</code> пока нет тарифов.
                </div>
            <?php else: ?>
                <div id="tariffs-grid" class="tariffs-grid">
                    <?php foreach ($plans as $p): ?>
                        <div class="tariff-card"
                             data-code="<?= h($p['code']) ?>"
                             data-title="<?= h($p['title']) ?>"
                             data-daily_limit="<?= (int)$p['daily_limit'] ?>"
                             data-price="<?= h($p['price']) ?>"
                             data-description_ru="<?= h($p['description_ru']) ?>"
                             data-description_en="<?= h($p['description_en']) ?>"
                             data-description_es="<?= h($p['description_es']) ?>"
                             data-duration_value="<?= (int)$p['duration_value'] ?>"
                             data-duration_unit="<?= h($p['duration_unit']) ?>"
                             data-duration_label_ru="<?= h($p['duration_label_ru']) ?>"
                             data-duration_label_en="<?= h($p['duration_label_en']) ?>"
                             data-duration_label_es="<?= h($p['duration_label_es']) ?>"
                             data-sort_order="<?= (int)$p['sort_order'] ?>"
                             data-is_active="<?= (int)$p['is_active'] ?>"
                        >
                            <div class="tariff-header">
                                <div>
                                    <div class="tariff-name"><?= h($p['title']) ?></div>
                                    <div class="tariff-code-badge"><?= h($p['code']) ?></div>
                                </div>
                                <div class="tariff-tag <?= $p['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $p['is_active'] ? 'Активен' : 'Выключен' ?>
                                </div>
                            </div>

                            <div class="tariff-main-row">
                                <div class="tariff-price">
                                    <?= h(number_format($p['price'], 2, '.', ' ')) ?> ₽
                                    <span>/ <?= h($p['duration_label_en'] ?: 'period') ?></span>
                                </div>
                                <div class="tariff-limit">
                                    До <?= (int)$p['daily_limit'] ?> поисков/сутки
                                </div>
                            </div>

                            <div class="tariff-desc">
                                <?= h($p['description_ru'] ?: $p['description'] ?: 'Описание не задано') ?>
                            </div>

                            <div class="tariff-footer">
                                <div class="tariff-sort">
                                    sort: <?= (int)$p['sort_order'] ?>
                                </div>
                                <div class="tariff-footer-hint">
                                    <i class="fa-regular fa-pen-to-square"></i>
                                    <span>Нажмите, чтобы редактировать</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="flash" class="flash-msg"></div>
        <?php else: ?>
            <div class="empty-state">
                База данных недоступна. Проверьте .env / соединение.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно редактирования тарифа -->
<div class="modal-overlay" id="tariffModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-title" id="modal-title">Редактирование тарифа</div>
                <div class="modal-subtitle">
                    Код: <span id="modal-code"></span>
                </div>
            </div>
            <button class="modal-close" id="modal-close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="tariff-form">
                <input type="hidden" name="ajax" value="update_plan">
                <input type="hidden" name="code" id="plan-code-input">
                <input type="hidden" name="is_active" id="plan-is-active-hidden" value="1">

                <div class="field">
                    <label class="field-label">
                        <span>Название тарифа</span>
                        <span class="mini">title</span>
                    </label>
                    <input type="text"
                           class="input"
                           name="title"
                           id="plan-title-input"
                           maxlength="100"
                           required>
                    <div class="inline-hint">
                        Название, которое увидит пользователь. Не должно дублировать другие тарифы.
                    </div>
                </div>

                <div class="field flex-row">
                    <div>
                        <label class="field-label">
                            <span>Лимит в сутки</span>
                        </label>
                        <input type="number"
                               class="input"
                               name="daily_limit"
                               id="plan-daily-limit-input"
                               min="0"
                               required>
                    </div>
                    <div>
                        <label class="field-label">
                            <span>Цена (₽)</span>
                        </label>
                        <input type="number"
                               class="input"
                               name="price"
                               id="plan-price-input"
                               step="0.01"
                               min="0"
                               required>
                    </div>
                    <div>
                        <label class="field-label">
                            <span>Порядок сортировки</span>
                        </label>
                        <input type="number"
                               class="input"
                               name="sort_order"
                               id="plan-sort-order-input"
                               min="0">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">
                        <span>Период действия тарифа</span>
                    </label>
                    <div class="flex-row">
                        <div>
                            <input type="number"
                                   class="input"
                                   name="duration_value"
                                   id="plan-duration-value-input"
                                   min="1"
                                   required>
                        </div>
                        <div>
                            <select class="select"
                                    name="duration_unit"
                                    id="plan-duration-unit-input">
                                <option value="days">дней</option>
                                <option value="months">месяцев</option>
                            </select>
                        </div>
                    </div>
                    <div class="duration-preview">
                        <div style="font-size:10px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;">
                            Превью периода RU / EN / ES
                        </div>
                        <div class="duration-preview-row" id="duration-preview-row"></div>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">
                        <span>Описание тарифа</span>
                        <span class="mini">RU / EN / ES</span>
                    </label>
                    <textarea class="textarea"
                              name="description_ru"
                              id="plan-desc-ru"
                              rows="3"
                              placeholder="Описание по-русски"></textarea>
                    <div class="inline-hint">
                        Русская версия — основная. Переводы EN / ES можно подредактировать.
                    </div>

                    <div class="multilang-box">
                        <div class="multilang-head">
                            <span>Переводы описания</span>
                            <div class="multilang-status" id="modal-translate-status">
                                <i class="fa-regular fa-circle-check" id="modal-translate-icon-ok"></i>
                                <i class="fa-solid fa-spinner fa-spin" id="modal-translate-icon-spin" style="display:none;"></i>
                                <span id="modal-translate-text">Авто-перевод включен</span>
                            </div>
                        </div>
                        <div class="flex-row" style="margin-bottom:4px;">
                            <div>
                                <div class="inline-hint">English · en</div>
                                <textarea class="textarea"
                                          name="description_en"
                                          id="plan-desc-en"
                                          rows="3"
                                          placeholder="Английская версия"></textarea>
                            </div>
                            <div>
                                <div class="inline-hint">Español · es</div>
                                <textarea class="textarea"
                                          name="description_es"
                                          id="plan-desc-es"
                                          rows="3"
                                          placeholder="Испанская версия"></textarea>
                            </div>
                        </div>
                        <button type="button" class="btn-link" id="btn-force-translate">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            Перевести заново из RU
                        </button>
                    </div>
                </div>

                <div class="field">
                    <div class="switch-row">
                        <div>
                            <div class="field-label">
                                <span>Статус тарифа</span>
                            </div>
                            <div class="inline-hint">
                                Если выключить — тариф не будет показываться новым пользователям.
                            </div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="plan-is-active-input">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <div class="modal-errors" id="modal-errors" style="display:none;"></div>
            </form>
        </div>
        <div class="modal-footer">
            <div>Все изменения сохраняются напрямую в таблицу <code>search_plans</code>.</div>
            <button type="button" class="btn-save" id="btn-save-plan">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Сохранить</span>
            </button>
        </div>
    </div>
</div>

<script>
(function(){
    const grid = document.getElementById('tariffs-grid');
    const filterActive = document.getElementById('filter-active');
    const flash = document.getElementById('flash');

    const modalOverlay = document.getElementById('tariffModal');
    const modalClose = document.getElementById('modal-close');

    const form = document.getElementById('tariff-form');
    const btnSave = document.getElementById('btn-save-plan');
    const modalErrors = document.getElementById('modal-errors');

    const titleInput = document.getElementById('plan-title-input');
    const codeHidden = document.getElementById('plan-code-input');
    const modalCode = document.getElementById('modal-code');
    const modalTitle = document.getElementById('modal-title');

    const dailyLimitInput = document.getElementById('plan-daily-limit-input');
    const priceInput = document.getElementById('plan-price-input');
    const sortOrderInput = document.getElementById('plan-sort-order-input');
    const durationValueInput = document.getElementById('plan-duration-value-input');
    const durationUnitInput = document.getElementById('plan-duration-unit-input');

    const descRu = document.getElementById('plan-desc-ru');
    const descEn = document.getElementById('plan-desc-en');
    const descEs = document.getElementById('plan-desc-es');

    const durationPreviewRow = document.getElementById('duration-preview-row');

    const isActiveCheckbox = document.getElementById('plan-is-active-input');
    const isActiveHidden = document.getElementById('plan-is-active-hidden');

    const translateStatus = document.getElementById('modal-translate-status');
    const translateIconOk = document.getElementById('modal-translate-icon-ok');
    const translateIconSpin = document.getElementById('modal-translate-icon-spin');
    const translateText = document.getElementById('modal-translate-text');
    const btnForceTranslate = document.getElementById('btn-force-translate');

    let translateTimer = null;
    let lastTranslateSent = '';

    function showFlash(type, text){
        if (!flash) return;
        flash.classList.remove('success','error');
        flash.textContent = text || '';
        if (!text) {
            flash.style.display = 'none';
            return;
        }
        flash.classList.add(type === 'success' ? 'success' : 'error');
        flash.style.display = 'block';
        setTimeout(() => {
            flash.style.opacity = '1';
            setTimeout(() => {
                flash.style.opacity = '0';
                setTimeout(() => {
                    flash.style.display = 'none';
                    flash.style.opacity = '1';
                }, 200);
            }, 2600);
        }, 20);
    }

    function openModalFromCard(card){
        if (!card) return;
        const code = card.getAttribute('data-code') || '';
        const title = card.getAttribute('data-title') || '';
        const dailyLimit = card.getAttribute('data-daily_limit') || '0';
        const price = card.getAttribute('data-price') || '0';
        const sortOrder = card.getAttribute('data-sort_order') || '0';
        const durVal = card.getAttribute('data-duration_value') || '0';
        const durUnit = card.getAttribute('data-duration_unit') || 'days';
        const desc_ru = card.getAttribute('data-description_ru') || '';
        const desc_en = card.getAttribute('data-description_en') || '';
        const desc_es = card.getAttribute('data-description_es') || '';
        const isActive = card.getAttribute('data-is_active') === '1';

        codeHidden.value = code;
        modalCode.textContent = code;
        modalTitle.textContent = 'Редактирование тарифа';

        titleInput.value = title;
        dailyLimitInput.value = dailyLimit;
        priceInput.value = price;
        sortOrderInput.value = sortOrder;
        durationValueInput.value = durVal;
        durationUnitInput.value = (durUnit === 'months') ? 'months' : 'days';

        descRu.value = desc_ru;
        descEn.value = desc_en;
        descEs.value = desc_es;

        isActiveCheckbox.checked = isActive;
        isActiveHidden.value = isActive ? '1' : '0';

        modalErrors.style.display = 'none';
        modalErrors.innerHTML = '';

        updateDurationPreview();

        setTranslateIdle();

        modalOverlay.classList.add('active');
    }

    function closeModal(){
        modalOverlay.classList.remove('active');
    }

    function getDurationLabels(value, unit){
        value = parseInt(value, 10) || 0;
        unit = (unit === 'months') ? 'months' : 'days';
        if (value <= 0) {
            return {ru: null, en: null, es: null};
        }
        let ru, en, es;
        function ruDays(n){
            n = Math.abs(n);
            const m10 = n % 10;
            const m100 = n % 100;
            if (m10 === 1 && m100 !== 11) return 'день';
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'дня';
            return 'дней';
        }
        function ruMonths(n){
            n = Math.abs(n);
            const m10 = n % 10;
            const m100 = n % 100;
            if (m10 === 1 && m100 !== 11) return 'месяц';
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'месяца';
            return 'месяцев';
        }
        function enDays(n){ return Math.abs(n) === 1 ? 'day' : 'days'; }
        function enMonths(n){ return Math.abs(n) === 1 ? 'month' : 'months'; }
        function esDays(n){ return Math.abs(n) === 1 ? 'día' : 'días'; }
        function esMonths(n){ return Math.abs(n) === 1 ? 'mes' : 'meses'; }

        if (unit === 'days') {
            ru = value + ' ' + ruDays(value);
            en = value + ' ' + enDays(value);
            es = value + ' ' + esDays(value);
        } else {
            ru = value + ' ' + ruMonths(value);
            en = value + ' ' + enMonths(value);
            es = value + ' ' + esMonths(value);
        }
        return {ru, en, es};
    }

    function updateDurationPreview(){
        if (!durationPreviewRow) return;
        durationPreviewRow.innerHTML = '';
        const v = parseInt(durationValueInput.value, 10) || 0;
        const u = durationUnitInput.value;

        if (v <= 0) {
            const span = document.createElement('span');
            span.style.fontSize = '10px';
            span.style.color = 'var(--text-muted)';
            span.textContent = 'Укажите длительность — и здесь появится превью RU / EN / ES.';
            durationPreviewRow.appendChild(span);
            return;
        }

        const labels = getDurationLabels(v, u);

        function chip(lang, txt){
            const d = document.createElement('div');
            d.className = 'duration-chip';
            const sLang = document.createElement('span');
            sLang.className = 'lang';
            sLang.textContent = lang;
            const sTxt = document.createElement('span');
            sTxt.textContent = txt;
            d.appendChild(sLang);
            d.appendChild(sTxt);
            return d;
        }

        durationPreviewRow.appendChild(chip('RU', labels.ru));
        durationPreviewRow.appendChild(chip('EN', labels.en));
        durationPreviewRow.appendChild(chip('ES', labels.es));
    }

    function setTranslateIdle(){
        translateIconSpin.style.display = 'none';
        translateIconOk.style.display = 'inline-block';
        translateText.textContent = 'Авто-перевод включен';
    }
    function setTranslateWork(){
        translateIconOk.style.display = 'none';
        translateIconSpin.style.display = 'inline-block';
        translateText.textContent = 'Переводим описание…';
    }
    function setTranslateError(){
        translateIconOk.style.display = 'none';
        translateIconSpin.style.display = 'none';
        translateText.textContent = 'Ошибка перевода, EN / ES можно править вручную';
    }

    function autoTranslateDescription(forceNow){
        const text = descRu.value.trim();
        clearTimeout(translateTimer);

        if (!text) {
            setTranslateIdle();
            return;
        }
        if (!forceNow && text === lastTranslateSent) {
            return;
        }

        const doSend = () => {
            lastTranslateSent = text;
            setTranslateWork();

            const body = new URLSearchParams();
            body.append('ajax', 'translate_description');
            body.append('text_ru', text);

            fetch('all_tariffs.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            })
                .then(r => r.json())
                .then(data => {
                    if (data && data.ok) {
                        let any = false;
                        if (typeof data.en === 'string' && data.en.trim() !== '') {
                            descEn.value = data.en;
                            any = true;
                        }
                        if (typeof data.es === 'string' && data.es.trim() !== '') {
                            descEs.value = data.es;
                            any = true;
                        }
                        if (any) {
                            setTranslateIdle();
                        } else {
                            setTranslateError();
                        }
                    } else {
                        setTranslateError();
                    }
                })
                .catch(err => {
                    console.error(err);
                    setTranslateError();
                });
        };

        if (forceNow) {
            doSend();
        } else {
            translateTimer = setTimeout(doSend, 800);
        }
    }

    // клики по карточкам тарифов
    if (grid) {
        grid.addEventListener('click', function(e){
            const card = e.target.closest('.tariff-card');
            if (!card) return;
            openModalFromCard(card);
        });
    }

    // фильтр только активных
    if (filterActive && grid) {
        filterActive.addEventListener('change', function(){
            const onlyActive = filterActive.checked;
            const cards = grid.querySelectorAll('.tariff-card');
            cards.forEach(card => {
                const active = card.getAttribute('data-is_active') === '1';
                card.style.display = (!onlyActive || active) ? '' : 'none';
            });
        });
        filterActive.dispatchEvent(new Event('change'));
    }

    // закрытие модалки
    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e){
            if (e.target === modalOverlay) {
                closeModal();
            }
        });
    }
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // превью периода
    durationValueInput && durationValueInput.addEventListener('input', updateDurationPreview);
    durationUnitInput && durationUnitInput.addEventListener('change', updateDurationPreview);

    // статус is_active
    if (isActiveCheckbox && isActiveHidden) {
        isActiveCheckbox.addEventListener('change', function(){
            isActiveHidden.value = this.checked ? '1' : '0';
        });
    }

    // авто-перевод описания
    if (descRu) {
        descRu.addEventListener('input', function(){
            autoTranslateDescription(false);
        });
    }
    if (btnForceTranslate) {
        btnForceTranslate.addEventListener('click', function(){
            autoTranslateDescription(true);
        });
    }

    // сохранение тарифа
    if (btnSave && form) {
        btnSave.addEventListener('click', function(){
            if (!codeHidden.value) return;
            btnSave.disabled = true;
            btnSave.querySelector('span').textContent = 'Сохраняем...';

            const formData = new FormData(form);
            const body = new URLSearchParams(formData);

            fetch('all_tariffs.php', {
                method: 'POST',
                body: body.toString(),
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}
            })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) {
                        let msg = 'Ошибка сохранения.';
                        if (data && data.msg) msg = data.msg;
                        if (data && Array.isArray(data.errors) && data.errors.length) {
                            modalErrors.style.display = 'block';
                            modalErrors.innerHTML = data.errors.map(e => '<div>' + e + '</div>').join('');
                        } else {
                            modalErrors.style.display = 'block';
                            modalErrors.innerHTML = msg;
                        }
                        showFlash('error', msg);
                        return;
                    }

                    modalErrors.style.display = 'none';
                    modalErrors.innerHTML = '';

                    const plan = data.plan;
                    if (plan && grid) {
                        const card = grid.querySelector('.tariff-card[data-code="' + plan.code + '"]');
                        if (card) {
                            card.setAttribute('data-title', plan.title || '');
                            card.setAttribute('data-daily_limit', plan.daily_limit || 0);
                            card.setAttribute('data-price', plan.price || 0);
                            card.setAttribute('data-description_ru', plan.description_ru || '');
                            card.setAttribute('data-description_en', plan.description_en || '');
                            card.setAttribute('data-description_es', plan.description_es || '');
                            card.setAttribute('data-duration_value', plan.duration_value || 0);
                            card.setAttribute('data-duration_unit', plan.duration_unit || 'days');
                            card.setAttribute('data-duration_label_ru', plan.duration_label_ru || '');
                            card.setAttribute('data-duration_label_en', plan.duration_label_en || '');
                            card.setAttribute('data-duration_label_es', plan.duration_label_es || '');
                            card.setAttribute('data-sort_order', plan.sort_order || 0);
                            card.setAttribute('data-is_active', plan.is_active ? '1' : '0');

                            const nameEl = card.querySelector('.tariff-name');
                            if (nameEl) nameEl.textContent = plan.title || '';

                            const periodSub = card.querySelector('.tariff-main-row .tariff-price span');
                            const priceEl = card.querySelector('.tariff-price');
                            if (priceEl) {
                                const priceVal = (parseFloat(plan.price || 0)).toFixed(2);
                                if (priceEl.firstChild) {
                                    priceEl.firstChild.nodeValue = priceVal + ' ₽';
                                } else {
                                    priceEl.textContent = priceVal + ' ₽';
                                }
                                if (periodSub) {
                                    periodSub.textContent = ' / ' + (plan.duration_label_en || 'period');
                                }
                            }

                            const limitEl = card.querySelector('.tariff-limit');
                            if (limitEl) {
                                limitEl.textContent = 'До ' + (plan.daily_limit || 0) + ' поисков/сутки';
                            }

                            const descEl = card.querySelector('.tariff-desc');
                            if (descEl) {
                                descEl.textContent = plan.description_ru || plan.description || 'Описание не задано';
                            }

                            const sortEl = card.querySelector('.tariff-sort');
                            if (sortEl) {
                                sortEl.textContent = 'sort: ' + (plan.sort_order || 0);
                            }

                            const tag = card.querySelector('.tariff-tag');
                            if (tag) {
                                tag.classList.remove('active','inactive');
                                if (plan.is_active) {
                                    tag.classList.add('active');
                                    tag.textContent = 'Активен';
                                } else {
                                    tag.classList.add('inactive');
                                    tag.textContent = 'Выключен';
                                }
                            }
                        }
                    }

                    showFlash('success', data.msg || 'Тариф сохранён');
                    closeModal();
                    if (filterActive) {
                        filterActive.dispatchEvent(new Event('change'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    showFlash('error', 'Ошибка соединения при сохранении.');
                })
                .finally(() => {
                    btnSave.disabled = false;
                    btnSave.querySelector('span').textContent = 'Сохранить';
                });
        });
    }

    // стартовое состояние
    updateDurationPreview();
    setTranslateIdle();
})();
</script>
</body>
</html>
