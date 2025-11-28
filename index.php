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
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/* ---------- helpers ---------- */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function userInitial(string $s): string {
    $s = trim($s);
    if ($s === '') return 'A';
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($s, 0, 1));
}

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
    try {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        $v = $s->fetchColumn();
        return $v !== false ? $v : 0;
    } catch (Throwable) {
        return 0;
    }
}

function roleLabel($r) {
    switch ($r) {
        case 'owner': return 'Владелец';
        case 'admin': return 'Администратор';
        case 'manager': return 'Менеджер';
        default: return 'Пользователь';
    }
}

function taskStatusLabel(string $s): string {
    return match ($s) {
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'done' => 'Завершена',
        'cancelled' => 'Отменена',
        default => $s,
    };
}

/* ---------- строгая проверка ADMIN (сессия + SQL) ---------- */
$LOGIN_URL = '/login.php';

// Проверяем сессию админа
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    header('Location: ' . $LOGIN_URL);
    exit;
}

$ADMIN_ID = (int)$_SESSION['admin_id'];
$userRole = (string)$_SESSION['admin_role'];
$username = (string)($_SESSION['admin_name'] ?? '');
$userEmail = (string)($_SESSION['admin_email'] ?? '');

// Разрешенные роли для доступа к админ-панели
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
    'name' => $username,
    'email' => $userEmail,
    'role' => $userRole,
    'is_active' => 0
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
    error_log('admin index fetch error: ' . $e->getMessage());
}

// Если админ не найден в БД или не активен - разлогиниваем
if (!$adminExists || (int)$adminRow['is_active'] !== 1) {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    header('Location: ' . $LOGIN_URL);
    exit;
}

/* ---------- итоговые данные по текущему админу ---------- */
$display = $adminRow['name'] ?: $adminRow['email'];
$userInitial = userInitial($display);

/* ---------- наличие таблиц ---------- */
$hasUsers     = tableExists($pdo, 'users');
$hasUserPlans = tableExists($pdo, 'v_user_search_plans');

/* ---------- начальные KPI (all-time, чтобы не было пусто до ajax) ---------- */
$kpiUsersTotalAll   = $hasUsers ? (int)scalar($pdo, "SELECT COUNT(*) FROM users") : 0;
$kpiUsersFreeAll    = $hasUserPlans ? (int)scalar($pdo, "SELECT COUNT(*) FROM v_user_search_plans WHERE plan_code = 'free'") : 0;
$kpiUsersStartAll   = $hasUserPlans ? (int)scalar($pdo, "SELECT COUNT(*) FROM v_user_search_plans WHERE plan_code = 'start'") : 0;
$kpiUsersPremiumAll = $hasUserPlans ? (int)scalar($pdo, "SELECT COUNT(*) FROM v_user_search_plans WHERE plan_code = 'premium'") : 0;

