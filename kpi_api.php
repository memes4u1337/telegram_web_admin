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
function scalar(PDO $pdo, string $sql, array $a = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($a);
    $v = $stmt->fetchColumn();
    return $v !== false ? $v : 0;
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

// проверяем, что админ активен
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
    error_log('kpi_api admin fetch error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'admin_check_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- параметры ---------- */
$tab   = isset($_GET['tab'])   ? (string)$_GET['tab']   : 'users';
$range = isset($_GET['range']) ? (string)$_GET['range'] : '7d';

function buildRangeFilter(string $range): array {
    $range = strtolower($range);
    $where = '';
    $params = [];

    if ($range === '7d') {
        $from = date('Y-m-d H:i:s', time() - 7 * 86400);
        $where = 'created_at >= :from';
        $params[':from'] = $from;
    } elseif ($range === '30d') {
        $from = date('Y-m-d H:i:s', time() - 30 * 86400);
        $where = 'created_at >= :from';
        $params[':from'] = $from;
    } else {
        // на всякий случай — без фильтра (all-time)
        $where = '';
        $params = [];
    }

    return ['where' => $where, 'params' => $params];
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ($tab === 'users') {
        if (!tableExists($pdo, 'users')) {
            echo json_encode([
                'ok' => true,
                'data' => [
                    'total_users'  => 0,
                    'plan_free'    => 0,
                    'plan_start'   => 0,
                    'plan_premium' => 0,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $hasView = tableExists($pdo, 'v_user_search_plans');

        $filter = buildRangeFilter($range);
        $where = $filter['where'];
        $params = $filter['params'];

        $sqlUsersTotal = 'SELECT COUNT(*) FROM users';
        if ($where !== '') {
            $sqlUsersTotal .= ' WHERE ' . $where;
        }
        $totalUsers = (int)scalar($pdo, $sqlUsersTotal, $params);

        $planFree = $planStart = $planPremium = 0;

        if ($hasView) {
            // линкуем по tg_id, режем по users.created_at
            $sql = "
                SELECT v.plan_code, COUNT(*) AS cnt
                FROM v_user_search_plans v
                JOIN users u ON u.tg_id = v.tg_id
            ";
            if ($where !== '') {
                $sql .= " WHERE u.created_at >= :from_plans";
                $paramsPlans = [':from_plans' => $params[':from'] ?? null];
            } else {
                $paramsPlans = [];
            }
            $sql .= " GROUP BY v.plan_code";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsPlans);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $code = (string)$row['plan_code'];
                $cnt  = (int)$row['cnt'];
                if ($code === 'free')    $planFree    = $cnt;
                if ($code === 'start')   $planStart   = $cnt;
                if ($code === 'premium') $planPremium = $cnt;
            }
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'total_users'  => $totalUsers,
                'plan_free'    => $planFree,
                'plan_start'   => $planStart,
                'plan_premium' => $planPremium,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tab === 'money') {
        // таблиц выручки нет — заглушка
        echo json_encode([
            'ok' => true,
            'data' => [
                'total_revenue'   => 0,
                'start_revenue'   => 0,
                'premium_revenue' => 0,
                'gifts_revenue'   => 0,
                'search_revenue'  => 0,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown_tab'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('kpi_api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
}
