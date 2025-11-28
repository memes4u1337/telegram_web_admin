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

/* ---------- PDO ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
    $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
    $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    if (!$host || !$user || !$name) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB env is not configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/* ---------- helpers ---------- */
function tableExists(PDO $pdo, string $t): bool {
    try {
        $q = $pdo->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
        ");
        $q->execute([':t' => $t]);
        return (bool)$q->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/* ---------- проверка админа ---------- */
$ALLOWED_ROLES = ['owner', 'admin', 'manager'];

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ADMIN_ID = (int)$_SESSION['admin_id'];
$userRole = (string)$_SESSION['admin_role'];

if (!in_array($userRole, $ALLOWED_ROLES, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

// проверяем активность админа
try {
    $st = $pdo->prepare("SELECT is_active FROM admins WHERE id = :id LIMIT 1");
    $st->execute([':id' => $ADMIN_ID]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'admin_inactive'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    error_log('logs_api admin fetch error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'admin_check_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!tableExists($pdo, 'logs')) {
    echo json_encode([
        'ok' => true,
        'data' => [
            'items' => [],
            'page'  => 1,
            'pages' => 1,
            'total' => 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- параметры ---------- */
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$filter = isset($_GET['filter']) ? (string)$_GET['filter'] : 'all';

// Пагинация: 7 записей на страницу
$pageSize = 7;
$where = '1';
$params = [];

if ($filter === 'registration') {
    $where = 'event_type = :t';
    $params[':t'] = 'registration';
} elseif ($filter === 'mail') {
    $where = 'event_type = :t';
    $params[':t'] = 'mail';
}

try {
    // общее количество
    $sqlTotal = "SELECT COUNT(*) FROM logs WHERE {$where}";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $pageSize;

    $sql = "
        SELECT id, tg_id, event_type, message, 
               DATE_FORMAT(created_at, '%d.%m.%Y %H:%i') AS created_at
        FROM logs
        WHERE {$where}
        ORDER BY created_at DESC, id DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => [
            'items' => $items,
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('logs_api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'internal_error',
    ], JSON_UNESCAPED_UNICODE);
}