// деньги — нули , заглушка
$kpiMoneyTotalAll   = 0;
$kpiMoneyStartAll   = 0;
$kpiMoneyPremiumAll = 0;
$kpiMoneyGiftsAll   = 0;
$kpiMoneySearchAll  = 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель управления</title>
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
            --sidebar-w: 220px;
            --sidebar-gap: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body, .sidebar, .sidebar-nav, .dashboard { scrollbar-width: none; }
        html::-webkit-scrollbar, body::-webkit-scrollbar, .sidebar::-webkit-scrollbar,
        .sidebar-nav::-webkit-scrollbar, .dashboard::-webkit-scrollbar { display: none; width:0; height:0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            overflow-x: hidden;
        }

        .app-container { display: flex; min-height: 100vh; }

        /* === SIDEBAR === */
        .sidebar {
            position: fixed; top: var(--sidebar-gap); bottom: var(--sidebar-gap); left: var(--sidebar-gap);
            width: var(--sidebar-w);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            display: flex; flex-direction: column; z-index: 100; overflow: hidden;
            transition: var(--transition);
        }
        .sidebar-header { padding: 14px 16px; border-bottom: 1px solid var(--border); display:flex; align-items:center; gap:10px; }
        .logo { width:32px; height:32px; background:linear-gradient(135deg, var(--accent), #a855f7); border-radius: var(--radius-sm);
                display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:14px; transition:var(--transition); }
        .logo:hover { transform: rotate(12deg) scale(1.08); }
        .logo-text { font-weight:700; font-size:16px; background: linear-gradient(135deg, var(--text-primary), var(--text-secondary)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }

        .sidebar-nav { flex:1; padding:10px 8px; overflow-y:auto; }
        .nav-group { margin:6px 6px 10px 6px; }
        .nav-header { padding:8px 10px; color:var(--text-muted); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text-secondary); text-decoration:none;
                    border-radius:var(--radius-sm); transition:var(--transition); position:relative; user-select:none;}
        .nav-item:hover { background:var(--bg-hover); color:var(--text-primary); transform: translateX(4px); }
        .nav-item i { width:18px; font-size:14px; transition: var(--transition); }
        .nav-item:hover i { transform: scale(1.06); }
        .nav-item .badge { margin-left:auto; background:var(--accent); color:#fff; font-size:10px; padding:2px 6px; border-radius:20px; }

        .nav-accordion { margin:2px 0; }
        .nav-accordion input { display:none; }
        .nav-accordion .nav-toggle { cursor:pointer; }
        .nav-accordion .nav-toggle::after { content:"\f107"; font-family:"Font Awesome 6 Free"; font-weight:900; margin-left:auto; transition: transform .3s ease; }
        .nav-accordion .nav-toggle .badge { order:2; }
        .nav-accordion .nav-toggle::after { order:3; }
        .submenu { max-height:0; opacity:0; overflow:hidden; margin:0 6px; border-left:2px solid var(--border); border-radius:var(--radius-sm);
                   background:rgba(255,255,255,0.03); transform: translateY(-4px);
                   transition:max-height .35s ease, opacity .35s ease, transform .35s ease; }
        .submenu a { display:flex; align-items:center; gap:8px; padding:8px 10px; font-size:13px; color:var(--text-secondary); text-decoration:none;
                     border-bottom:1px solid rgba(255,255,255,.04); transition: var(--transition); }
        .submenu a:last-child { border-bottom:0; }
        .submenu a:hover { color:var(--text-primary); background:var(--bg-hover); transform: translateX(6px); }
        .nav-accordion input:checked ~ .submenu { max-height:420px; opacity:1; transform: translateY(0); }
        .nav-accordion input:checked + label.nav-item.nav-toggle::after { transform: rotate(180deg); }

        .sidebar-footer { padding:12px 14px; border-top:1px solid var(--border); background: linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(255,255,255,0.03) 100%); }
        .user-card { display:flex; align-items:center; gap:10px; }
        .user-avatar { width:36px; height:36px; border-radius:var(--radius-sm); background: linear-gradient(135deg, var(--accent), #a855f7);
                       display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:14px; transition:var(--transition);}
        .user-avatar:hover { transform: scale(1.06) rotate(4deg); }
        .user-info { flex:1; }
        .user-name { font-weight:600; font-size:13px; margin-bottom:2px; }
        .user-role { font-size:11px; color:var(--text-muted); }

        .user-logout {
            width:30px;
            height:30px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:var(--text-secondary);
            background:rgba(15,23,42,0.9);
            text-decoration:none;
            transition:var(--transition);
        }
        .user-logout:hover {
            background:var(--danger);
            color:#fff;
            transform:translateY(-1px);
        }

        /* === MAIN === */
        .main-content { flex:1; margin-left: calc(var(--sidebar-w) + var(--sidebar-gap) * 2); padding:0; min-height:100vh; display:flex; flex-direction:column; transition:var(--transition); }
        .top-bar { background:var(--bg-secondary); border:1px solid var(--border); padding:14px 20px; display:flex; justify-content:space-between; align-items:center;
                   backdrop-filter: blur(10px); border-radius:var(--radius-lg); margin: var(--sidebar-gap) var(--sidebar-gap) 12px var(--sidebar-gap); }
        .page-title h1 { font-size:20px; font-weight:700; margin-bottom:4px; background: linear-gradient(135deg, var(--text-primary), var(--accent));
                         -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .page-title p { color:var(--text-secondary); font-size:13px; }

        .btn { padding:8px 14px; border-radius:var(--radius-sm); border:1px solid transparent; font-weight:500; font-size:13px; cursor:pointer; transition:var(--transition);
               display:inline-flex; align-items:center; gap:6px; position:relative; overflow:hidden; }
        .btn::before { content:''; position:absolute; top:50%; left:50%; width:0; height:0; background:rgba(255,255,255,.1); border-radius:50%;
                       transform:translate(-50%,-50%); transition: width .6s, height .6s; }
        .btn:active::before { width:300px; height:300px; }
        .btn-primary { background:var(--accent); color:#fff; box-shadow: var(--shadow); }
        .btn-primary:hover { background:var(--accent-hover); transform: translateY(-2px); }
        .btn-secondary { background:var(--bg-card); color:var(--text-primary); border-color:var(--border); }
        .btn-secondary:hover { background:var(--bg-hover); transform: translateY(-1px); }
        .btn-ghost { background:transparent; color:var(--text-secondary); border:1px dashed var(--border); }
        .btn-ghost.active { border-color: var(--accent); color:#fff; }

        .dashboard { flex:1; padding: 0 var(--sidebar-gap) 20px var(--sidebar-gap); overflow-y:auto; }

        /* KPI switcher */
        .kpi-switcher { display:flex; align-items:center; justify-content:space-between; gap:12px; margin: 14px 0 6px 0; }
        .kpi-tabs { background: var(--bg-secondary); border:1px solid var(--border); padding:6px; border-radius: 999px; display:flex; gap:6px; }
        .kpi-tab { padding:8px 16px; border-radius:999px; cursor:pointer; user-select:none; font-weight:600; font-size:13px; color:var(--text-secondary); background:transparent; border:1px solid transparent; }
        .kpi-tab.active { background:var(--accent); color:#fff; box-shadow: var(--shadow); }
        .kpi-ranges { display:flex; gap:6px; }
        .chip { padding:7px 10px; border-radius:999px; background:var(--bg-secondary); border:1px solid var(--border); color:var(--text-secondary); font-size:12px; cursor:pointer; }
        .chip.active { background: var(--bg-card); color:#fff; border-color: var(--accent); }

        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin: 14px 0; }
        .stat-card { background: var(--bg-card); border-radius: var(--radius-md); padding: 16px; border:1px solid var(--border); position:relative; overflow:hidden; animation: slideUp .6s ease-out; }
        .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: linear-gradient(90deg, var(--accent), #a855f7); }
        .stat-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .stat-icon { width:36px; height:36px; border-radius:var(--radius-sm); background: rgba(139,92,246,.1); display:flex; align-items:center; justify-content:center; color: var(--accent); font-size:16px; transition:var(--transition); }
        .stat-card:hover .stat-icon { transform: scale(1.1) rotate(10deg); }
        .stat-value { font-size:26px; font-weight:800; margin-bottom:4px; background: linear-gradient(135deg, var(--text-primary), var(--accent)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .stat-label { color:var(--text-secondary); font-size:13px; }

        .tone-success .stat-icon { color: var(--success); background: rgba(16,185,129,.1); }
        .tone-warning .stat-icon { color: var(--warning); background: rgba(245,158,11,.12); }
        .tone-info    .stat-icon { color: var(--info);    background: rgba(59,130,246,.12); }
        .tone-primary .stat-icon { color: var(--accent);  background: rgba(139,92,246,.12); }

        .panel { background: var(--bg-card); border-radius: var(--radius-md); border:1px solid var(--border); overflow:hidden; margin: 20px 0; animation: slideUp .8s ease-out; }
        .panel-header { padding: 16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background: var(--bg-secondary); }
        .panel-title { font-size:16px; font-weight:600; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

        .logs-filters { display:flex; flex-wrap:wrap; gap:6px; }
        .logs-filters .chip { font-size:11px; padding:4px 10px; }

        .table-container { overflow-x:auto; }
        #logs-panel .table-container { overflow: visible; }

        table.data-table { width:100%; border-collapse: collapse; }
        .data-table th { background:var(--bg-secondary); padding:12px 14px; text-align:left; font-weight:600; font-size:12px; color:var(--text-secondary); border-bottom:1px solid var(--border); }
        .data-table td { padding:12px 14px; border-bottom:1px solid var(--border); font-size:13px; transition:var(--transition); }
        .data-table tr:last-child td { border-bottom:none; }
        .data-table tbody tr { cursor:pointer; }
        .data-table tr:hover td { background: var(--bg-hover); transform: scale(1.02); }

        .status-badge { padding:5px 10px; border-radius:20px; font-size:11px; font-weight:600; transition: var(--transition); }
        .status-new { background: rgba(59,130,246,.1); color: var(--info); }
        .status-in-progress { background: rgba(245,158,11,.1); color: var(--warning); }
        .status-done { background: rgba(16,185,129,.1); color: var(--success); }
        .status-cancelled { background: rgba(239,68,68,.1); color: var(--danger); }

        .tech-tag { display:inline-block; padding:3px 8px; background: var(--bg-secondary); border-radius:var(--radius-sm); font-size:11px; margin-right:6px; margin-bottom:4px; transition:var(--transition); }
        .tech-tag:hover { background: var(--accent); color:#fff; transform: translateY(-1px); }

        .pagination { display:flex; justify-content:center; padding:16px 20px; border-top:1px solid var(--border); background: var(--bg-secondary); }
        .pagination-items { display:flex; gap:6px; flex-wrap:wrap; }
        .pagination-item { min-width:32px; height:32px; padding:0 10px; display:flex; align-items:center; justify-content:center; border-radius:var(--radius-sm);
                           background: var(--bg-card); color: var(--text-secondary); text-decoration:none; font-size:12px; font-weight:500; transition: var(--transition); }
        .pagination-item:hover { background:var(--bg-hover); color:var(--text-primary); transform: translateY(-1px); }
        .pagination-item.active { background: var(--accent); color:#fff; transform: scale(1.05); }

        /* MODAL LOG DETAILS */
        .modal {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 200;
            pointer-events: none;
            opacity: 0;
            transition: var(--transition);
        }
        .modal.visible {
            pointer-events: auto;
            opacity: 1;
        }
        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(10, 10, 25, 0.75);
            backdrop-filter: blur(8px);
        }
        .modal-dialog {
            position: relative;
            max-width: 720px;
            width: 100%;
            margin: 0 16px;
            background: radial-gradient(circle at top left, rgba(139,92,246,0.12), transparent 55%), var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.65);
            padding: 18px 20px 16px;
            transform: translateY(30px) scale(0.98);
            transition: var(--transition);
        }
        .modal.visible .modal-dialog {
            transform: translateY(0) scale(1);
        }
        .modal-header {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
        }
        .modal-title-block {
            display:flex;
            flex-direction:column;
            gap:3px;
        }
        .modal-title {
            font-size:16px;
            font-weight:600;
        }
        .modal-title span {
            background: linear-gradient(135deg, var(--text-primary), var(--accent));
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
        }
        .modal-meta {
            font-size:12px;
            color:var(--text-muted);
        }
        .modal-close-btn {
            border:none;
            background:rgba(15,23,42,0.8);
            border-radius:999px;
            width:30px;
            height:30px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:var(--text-secondary);
            cursor:pointer;
            transition:var(--transition);
        }
        .modal-close-btn:hover {
            background:var(--bg-hover);
            color:#fff;
            transform:rotate(8deg);
        }
        .modal-body {
            max-height:60vh;
            overflow-y:auto;
            padding-right:4px;
            margin-bottom:10px;
        }
        .modal-row {
            display:flex;
            align-items:flex-start;
            gap:8px;
            padding:6px 0;
            border-bottom:1px dashed rgba(148,163,184,0.25);
        }
        .modal-row:last-child {
            border-bottom:none;
        }
        .modal-label {
            flex:0 0 170px;
            font-size:12px;
            color:var(--text-muted);
        }
        .modal-value {
            flex:1;
            font-size:13px;
            color:var(--text-primary);
            word-break:break-word;
            white-space:pre-wrap;
        }
        .modal-footer {
            display:flex;
            justify-content:flex-end;
            gap:8px;
            margin-top:4px;
        }
        .badge-pill {
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:4px 10px;
            border-radius:999px;
            background:rgba(15,23,42,0.8);
            border:1px solid rgba(148,163,184,0.35);
            font-size:11px;
            color:var(--text-secondary);
        }
        .badge-pill i {
            font-size:11px;
        }

        /* Доп. стили для KPI/логов (анимации + бейджи) */
        .kpi-loading .stat-card {
            opacity: .6;
            filter: blur(.2px);
            transform: translateY(2px);
        }

        .log-type-badge{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:3px 9px;
            border-radius:999px;
            font-size:11px;
            font-weight:600;
            background:rgba(59,130,246,.1);
            color:var(--info);
        }
        .log-type-badge.log-registration{
            background:rgba(16,185,129,.12);
            color:var(--success);
        }
        .log-type-badge.log-mail{
            background:rgba(236,72,153,.12);
            color:#f9a8d4;
        }
        .logs-empty{
            text-align:center;
            padding:24px;
            color:var(--text-muted);
            font-size:13px;
        }
        .logs-row-enter{
            animation: logsRowEnter .3s ease-out;
        }

        @keyframes logsRowEnter{
            from{opacity:0; transform:translateY(6px);}
            to{opacity:1; transform:translateY(0);}
        }

        @keyframes slideUp { from { opacity:0; transform: translateY(30px); } to { opacity:1; transform: translateY(0); } }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(calc(-100% - var(--sidebar-gap))); }
            .main-content { margin-left:0; }
            .top-bar { margin: var(--sidebar-gap); }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar { flex-direction:column; align-items:flex-start; gap:12px; border-radius:var(--radius-md); }
            .dashboard { padding: 0 var(--sidebar-gap) 16px var(--sidebar-gap); }
            .modal-dialog { padding:16px 14px 12px; }
            .modal-label { flex:0 0 120px; }
        }
    </style>
</head>
<body>
 <div class="app-container">
      
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">A</div>
                <div class="logo-text">Админ панель</div>
            </div>

            <nav class="sidebar-nav">
                <!-- ГРУППА: Основное -->
                <div class="nav-group">
                    <div class="nav-header">Основное</div>

                    <!-- Главная -->
                    <div class="nav-accordion">
                        <input id="acc-home" type="checkbox">
                        <label class="nav-item nav-toggle" for="acc-home">
                            <i class="fa-solid fa-circle-info"></i><span>Информация</span>
                        </label>
                        <div class="submenu">
                            <a href="users.php"><i class="fa-solid fa-users"></i><span>Пользователи</span></a>
							<a href="admin_register.php"><i class="fa-solid fa-user-tie"></i><span>Добавить админа</span></a>
                        </div>
                    </div>

                    <!-- Настройки бота -->
                    <div class="nav-accordion">
                        <input id="acc-analytics" type="checkbox">
                        <label class="nav-item nav-toggle" for="acc-analytics">
                            <i class="fa-solid fa-robot"></i><span>Настройки бота</span>
                        </label>
                        <div class="submenu">
                            <a href="start_bot.php"><i class="fa-solid fa-envelope"></i><span>Стартовое сообщение</span></a>
                            <a href="setting_bot.php"><i class="fa-solid fa-gear"></i><span>Настройки бота</span></a>
                            <a href="new_message.php"><i class="fa-solid fa-reply-all"></i><span>Создать oбъявление</span></a>
                        </div>
                    </div>
                </div>

                <!-- ГРУППА: Управление -->
                <div class="nav-group">
                    <div class="nav-header">Подписка</div>

                    <!-- Задачи -->
                    <div class="nav-accordion">
                        <input id="acc-tasks" type="checkbox">
                        <label class="nav-item nav-toggle" for="acc-tasks">
                            <i class="fa-solid fa-gift"></i><span>Тариф</span>
                        </label>
                        <div class="submenu">
                            <a href="new_tariffs.php"><i class="fas fa-plus"></i><span>Создать новый тариф</span></a>
                            <a href="succes_kwork.php"><i class="fas fa-spinner"></i><span>Все Тарифы</span></a>
                        </div>
                    </div>

                    <!-- Выплаты -->
                    <div class="nav-accordion">
                        <input id="acc-payouts" type="checkbox">
                        <label class="nav-item nav-toggle" for="acc-payouts">
                           <i class="fa-solid fa-circle-dollar-to-slot"></i><span>Лимиты</span>
                        </label>
                        <div class="submenu">
                            <a href="edit_pay.php"><i class="fa-solid fa-circle-plus"></i><span>Создать новый лимит</span></a>
                            <a href="succes_pay.php"><i class="fa-solid fa-rotate"></i><span>Все лимиты</span></a>
                        </div>
                    </div>
                </div>

                <!-- ГРУППА: Уведомления -->
                <div class="nav-group">
                    <div class="nav-header">Уведомления</div>

                    <div class="nav-accordion">
                        <input id="acc-announce" type="checkbox">
                        <label class="nav-item nav-toggle" for="acc-announce">
                            <i class="fa-solid fa-rectangle-ad"></i><span>Объявление</span>
                        </label>
                        <div class="submenu">
                            <a href="new_message.php"><i class="fa-solid fa-reply-all"></i><span>Создать oбъявление</span></a>
                        </div>
                    </div>
                </div>
                 <div class="nav-group">
                   <div class="nav-header">Сотрудники</div>

                     <div class="nav-accordion">
                     
                     <input id="acc-test" type="checkbox">
                     <label class="nav-item nav-toggle" for="acc-test">
                     <i class="fa-solid fa-user-plus"></i></i><span>Сотрудники</span>
                     </label>
                     <div class="submenu">
                     <a href="new_job.php"><i class="fa-solid fa-user-pen"></i><span>Сотрудники на проверки</span></a>
				     <a href="all_job.php"><i class="fa-solid fa-user-group"></i><span>Все Сотрудники</span></a>
                 </div>
                </div>
               </div>


            </nav>

            <!-- КАРТОЧКА ПОЛЬЗОВАТЕЛЯ-->
            <div class="sidebar-footer">
                <div class="user-card">
                    <div class="user-avatar">
                        <?php echo h($userInitial); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo h($display); ?></div>
                        <div class="user-role"><?php echo h(roleLabel($userRole)); ?></div>
                    </div>
                    <a href="logout.php" class="user-logout" title="Выйти">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </a>
                </div>
            </div>
        </aside>

        <!-- основа -->
        <main class="main-content">
            <header class="top-bar">
                <div class="page-title">
                    <h1>Панель управления</h1>
                    <p>Добро пожаловать, <?php echo h($display); ?>! · <?php echo date('d.m.Y H:i'); ?></p>
                </div>
            </header>

            <div class="dashboard">
                <!-- Переключатель KPI -->
                <div class="kpi-switcher">
                    <div class="kpi-tabs" id="kpi-tabs">
                        <button class="kpi-tab active" data-tab="users"><i class="fa-solid fa-users"></i> Пользователи</button>
                        <button class="kpi-tab" data-tab="money"><i class="fa-solid fa-sack-dollar"></i> Деньги</button>
                    </div>
                    <div class="kpi-ranges" id="kpi-ranges">
                        <span class="chip active" data-range="7d">7 дней</span>
                        <span class="chip" data-range="30d">30 дней</span>
                    </div>
                </div>

                <!-- Динамические KPI-->
                <div class="stats-grid" id="dynamic-kpis">
                    <div class="stat-card tone-primary">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                            <div class="stat-label">Всего пользователей (all-time)</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($kpiUsersTotalAll, 0, ',', ' '); ?></div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-star-half-stroke"></i></div>
                            <div class="stat-label">Тариф Free (all-time)</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($kpiUsersFreeAll, 0, ',', ' '); ?></div>
                    </div>
                    <div class="stat-card tone-warning">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-rocket"></i></div>
                            <div class="stat-label">Тариф Start (all-time)</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($kpiUsersStartAll, 0, ',', ' '); ?></div>
                    </div>
                    <div class="stat-card tone-success">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-crown"></i></div>
                            <div class="stat-label">Тариф Premium (all-time)</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($kpiUsersPremiumAll, 0, ',', ' '); ?></div>
                    </div>
                </div>

                <!-- ПАНЕЛЬ ЛОГОВ -->
                <div class="panel" id="logs-panel">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fa-solid fa-clock-rotate-left"></i> Логи
                            <div class="logs-filters">
                                <span class="chip logs-filter active" data-filter="all">Все</span>
                                <span class="chip logs-filter" data-filter="registration">Бот</span>
                                <span class="chip logs-filter" data-filter="mail">Рассылка</span>
                            </div>
                        </div>
                        <div class="panel-actions">
                            
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table" id="logs-table">
                            <thead>
                                <tr>
                                    <th>Время</th>
                                    <th>Тип</th>
                                    <th>Событие</th>
                                    <th>Кто</th>
                                </tr>
                            </thead>
                            <tbody id="logs-body">
                                <tr>
                                    <td colspan="4" class="logs-empty">
                                        <i class="fas fa-spinner fa-spin" style="opacity:.7; margin-right:6px;"></i> Загружаем логи…
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Пагинация логов -->
                    <div class="pagination" id="logs-pagination" style="display:none;">
                        <div class="pagination-items" id="logs-pagination-items"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Модальное окно подробностей лога -->
    <div class="modal" id="log-modal">
        <div class="modal-backdrop" data-role="backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title-block">
                    <div class="modal-title">
                        <span id="log-modal-title">Подробности события</span>
                    </div>
                    <div class="modal-meta" id="log-modal-meta"></div>
                </div>
                <button class="modal-close-btn" id="log-modal-close" type="button">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" id="log-modal-body">
                <!-- детали сюда -->
            </div>
            <div class="modal-footer">
                <div class="badge-pill" id="log-modal-id-pill" style="display:none;">
                    <i class="fa-solid fa-hashtag"></i>
                    <span id="log-modal-id"></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    const rect = e.target.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const ripple = document.createElement('span');
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    ripple.classList.add('ripple');
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });

            // ==== KPI через AJAX (Пользователи / Деньги, 7 и 30 дней) ====
            const kpiContainer = document.getElementById('dynamic-kpis');
            const kpiTabs = document.getElementById('kpi-tabs');
            const kpiRanges = document.getElementById('kpi-ranges');

            const stateKpi = {
                tab: 'users',
                range: '7d'
            };

            function formatNumber(n) {
                if (typeof n !== 'number') n = Number(n) || 0;
                return n.toLocaleString('ru-RU');
            }

            function setActiveKpiTab(tab) {
                stateKpi.tab = tab;
                kpiTabs.querySelectorAll('.kpi-tab').forEach(b => {
                    b.classList.toggle('active', b.dataset.tab === tab);
                });
            }

            function setActiveKpiRange(range) {
                stateKpi.range = range;
                kpiRanges.querySelectorAll('.chip').forEach(c => {
                    c.classList.toggle('active', c.dataset.range === range);
                });
            }

            function renderUsersKpis(data) {
                const total   = data.total_users   || 0;
                const free    = data.plan_free     || 0;
                const start   = data.plan_start    || 0;
                const premium = data.plan_premium  || 0;

                kpiContainer.innerHTML = `
                    <div class="stat-card tone-primary">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                            <div class="stat-label">Всего пользователей</div>
                        </div>
                        <div class="stat-value">${formatNumber(total)}</div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-star-half-stroke"></i></div>
                            <div class="stat-label">Тариф Free</div>
                        </div>
                        <div class="stat-value">${formatNumber(free)}</div>
                    </div>
                    <div class="stat-card tone-warning">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-rocket"></i></div>
                            <div class="stat-label">Тариф Start</div>
                        </div>
                        <div class="stat-value">${formatNumber(start)}</div>
                    </div>
                    <div class="stat-card tone-success">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-crown"></i></div>
                            <div class="stat-label">Тариф Premium</div>
                        </div>
                        <div class="stat-value">${formatNumber(premium)}</div>
                    </div>
                `;
            }

            function renderMoneyKpis(data) {
                const total   = data.total_revenue   || 0;
                const start   = data.start_revenue   || 0;
                const premium = data.premium_revenue || 0;
                const gifts   = data.gifts_revenue   || 0;
                const search  = data.search_revenue  || 0;

                kpiContainer.innerHTML = `
                    <div class="stat-card tone-primary">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                            <div class="stat-label">Всего выручки</div>
                        </div>
                        <div class="stat-value">${formatNumber(total)}</div>
                    </div>
                    <div class="stat-card tone-warning">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-rocket"></i></div>
                            <div class="stat-label">Тариф Start</div>
                        </div>
                        <div class="stat-value">${formatNumber(start)}</div>
                    </div>
                    <div class="stat-card tone-success">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-crown"></i></div>
                            <div class="stat-label">Тариф Premium</div>
                        </div>
                        <div class="stat-value">${formatNumber(premium)}</div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-gift"></i></div>
                            <div class="stat-label">За подарки</div>
                        </div>
                        <div class="stat-value">${formatNumber(gifts)}</div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="stat-header">
                            <div class="stat-icon"><i class="fa-solid fa-magnifying-glass-dollar"></i></div>
                            <div class="stat-label">За поиск</div>
                        </div>
                        <div class="stat-value">${formatNumber(search)}</div>
                    </div>
                `;
            }

            function loadKpis() {
                if (!kpiContainer) return;
                kpiContainer.classList.add('kpi-loading');

                const params = new URLSearchParams({
                    tab: stateKpi.tab,
                    range: stateKpi.range
                });

                fetch('kpi_api.php?' + params.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(resp => resp.json())
                .then(json => {
                    if (!json || !json.ok) return;
                    if (stateKpi.tab === 'users') {
                        renderUsersKpis(json.data || {});
                    } else {
                        renderMoneyKpis(json.data || {});
                    }
                })
                .catch(console.error)
                .finally(() => {
                    kpiContainer.classList.remove('kpi-loading');
                });
            }

            if (kpiTabs) {
                kpiTabs.addEventListener('click', e => {
                    const btn = e.target.closest('.kpi-tab');
                    if (!btn) return;
                    const tab = btn.dataset.tab;
                    if (!tab || tab === stateKpi.tab) return;
                    setActiveKpiTab(tab);
                    loadKpis();
                });
            }
            if (kpiRanges) {
                kpiRanges.addEventListener('click', e => {
                    const chip = e.target.closest('.chip');
                    if (!chip) return;
                    const range = chip.dataset.range;
                    if (!range || range === stateKpi.range) return;
                    setActiveKpiRange(range);
                    loadKpis();
                });
            }

            // первый запрос KPI (Пользователи, 7 дней)
            setActiveKpiTab(stateKpi.tab);
            setActiveKpiRange(stateKpi.range);
            loadKpis(); // без setInterval — ничего не моргает

            // ====== LOGS ======
            const logsBody = document.getElementById('logs-body');
            const logsPagination = document.getElementById('logs-pagination');
            const logsPaginationItems = document.getElementById('logs-pagination-items');
            const logsFilters = document.querySelectorAll('.logs-filter');

            const stateLogs = {
                filter: 'all',
                page: 1,
            };

            function escapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function prettifyType(eventType) {
                if (!eventType) return '—';
                if (eventType === 'registration') return 'Бот';
                if (eventType === 'mail') return 'Рассылка';
                return eventType;
            }

            function typeBadgeClass(eventType) {
                if (eventType === 'registration') return 'log-registration';
                if (eventType === 'mail') return 'log-mail';
                return '';
            }

            function renderLogs(rows) {
                if (!rows || !rows.length) {
                    logsBody.innerHTML = `
                        <tr>
                            <td colspan="4" class="logs-empty">
                                <i class="fa-regular fa-circle-dot" style="opacity:.6; margin-right:6px;"></i>
                                Логи по выбранному фильтру отсутствуют
                            </td>
                        </tr>
                    `;
                    return;
                }

                let html = '';
                for (const row of rows) {
                    const created = row.created_at ? row.created_at : '';
                    const eventType = row.event_type || '';
                    const tgId = row.tg_id ? String(row.tg_id) : '';
                    const msg = row.message || '';

                    html += `
                        <tr class="logs-row-enter" data-id="${row.id}">
                            <td>${escapeHtml(created)}</td>
                            <td>
                                <span class="log-type-badge ${typeBadgeClass(eventType)}">
                                    <i class="fa-solid fa-circle"></i>
                                    ${escapeHtml(prettifyType(eventType))}
                                </span>
                            </td>
                            <td>${escapeHtml(msg.length > 220 ? msg.slice(0, 220) + '…' : msg)}</td>
                            <td>${tgId ? ('TG: ' + escapeHtml(tgId)) : '—'}</td>
                        </tr>
                    `;
                }
                logsBody.innerHTML = html;
            }

            function renderLogsPagination(page, pages) {
                if (!pages || pages <= 1) {
                    logsPagination.style.display = 'none';
                    logsPaginationItems.innerHTML = '';
                    return;
                }
                logsPagination.style.display = '';

                let html = '';
                const makeItem = (label, targetPage, disabled = false, active = false) => {
                    const cls = [
                        'pagination-item',
                        disabled ? 'disabled' : '',
                        active ? 'active' : ''
                    ].filter(Boolean).join(' ');
                    return `<button class="${cls}" data-page="${disabled ? '' : targetPage}">${label}</button>`;
                };

                // prev
                html += makeItem('<', Math.max(1, page - 1), page <= 1, false);

                const maxButtons = 5;
                let start = Math.max(1, page - 2);
                let end = Math.min(pages, start + maxButtons - 1);
                if (end - start + 1 < maxButtons) {
                    start = Math.max(1, end - maxButtons + 1);
                }

                if (start > 1) {
                    html += makeItem('1', 1, false, page === 1);
                    if (start > 2) {
                        html += `<span class="pagination-item disabled">...</span>`;
                    }
                }

                for (let p = start; p <= end; p++) {
                    html += makeItem(String(p), p, false, p === page);
                }

                if (end < pages) {
                    if (end < pages - 1) {
                        html += `<span class="pagination-item disabled">...</span>`;
                    }
                    html += makeItem(String(pages), pages, false, page === pages);
                }

                // next
                html += makeItem('>', Math.min(pages, page + 1), page >= pages, false);

                logsPaginationItems.innerHTML = html;
            }

            function loadLogs(page = 1) {
                stateLogs.page = page;

                const params = new URLSearchParams({
                    page: String(page),
                    filter: stateLogs.filter
                });

                logsBody.innerHTML = `
                    <tr>
                        <td colspan="4" class="logs-empty">
                            <i class="fas fa-spinner fa-spin" style="opacity:.7; margin-right:6px;"></i>
                            Загружаем логи…
                        </td>
                    </tr>
                `;

                fetch('logs_api.php?' + params.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(resp => resp.json())
                    .then(json => {
                        if (!json || !json.ok) {
                            logsBody.innerHTML = `
                                <tr>
                                    <td colspan="4" class="logs-empty">
                                        Ошибка загрузки логов
                                    </td>
                                </tr>
                            `;
                            logsPagination.style.display = 'none';
                            return;
                        }
                        renderLogs(json.data.items || []);
                        renderLogsPagination(json.data.page || 1, json.data.pages || 1);
                    })
                    .catch(err => {
                        console.error(err);
                        logsBody.innerHTML = `
                            <tr>
                                <td colspan="4" class="logs-empty">
                                    Ошибка загрузки логов
                                </td>
                            </tr>
                        `;
                        logsPagination.style.display = 'none';
                    });
            }

            // фильтры логов
            logsFilters.forEach(chip => {
                chip.addEventListener('click', () => {
                    const filter = chip.dataset.filter || 'all';
                    if (stateLogs.filter === filter) return;
                    stateLogs.filter = filter;
                    logsFilters.forEach(c => c.classList.toggle('active', c === chip));
                    loadLogs(1);
                });
            });

            // пагинация логов
            if (logsPaginationItems) {
                logsPaginationItems.addEventListener('click', e => {
                    const btn = e.target.closest('.pagination-item');
                    if (!btn) return;
                    if (btn.classList.contains('disabled')) return;
                    const page = parseInt(btn.dataset.page || '1', 10);
                    if (!page || page === stateLogs.page) return;
                    loadLogs(page);
                });
            }

            // модалка логов
            const logModal = document.getElementById('log-modal');
            const logModalClose = document.getElementById('log-modal-close');
            const logModalTitle = document.getElementById('log-modal-title');
            const logModalMeta = document.getElementById('log-modal-meta');
            const logModalBody = document.getElementById('log-modal-body');
            const logModalId = document.getElementById('log-modal-id');
            const logModalIdPill = document.getElementById('log-modal-id-pill');

            function closeLogModal() {
                if (!logModal) return;
                logModal.classList.remove('visible');
            }

            function openLogModal(rowData) {
                if (!logModal) return;
                logModalTitle.textContent = 'Подробности события';
                logModalMeta.textContent = (rowData.created_at || '') + ' · ' + prettifyType(rowData.event_type || '');

                logModalBody.innerHTML = `
                    <div class="modal-row">
                        <div class="modal-label">Тип события</div>
                        <div class="modal-value">${escapeHtml(prettifyType(rowData.event_type || ''))}</div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">TG ID</div>
                        <div class="modal-value">${rowData.tg_id ? escapeHtml(String(rowData.tg_id)) : '—'}</div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Сообщение</div>
                        <div class="modal-value">${escapeHtml(rowData.message || '')}</div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Создано</div>
                        <div class="modal-value">${escapeHtml(rowData.created_at || '')}</div>
                    </div>
                `;

                if (rowData.id) {
                    logModalId.textContent = '#' + rowData.id;
                    logModalIdPill.style.display = '';
                } else {
                    logModalIdPill.style.display = 'none';
                }

                logModal.classList.add('visible');
            }

            if (logsBody) {
                logsBody.addEventListener('click', e => {
                    const tr = e.target.closest('tr[data-id]');
                    if (!tr) return;
                    const id = tr.getAttribute('data-id');
                    const tds = Array.from(tr.children);
                    const created = tds[0]?.textContent.trim() || '';
                    const typeText = tds[1]?.innerText.trim() || '';
                    const msg = tds[2]?.innerText.trim() || '';
                    const user = tds[3]?.innerText.trim() || '';

                    // Определяем event_type по тексту
                    let ev = '';
                    if (typeText.toLowerCase().includes('рассыл')) ev = 'mail';
                    else if (typeText.toLowerCase().includes('бот')) ev = 'registration';

                    openLogModal({
                        id: id,
                        created_at: created,
                        event_type: ev,
                        message: msg,
                        tg_id: user.startsWith('TG:') ? user.replace('TG:','').trim() : user
                    });
                });
            }

            if (logModalClose) logModalClose.addEventListener('click', closeLogModal);
            if (logModal) {
                logModal.addEventListener('click', e => {
                    if (e.target.dataset.role === 'backdrop') {
                        closeLogModal();
                    }
                });
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') closeLogModal();
                });
            }

            // первый запрос логов (страница 1, по 7 шт на странице в logs_api.php)
            loadLogs(1);
        });

        // Стили для ripple-элемента
        const style = document.createElement('style');
        style.textContent = `
            .ripple {
                position: absolute;
                background: rgba(255, 255, 255, 0.3);
                border-radius: 50%;
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
            }
            
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
