<?php

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php'; 

// На всякий случай включим режим исключений
if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Куда шлем после успешного входа
$redirect_after = '/love/panel/index.php';

$error = '';
$email_value = '';

// Если уже залогинен — сразу отправляем в панель
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . $redirect_after);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $pass  = isset($_POST['password']) ? (string)$_POST['password'] : '';

    $email_value = $email;

    if ($email === '' || $pass === '') {
        $error = 'Введите почту и пароль.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный формат почты.';
    } else {
        try {
            // Ищем админа по email
            $sql = "SELECT id, user_id, email, name, role, password_hash, is_active
                    FROM admins
                    WHERE email = :email
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin || (int)$admin['is_active'] !== 1) {
                $error = 'Неверная почта или пароль.';
            } else {
                if (!password_verify($pass, $admin['password_hash'])) {
                    $error = 'Неверная почта или пароль.';
                } else {
                    // всё ок — логиним
                    if (function_exists('session_regenerate_id')) {
                        @session_regenerate_id(true);
                    }

                    // данные админа
                    $_SESSION['admin_id']    = (int)$admin['id'];
                    $_SESSION['admin_email'] = (string)$admin['email'];
                    $_SESSION['admin_name']  = (string)($admin['name'] ?? '');
                    $_SESSION['admin_role']  = (string)($admin['role'] ?? 'admin');

                    // привязываем к владельцу из users (FK admins.user_id -> users.id)
                    $_SESSION['user_id']     = (int)($admin['user_id'] ?? 0);

                    $_SESSION['logged_in_at'] = time();

                    header('Location: ' . $redirect_after);
                    exit;
                }
            }
        } catch (Throwable $e) {

            error_log('Admin login error: ' . $e->getMessage());
            $error = 'Ошибка сервера. Попробуйте позже.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Вход в панель</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root{
            --bg:#050816;
            --bg2:#0b1020;
            --card-bg:rgba(15,23,42,.96);
            --card-border:rgba(148,163,255,.35);
            --text:#e5e7eb;
            --muted:#9ca3af;
            --accent1:#9a4df7;
            --accent2:#6a5af9;
            --accent3:#ff4fd8;
            --input-bg:#020617;
            --error-bg:rgba(248,113,113,.10);
            --error-border:rgba(248,113,113,.70);
            --error-text:#fecaca;
            --success:#22c55e;
        }

        *{box-sizing:border-box;}

        html,body{
            height:100%;
        }

        body{
            margin:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color:var(--text);
            background:
                radial-gradient(800px 800px at 10% 0%, rgba(154,77,247,.18), transparent 60%),
                radial-gradient(900px 900px at 90% 100%, rgba(255,79,216,.12), transparent 60%),
                linear-gradient(180deg, #020617 0%, #020617 50%, #020617 100%);
            overflow-x:hidden;
        }

        .bg-orbits::before,
        .bg-orbits::after{
            content:"";
            position:fixed;
            inset:-30vmax;
            background:radial-gradient(closest-side, rgba(106,90,249,.12), transparent 60%);
            filter:blur(60px);
            opacity:.7;
            mix-blend-mode:screen;
            pointer-events:none;
            z-index:0;
        }
        .bg-orbits::after{
            background:radial-gradient(closest-side, rgba(255,79,216,.10), transparent 60%);
        }

        .wrap{
            position:relative;
            min-height:100svh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px 16px;
            z-index:1;
        }

        .card{
            width:100%;
            max-width:480px;
            border-radius:24px;
            padding:22px 20px 20px;
            background:linear-gradient(
                145deg,
                rgba(15,23,42,.96),
                rgba(15,23,42,.96)
            );
            border:1px solid rgba(148,163,255,.45);
            box-shadow:
                0 30px 80px rgba(15,23,42,.95),
                0 0 0 1px rgba(15,23,42,.9) inset;
            backdrop-filter:blur(18px) saturate(1.1);
            position:relative;
            overflow:hidden;
        }

        .card::before{
            content:"";
            position:absolute;
            inset:-40%;
            background:
                radial-gradient(500px 200px at -10% 0%, rgba(154,77,247,.20), transparent 60%),
                radial-gradient(500px 200px at 110% 120%, rgba(255,79,216,.20), transparent 60%);
            opacity:.7;
            z-index:-1;
        }

        .card-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .title-block{
            display:flex;
            flex-direction:column;
            gap:4px;
        }

        h1{
            margin:0;
            font-size:24px;
            font-weight:800;
            letter-spacing:.02em;
        }

        .subtitle{
            margin:0;
            font-size:13px;
            color:var(--muted);
        }

        .badge-pill{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid rgba(148,163,255,.6);
            background:radial-gradient(circle at 0% 0%, rgba(154,77,247,.3), transparent 55%);
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:#e5e7eb;
        }

        .badge-dot{
            width:9px;
            height:9px;
            border-radius:999px;
            background:var(--success);
            box-shadow:0 0 0 0 rgba(34,197,94,.7);
            animation:pulse-dot 1.8s infinite;
        }

        @keyframes pulse-dot{
            0%{box-shadow:0 0 0 0 rgba(34,197,94,.7);}
            70%{box-shadow:0 0 0 10px rgba(34,197,94,0);}
            100%{box-shadow:0 0 0 0 rgba(34,197,94,0);}
        }

        .hint-top{
            font-size:12px;
            color:var(--muted);
            margin-bottom:10px;
        }

        form{
            display:grid;
            gap:12px;
            margin-top:4px;
        }

        label{
            font-size:13px;
            color:var(--muted);
            margin-bottom:2px;
            display:inline-block;
        }

        .field{
            display:flex;
            flex-direction:column;
            gap:4px;
        }

        .field-inner{
            position:relative;
        }

        input[type="email"],
        input[type="password"]{
            width:100%;
            padding:11px 14px 11px 38px;
            border-radius:14px;
            border:1px solid rgba(148,163,255,.3);
            background:radial-gradient(circle at 0% 0%, rgba(148,163,255,.12), transparent 70%), var(--input-bg);
            color:var(--text);
            font-size:14px;
            outline:none;
            transition:border-color .18s, box-shadow .18s, transform .08s ease, background .18s;
        }

        input::placeholder{
            color:rgba(148,163,184,.7);
        }

        input:focus{
            border-color:rgba(154,77,247,.9);
            box-shadow:
                0 0 0 1px rgba(154,77,247,.7),
                0 0 0 8px rgba(154,77,247,.18);
            transform:translateY(-1px);
            background:radial-gradient(circle at 0% 0%, rgba(148,163,255,.18), transparent 70%), var(--input-bg);
        }

        .field-icon{
            position:absolute;
            left:12px;
            top:50%;
            transform:translateY(-50%);
            font-size:13px;
            opacity:.8;
        }

        .error{
            margin:4px 0 8px;
            padding:9px 10px;
            border-radius:12px;
            background:var(--error-bg);
            border:1px solid var(--error-border);
            color:var(--error-text);
            font-size:13px;
        }

        .row-inline{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-top:2px;
        }

        .remember-wrap{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:12px;
            color:var(--muted);
        }

        .remember-wrap input[type="checkbox"]{
            width:15px;
            height:15px;
            border-radius:4px;
            border:1px solid rgba(148,163,255,.7);
            background:rgba(15,23,42,1);
            appearance:none;
            outline:none;
            display:inline-block;
            position:relative;
            cursor:pointer;
            transition:border-color .18s, background .18s;
        }
        .remember-wrap input[type="checkbox"]:checked{
            background:linear-gradient(135deg, var(--accent1), var(--accent3));
            border-color:rgba(154,77,247,1);
        }
        .remember-wrap input[type="checkbox"]:checked::after{
            content:"";
            position:absolute;
            inset:3px 4px 3px 3px;
            border-right:2px solid #f9fafb;
            border-bottom:2px solid #f9fafb;
            transform:rotate(35deg);
        }

        .link-muted{
            font-size:12px;
            color:var(--muted);
            text-decoration:none;
        }
        .link-muted:hover{
            color:#e5e7eb;
            text-decoration:underline;
        }

        .btn-primary{
            width:100%;
            margin-top:8px;
            padding:12px 16px;
            border-radius:999px;
            border:none;
            cursor:pointer;
            font-weight:700;
            font-size:14px;
            color:#f9fafb;
            background:radial-gradient(120px 120px at 20% 0%, rgba(255,255,255,.18), transparent 60%),
                       linear-gradient(135deg, var(--accent1), var(--accent2), var(--accent3));
            box-shadow:
                0 14px 40px rgba(154,77,247,.55),
                0 0 0 1px rgba(248,250,252,.06) inset;
            transition:transform .09s ease, box-shadow .2s, filter .2s;
            position:relative;
            overflow:hidden;
        }

        .btn-primary:hover{
            transform:translateY(-1px);
            filter:brightness(1.02);
            box-shadow:
                0 18px 46px rgba(154,77,247,.75),
                0 0 0 1px rgba(248,250,252,.12) inset;
        }

        .btn-primary:active{
            transform:translateY(0);
            box-shadow:
                0 10px 28px rgba(154,77,247,.65),
                0 0 0 1px rgba(248,250,252,.12) inset;
        }

        .btn-primary span{
            position:relative;
            z-index:1;
        }

        .btn-primary::before{
            content:"";
            position:absolute;
            inset:0;
            background:radial-gradient(220px 220px at var(--mx,50%) var(--my,0%), rgba(255,255,255,.24), transparent 65%);
            opacity:0;
            transition:opacity .2s;
        }
        .btn-primary:hover::before{
            opacity:.9;
        }

        .footer-text{
            margin-top:14px;
            font-size:11px;
            text-align:center;
            color:var(--muted);
        }

        .footer-text a{
            color:#e5e7eb;
            text-decoration:none;
        }
        .footer-text a:hover{
            text-decoration:underline;
        }

        @media (max-width: 480px){
            .card{
                padding:18px 16px 18px;
                border-radius:20px;
            }
            h1{font-size:20px;}
            .subtitle{font-size:12px;}
            .badge-pill{display:none;}
        }
    </style>
</head>
<body class="bg-orbits">
<div class="wrap">
    <div class="card">
        <div class="card-header">
            <div class="title-block">
                <h1>Вход в панель</h1>
            </div>
        </div>


        <?php if ($error): ?>
            <div class="error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="email">Почта</label>
                <div class="field-inner">
                    <span class="field-icon">📧</span>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        placeholder="you@example.com"
                        required
                        value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>
            </div>

            <div class="field">
                <label for="password">Пароль</label>
                <div class="field-inner">
                    <span class="field-icon">🔒</span>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Введите пароль"
                        required
                    >
                </div>
            </div>
            <button class="btn-primary" type="submit" id="loginBtn">
                <span>Войти</span>
            </button>
        </form>
    </div>
</div>

<script>
(function(){
    var btn = document.getElementById('loginBtn');
    if (!btn) return;
    btn.addEventListener('mousemove', function(e){
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
