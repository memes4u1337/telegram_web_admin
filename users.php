<?php
// users.php — список пользователей бота

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

function roleLabel($r) {
    switch ($r) {
        case 'owner':   return 'Владелец';
        case 'admin':   return 'Администратор';
        case 'manager': return 'Менеджер';
        default:        return 'Пользователь';
    }
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

function userInitial(string $s): string {
    $s = trim($s);
    if ($s === '') return 'U';
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($s, 0, 1));
}

/**
 * Рендер HTML карточки текущего плана (для вкладки "Подписка")
 */
function renderPlanCardHtml(?array $planInfo): string {
    ob_start();
    if ($planInfo): ?>
        <div style="margin-bottom:18px;padding:12px 14px;border-radius:10px;border:1px solid rgba(139,92,246,.4);background:rgba(15,23,42,.85);animation:fadeIn .3s ease-out;">
            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">
                <i class="fa-solid fa-crown" style="margin-right:6px;color:#facc15;"></i>Текущий тариф
            </div>
            <div style="font-size:13px;color:#b8b8d6;margin-bottom:4px;">
                <?php echo h($planInfo['plan_title'] ?? $planInfo['plan_code']); ?>
                <span style="opacity:.7;">(<?php echo h($planInfo['plan_code']); ?>)</span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;font-size:12px;color:#b8b8d6;">
                <span>Лимит в день: <strong><?php echo (int)($planInfo['plan_daily_limit'] ?? 0); ?></strong></span>
                <span>Использовано сегодня: <strong><?php echo (int)($planInfo['used_today'] ?? 0); ?></strong></span>
                <span>Осталось: <strong><?php echo (int)($planInfo['remaining_today'] ?? 0); ?></strong></span>
                <?php if (!empty($planInfo['last_reset_date'])): ?>
                    <span>Последний сброс: <strong><?php echo h(date('d.m.Y', strtotime($planInfo['last_reset_date']))); ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div style="margin-bottom:18px;font-size:13px;color:#6c6c8c;animation:fadeIn .3s ease-out;">
            Тариф не назначен (пользователь на базовом лимите).
        </div>
    <?php endif;
    return ob_get_clean();
}

/* ---------- строгая проверка ADMIN как в index.php ---------- */
$LOGIN_URL = '/login.php';

// Проверяем сессию админа
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role'])) {
    header('Location: ' . $LOGIN_URL);
    exit;
}

$ADMIN_ID   = (int)$_SESSION['admin_id'];
$adminRole  = (string)$_SESSION['admin_role'];
$adminName  = (string)($_SESSION['admin_name']  ?? '');
$adminEmail = (string)($_SESSION['admin_email'] ?? '');

// Разрешенные роли
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
    error_log('users.php admin fetch error: ' . $e->getMessage());
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

/* ---------- AJAX: детальная инфа по юзеру (вкладки Информация / Подписка) ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'user_details') {
    header('Content-Type: text/html; charset=utf-8');

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($userId <= 0) {
        echo '<div style="text-align:center;padding:40px;color:#ef4444;">Ошибка: ID пользователя не указан</div>';
        exit;
    }

    try {
        // Основные данные пользователя
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            echo '<div style="text-align:center;padding:40px;color:#ef4444;">Пользователь не найден</div>';
            exit;
        }

        // Попытка подтянуть тариф из v_user_search_plans по tg_id
        $planInfo = null;
        if (!empty($u['tg_id']) && tableExists($pdo, 'v_user_search_plans')) {
            try {
                $ps = $pdo->prepare("
                    SELECT plan_code, plan_title, plan_daily_limit, used_today, remaining_today, last_reset_date
                    FROM v_user_search_plans
                    WHERE tg_id = :tg
                    LIMIT 1
                ");
                $ps->execute([':tg' => $u['tg_id']]);
                $planInfo = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('users.php plan fetch error: ' . $e->getMessage());
            }
        }

        // Список доступных планов для ручного выбора
        $plans = [];
        try {
            if (tableExists($pdo, 'search_plans')) {
                $qPlans = $pdo->query("
                    SELECT code, title, daily_limit 
                    FROM search_plans 
                    WHERE is_active = 1 
                    ORDER BY sort_order, title
                ");
                $plans = $qPlans->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            error_log('users.php search_plans fetch error: ' . $e->getMessage());
        }

        $displayName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $u['name_telegram_profile'] ?: ($u['username'] ? '@'.$u['username'] : 'Пользователь');
        }

        // Фото или заглушка
        $avatarUrl = !empty($u['avatar_path']) ? $u['avatar_path'] : '/love/panel/assets/img/user.png';

        $fmtDateTime = function($v) {
            if (empty($v) || $v === '0000-00-00 00:00:00') return '—';
            return date('d.m.Y H:i', strtotime($v));
        };
        $fmtDate = function($v) {
            if (empty($v) || $v === '0000-00-00') return '—';
            return date('d.m.Y', strtotime($v));
        };

        $onboarding = !empty($u['onboarding_completed'])       ? 'Да' : 'Нет';
        $webapp     = !empty($u['webapp_profile_completed'])   ? 'Да' : 'Нет';

        // JSON поля
        $partnerReq = [];
        if (!empty($u['partner_requirements_json'])) {
            $tmp = json_decode($u['partner_requirements_json'], true);
            if (is_array($tmp)) $partnerReq = $tmp;
        }
        $selfTraits = [];
        if (!empty($u['self_traits_json'])) {
            $tmp = json_decode($u['self_traits_json'], true);
            if (is_array($tmp)) $selfTraits = $tmp;
        }

        ?>
        <div class="user-details-modal" data-user-id="<?php echo (int)$u['id']; ?>" style="padding: 0;">
            <div style="padding: 18px 22px 12px 22px;border-bottom:1px solid rgba(51,65,85,.8);background:rgba(15,23,42,.95);">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;">
                    <div style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,#8b5cf6,#a855f7);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:22px;box-shadow:0 10px 25px rgba(0,0,0,.45);overflow:hidden;animation:pulseGlow 2.6s ease-in-out infinite;">
                        <img src="<?php echo h($avatarUrl); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;border:1px solid rgba(148,163,184,.5);">
                    </div>
                    <div>
                        <div style="font-size:18px;font-weight:600;margin-bottom:4px;"><?php echo h($displayName); ?></div>
                        <div style="font-size:13px;color:#b8b8d6;">
                            ID: #<?php echo (int)$u['id']; ?>
                            <?php if (!empty($u['username'])): ?>
                                · @<?php echo h($u['username']); ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:#6c6c8c;margin-top:2px;">
                            Создан: <?php echo h($fmtDateTime($u['created_at'] ?? '')); ?> · Обновлён: <?php echo h($fmtDateTime($u['updated_at'] ?? '')); ?>
                        </div>
                    </div>
                </div>

                <!-- tabs -->
                <div class="tabs-switcher" style="margin-top:14px;display:inline-flex;padding:3px;border-radius:999px;background:rgba(15,23,42,.9);border:1px solid rgba(148,163,184,.45);">
                    <button type="button" class="tab-btn active" data-tab="info" style="border:none;background:transparent;padding:6px 16px;border-radius:999px;font-size:12px;font-weight:500;color:#e5e7eb;cursor:pointer;transition:all .2s;">
                        Информация
                    </button>
                    <button type="button" class="tab-btn" data-tab="subscription" style="border:none;background:transparent;padding:6px 16px;border-radius:999px;font-size:12px;font-weight:500;color:#9ca3af;cursor:pointer;transition:all .2s;">
                        Подписка
                    </button>
                </div>
            </div>

            <div style="padding: 18px 22px 22px 22px;">
                <!-- TAB: Информация -->
                <div class="tab-content tab-content-info active">
                    <div style="display:grid;grid-template-columns:1.15fr 1fr;gap:18px;margin-bottom:18px;">
                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:8px;color:#ffffff;">Основное</div>
                            <div style="display:grid;grid-template-columns:140px 1fr;row-gap:6px;font-size:13px;">
                                <div style="color:#6c6c8c;">Telegram ID</div>
                                <div><?php echo h($u['tg_id']); ?></div>

                                <div style="color:#6c6c8c;">Username</div>
                                <div><?php echo $u['username'] ? '@'.h($u['username']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Имя</div>
                                <div><?php echo $u['first_name'] ? h($u['first_name']) : '—'; ?></div>

                                <?php if (!empty($u['last_name'])): ?>
                                    <div style="color:#6c6c8c;">Фамилия</div>
                                    <div><?php echo h($u['last_name']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($u['name_telegram_profile'])): ?>
                                    <div style="color:#6c6c8c;">Имя в профиле TG</div>
                                    <div><?php echo h($u['name_telegram_profile']); ?></div>
                                <?php endif; ?>

                                <div style="color:#6c6c8c;">Язык Telegram</div>
                                <div><?php echo $u['language_code'] ? h($u['language_code']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Язык приложения</div>
                                <div><?php echo $u['app_language'] ? h($u['app_language']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Онбординг</div>
                                <div><?php echo h($onboarding); ?></div>

                                <div style="color:#6c6c8c;">Профиль в webapp</div>
                                <div><?php echo h($webapp); ?></div>
                            </div>
                        </div>

                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:8px;color:#ffffff;">Профиль</div>
                            <div style="display:grid;grid-template-columns:140px 1fr;row-gap:6px;font-size:13px;">
                                <div style="color:#6c6c8c;">Дата рождения</div>
                                <div><?php echo h($fmtDate($u['birth_date'] ?? '')); ?></div>

                                <div style="color:#6c6c8c;">Знак зодиака</div>
                                <div><?php echo $u['zodiac_sign'] ? h($u['zodiac_sign']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Пол</div>
                                <div><?php echo $u['gender'] ? h($u['gender']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Ищет</div>
                                <div><?php echo $u['seeking_gender'] ? h($u['seeking_gender']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Локация</div>
                                <div><?php echo $u['location'] ? h($u['location']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Тип семьи</div>
                                <div><?php echo $u['family_type'] ? h($u['family_type']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Тип семьи родителей</div>
                                <div><?php echo $u['parents_family_type'] ? h($u['parents_family_type']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Деятельность родителей</div>
                                <div><?php echo $u['parents_main_activity'] ? h($u['parents_main_activity']) : '—'; ?></div>

                                <div style="color:#6c6c8c;">Сфера партнёра</div>
                                <div><?php echo $u['partner_activity_scope'] ? h($u['partner_activity_scope']) : '—'; ?></div>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;">
                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">Требования к партнёру</div>
                            <?php if ($partnerReq): ?>
                                <ul style="margin:0;padding-left:18px;font-size:13px;color:#b8b8d6;">
                                    <?php foreach ($partnerReq as $item): ?>
                                        <li style="animation:fadeIn .3s ease-out;"><?php echo h(is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : $item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div style="font-size:13px;color:#6c6c8c;">Не заполнено</div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">Собственные качества</div>
                            <?php if ($selfTraits): ?>
                                <ul style="margin:0;padding-left:18px;font-size:13px;color:#b8b8d6;">
                                    <?php foreach ($selfTraits as $item): ?>
                                        <li style="animation:fadeIn .3s ease-out;"><?php echo h(is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : $item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div style="font-size:13px;color:#6c6c8c;">Не заполнено</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;font-size:13px;">
                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">Предпочтения в партнёре</div>
                            <div style="color:#b8b8d6;white-space:pre-wrap;"><?php echo $u['partner_preferences'] ? h($u['partner_preferences']) : 'Не заполнено'; ?></div>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">Вредные привычки</div>
                            <div style="color:#b8b8d6;white-space:pre-wrap;"><?php echo $u['bad_habits'] ? h($u['bad_habits']) : 'Не заполнено'; ?></div>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:#ffffff;">Жизненные приоритеты</div>
                            <div style="color:#b8b8d6;white-space:pre-wrap;"><?php echo $u['life_priorities'] ? h($u['life_priorities']) : 'Не заполнено'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- TAB: Подписка -->
                <div class="tab-content tab-content-subscription" style="display:none;">
                    <div class="subscription-current">
                        <?php echo renderPlanCardHtml($planInfo); ?>
                    </div>

                    <div style="margin-top:4px;padding:12px 14px;border-radius:10px;border:1px solid rgba(55,65,81,.9);background:rgba(15,23,42,.9);animation:slideUp .25s ease-out;">
                        <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:#ffffff;">
                            Ручное изменение подписки
                        </div>

                        <?php if ($plans): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                                <div style="flex:1;min-width:220px;">
                                    <label for="planSelect" style="display:block;font-size:12px;color:#b8b8d6;margin-bottom:4px;">Тариф</label>
                                    <select id="planSelect" class="form-input" style="width:100%;min-width:0;padding-right:28px;">
                                        <?php
                                        $currentCode = $planInfo['plan_code'] ?? ($u['tariff_code'] ?? 'free');
                                        foreach ($plans as $p):
                                            $code = (string)$p['code'];
                                            $selected = ($code === $currentCode) ? 'selected' : '';
                                            ?>
                                            <option value="<?php echo h($code); ?>" <?php echo $selected; ?>>
                                                <?php echo h($p['title']); ?> (<?php echo h($code); ?>, <?php echo (int)$p['daily_limit']; ?>/день)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-primary" id="savePlanBtn">
                                        <i class="fa-solid fa-floppy-disk"></i> Сохранить
                                    </button>
                                </div>
                            </div>
                            <div style="margin-top:8px;font-size:11px;color:#6c6c8c;">
                                При смене тарифа счётчик дневного лимита будет сброшен на 0 для выбранного плана.
                            </div>
                        <?php else: ?>
                            <div style="font-size:13px;color:#ef4444;">
                                Таблица тарифов не найдена или пустая. Нечего выбрать.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } catch (Throwable $e) {
        error_log('users.php user_details error: ' . $e->getMessage());
        echo '<div style="text-align:center;padding:40px;color:#ef4444;">Ошибка загрузки данных</div>';
    }
    exit;
}

/* ---------- AJAX: обновление плана пользователя ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_plan') {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['ok' => false, 'message' => 'Не удалось обновить подписку'];

    $userId   = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $planCode = isset($_POST['plan_code']) ? trim((string)$_POST['plan_code']) : '';

    if ($userId <= 0 || $planCode === '') {
        $res['message'] = 'Некорректные данные (user_id / plan_code)';
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Проверяем, что план существует и активен
        $st = $pdo->prepare("SELECT code, title, daily_limit FROM search_plans WHERE code = :c AND is_active = 1 LIMIT 1");
        $st->execute([':c' => $planCode]);
        $planRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$planRow) {
            $res['message'] = 'Указанный тариф не найден или отключён';
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Берём tg_id пользователя
        $st = $pdo->prepare("SELECT tg_id FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $uRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$uRow || empty($uRow['tg_id'])) {
            $res['message'] = 'Пользователь не найден или не задан tg_id';
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tgId = $uRow['tg_id'];

        // Вызываем процедуру sp_set_user_plan, которая создаёт/обновляет user_search_limits и сбрасывает счётчик
        $call = $pdo->prepare("CALL sp_set_user_plan(:tg_id, :plan_code)");
        $call->execute([
            ':tg_id'     => $tgId,
            ':plan_code' => $planCode,
        ]);
        while ($call->nextRowset()) {}

        // Обновляем users.tariff_code (чтобы не расходилось с планами)
        try {
            $up = $pdo->prepare("UPDATE users SET tariff_code = :code WHERE tg_id = :tg LIMIT 1");
            $up->execute([':code' => $planCode, ':tg' => $tgId]);
        } catch (Throwable $e) {
            error_log('users.php update users.tariff_code error: ' . $e->getMessage());
        }

        // Забираем актуальную инфу по плану из v_user_search_plans
        $planInfo = null;
        if (tableExists($pdo, 'v_user_search_plans')) {
            $ps = $pdo->prepare("
                SELECT plan_code, plan_title, plan_daily_limit, used_today, remaining_today, last_reset_date
                FROM v_user_search_plans
                WHERE tg_id = :tg
                LIMIT 1
            ");
            $ps->execute([':tg' => $tgId]);
            $planInfo = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $res['ok']             = true;
        $res['message']        = 'Подписка успешно обновлена';
        $res['html_plan_card'] = renderPlanCardHtml($planInfo);
    } catch (Throwable $e) {
        error_log('users.php update_plan error: ' . $e->getMessage());
        $res['message'] = 'Ошибка при обновлении подписки';
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Если не AJAX, рендерим страницу списка ---------- */

// Поиск
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$whereSql = '';
$params = [];
if ($search !== '') {
    $whereSql = "WHERE (username LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR name_telegram_profile LIKE :q OR location LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

// Пагинация
$pageSize = 12;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Общее количество
$countSql = "SELECT COUNT(*) FROM users " . $whereSql;
$st = $pdo->prepare($countSql);
$st->execute($params);
$totalUsers = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($totalUsers / $pageSize));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $pageSize;

// Получаем пользователей
$listSql = "SELECT * FROM users " . $whereSql . " ORDER BY created_at DESC LIMIT :lim OFFSET :off";
$st = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':lim', $pageSize, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$users = $st->fetchAll(PDO::FETCH_ASSOC);

// Для пагинации — URL билдер
$base = strtok($_SERVER['REQUEST_URI'], '?');
$qs = $_GET;
unset($qs['page']);
$buildPageUrl = function(int $p) use ($base, $qs): string {
    $qs['page'] = $p;
    $query = http_build_query($qs);
    return h($base . ($query ? '?' . $query : ''));
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Пользователи бота</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

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

        .filters{
            background:rgba(36,36,66,.95);
            border-radius:var(--radius-md);
            padding:16px 18px;
            margin-bottom:18px;
            border:1px solid var(--border);
            display:flex;
            gap:14px;
            flex-wrap:wrap;
            align-items:flex-end;
            box-shadow:0 14px 40px rgba(0,0,0,.55);
            animation: slideUp .4s ease-out;
        }
        .filter-group{
            display:flex;
            flex-direction:column;
            gap:6px;
        }
        .filter-label{
            font-size:12px;
            color:var(--text-secondary);
            font-weight:500;
        }
        .form-input{
            padding:9px 12px;
            background:var(--bg-secondary);
            border-radius:var(--radius-sm);
            border:1px solid var(--border);
            color:var(--text-primary);
            font-size:13px;
            min-width:260px;
            transition:var(--transition);
        }
        .form-input:focus{
            outline:none;
            border-color:var(--accent);
            box-shadow:0 0 0 1px rgba(139,92,246,.5), 0 0 25px rgba(139,92,246,.35);
            transform:translateY(-1px);
        }

        .users-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
            gap:16px;
            margin-bottom:24px;
        }
        .user-card{
            background:var(--bg-card);
            border-radius:var(--radius-md);
            border:1px solid var(--border);
            padding:16px 16px 14px;
            position:relative;
            overflow:hidden;
            animation:slideUp .5s ease-out;
            transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .user-card::before{
            content:'';
            position:absolute;
            top:0;left:-40%;right:-40%;
            height:3px;
            background:linear-gradient(90deg,var(--accent),#a855f7);
            opacity:.8;
            transform:translateX(-20%);
            animation: shimmer 2.6s linear infinite;
        }
        .user-card:hover{
            transform:translateY(-6px) scale(1.01);
            box-shadow:0 18px 40px rgba(0,0,0,.7);
            border-color:rgba(139,92,246,.8);
        }

        .user-header{
            display:flex;
            align-items:center;
            gap:12px;
        }
        .user-avatar{
            width:46px;
            height:46px;
            border-radius:12px;
            background:linear-gradient(135deg,var(--accent),#a855f7);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-weight:700;
            font-size:18px;
            box-shadow:0 10px 25px rgba(0,0,0,.45);
            transition:var(--transition);
            overflow:hidden;
            animation:pulseGlow 2.8s ease-in-out infinite;
        }
        .user-avatar:hover{
            transform:scale(1.08) rotate(4deg) translateY(-1px);
        }
        .user-avatar img{
            width:100%;
            height:100%;
            object-fit:cover;
            border-radius:inherit;
            border:1px solid rgba(148,163,184,.5);
        }

        .user-info{
            flex:1;
        }
        .user-name{
            font-size:15px;
            font-weight:600;
            margin-bottom:4px;
        }
        .user-meta{
            font-size:12px;
            color:var(--text-secondary);
        }

        .user-details{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:8px 12px;
            margin:10px 0 12px;
            font-size:12px;
            color:var(--text-secondary);
        }
        .user-detail-label{
            color:var(--text-muted);
        }
        .user-detail-value{
            color:var(--text-primary);
        }

        .user-actions{
            display:flex;
            justify-content:flex-end;
            margin-top:4px;
        }

        .empty-state{
            text-align:center;
            padding:40px 20px;
            color:var(--text-muted);
            font-size:14px;
            animation:fadeIn .4s ease-out;
        }

        .pagination{
            display:flex;
            justify-content:center;
            padding:16px 0;
        }
        .pagination-items{
            display:flex;
            gap:6px;
            flex-wrap:wrap;
        }
        .pagination-item{
            min-width:32px;
            height:32px;
            padding:0 8px;
            border-radius:var(--radius-sm);
            background:var(--bg-card);
            color:var(--text-secondary);
            display:flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            font-size:12px;
            font-weight:500;
            transition:var(--transition);
        }
        .pagination-item:hover{
            background:var(--bg-hover);
            color:var(--text-primary);
            transform:translateY(-1px);
        }
        .pagination-item.active{
            background:var(--accent);
            color:#fff;
            transform:scale(1.05);
        }

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
            max-width:960px;
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
            padding:0;
        }

        .tab-btn.active{
            background:rgba(31,41,55,1);
            color:#ffffff !important;
            box-shadow:0 0 0 1px rgba(148,163,184,.7);
        }
        .tab-content{
            margin-top:4px;
            opacity:0;
            transform:translateY(4px);
            transition:opacity .18s ease-out, transform .18s ease-out;
        }
        .tab-content.active{
            display:block !important;
            opacity:1;
            transform:translateY(0);
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
        @keyframes pulseGlow{
            0%,100%{box-shadow:0 10px 30px rgba(124,58,237,.55);}
            50%{box-shadow:0 18px 40px rgba(124,58,237,.9);}
        }

        @media (max-width: 768px){
            body{padding:14px;}
            .header{flex-direction:column;gap:12px;}
            .filters{flex-direction:column;align-items:stretch;}
            .form-input{min-width:100%;}
            .user-details{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="page-title">
                <h1>Пользователи бота</h1>
                <p>Всего пользователей: <?php echo number_format($totalUsers, 0, ',', ' '); ?></p>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <div class="admin-pill">
                    <i class="fa-solid fa-user-shield"></i>
                    <span><?php echo h($adminDisplay); ?> · <?php echo h(roleLabel($adminRole)); ?></span>
                </div>
                <div>
                    <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i>Назад</a>
                </div>
            </div>
        </div>

        <div class="filters">
            <div class="filter-group" style="flex:1;">
                <label class="filter-label">Поиск по нику, имени, фамилии или локации</label>
                <input type="text" class="form-input" id="searchInput" placeholder="Например: @username, Москва, Анна..."
                       value="<?php echo h($search); ?>">
            </div>
            <div>
                <button class="btn btn-secondary" id="btnClearSearch"><i class="fa-solid fa-eraser"></i>Сброс</button>
            </div>
        </div>

        <?php if (!$users): ?>
            <div class="empty-state">
                <i class="fa-regular fa-circle-user" style="font-size:34px;margin-bottom:8px;display:block;opacity:.6;"></i>
                Пользователи не найдены
            </div>
        <?php else: ?>
            <div class="users-grid">
                <?php foreach ($users as $u): ?>
                    <?php
                    $nameParts = [];
                    if (!empty($u['first_name'])) $nameParts[] = $u['first_name'];
                    if (!empty($u['last_name']))  $nameParts[] = $u['last_name'];
                    $displayName = trim(implode(' ', $nameParts));
                    if ($displayName === '') {
                        $displayName = $u['name_telegram_profile'] ?: ($u['username'] ? '@'.$u['username'] : 'Пользователь');
                    }
                    $avatarUrl  = !empty($u['avatar_path']) ? $u['avatar_path'] : '/love/panel/assets/img/user.png';
                    ?>
                    <div class="user-card" data-user-id="<?php echo (int)$u['id']; ?>">
                        <div class="user-header">
                            <div class="user-avatar">
                                <img src="<?php echo h($avatarUrl); ?>" alt="avatar">
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo h($displayName); ?></div>
                                <div class="user-meta">
                                    ID: #<?php echo (int)$u['id']; ?>
                                    <?php if (!empty($u['username'])): ?>
                                        · @<?php echo h($u['username']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="user-meta" style="margin-top:2px;">
                                    Создан: <?php echo h(date('d.m.Y H:i', strtotime($u['created_at']))); ?>
                                </div>
                            </div>
                        </div>
                        <div class="user-details">
                            <div>
                                <div class="user-detail-label">Telegram ID</div>
                                <div class="user-detail-value"><?php echo h($u['tg_id']); ?></div>
                            </div>
                            <div>
                                <div class="user-detail-label">Пол</div>
                                <div class="user-detail-value"><?php echo $u['gender'] ? h($u['gender']) : '—'; ?></div>
                            </div>
                            <div>
                                <div class="user-detail-label">Локация</div>
                                <div class="user-detail-value"><?php echo $u['location'] ? h($u['location']) : '—'; ?></div>
                            </div>
                            <div>
                                <div class="user-detail-label">Знак зодиака</div>
                                <div class="user-detail-value"><?php echo $u['zodiac_sign'] ? h($u['zodiac_sign']) : '—'; ?></div>
                            </div>
                        </div>
                        <div class="user-actions">
                            <button class="btn btn-primary" onclick="openUserModal(<?php echo (int)$u['id']; ?>);">
                                <i class="fa-solid fa-eye"></i>Подробнее
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <div class="pagination-items">
                        <a class="pagination-item" href="<?php echo $buildPageUrl(max(1, $page-1)); ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($pages, $page + 2);
                        if ($start > 1): ?>
                            <a class="pagination-item" href="<?php echo $buildPageUrl(1); ?>">1</a>
                            <?php if ($start > 2): ?>
                                <span class="pagination-item">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <a class="pagination-item <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo $buildPageUrl($p); ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($end < $pages): ?>
                            <?php if ($end < $pages - 1): ?>
                                <span class="pagination-item">...</span>
                            <?php endif; ?>
                            <a class="pagination-item" href="<?php echo $buildPageUrl($pages); ?>"><?php echo $pages; ?></a>
                        <?php endif; ?>
                        <a class="pagination-item" href="<?php echo $buildPageUrl(min($pages, $page+1)); ?>">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Модалка -->
    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">Данные пользователя</div>
                <button class="modal-close" id="userModalClose">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" id="userModalBody">
                <div style="text-align:center;padding:40px;">
                    <i class="fa-solid fa-spinner fa-spin" style="opacity:.8;"></i> Загрузка...
                </div>
            </div>
        </div>
    </div>

    <script>
        const searchInput    = document.getElementById('searchInput');
        const btnClearSearch = document.getElementById('btnClearSearch');

        function applySearch() {
            const value  = searchInput.value.trim();
            const params = new URLSearchParams(window.location.search);
            if (value) params.set('search', value); else params.delete('search');
            params.delete('page'); // на первую страницу
            window.location.search = params.toString();
        }

        searchInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                applySearch();
            }
        });

        btnClearSearch.addEventListener('click', function(){
            searchInput.value = '';
            applySearch();
        });

        const modal     = document.getElementById('userModal');
        const modalBody = document.getElementById('userModalBody');
        const modalClose= document.getElementById('userModalClose');

        function openUserModal(userId) {
            modal.classList.add('active');
            modalBody.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fa-solid fa-spinner fa-spin" style="opacity:.8;"></i> Загрузка...</div>';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'users.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onreadystatechange = function(){
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        modalBody.innerHTML = xhr.responseText;
                    } else {
                        modalBody.innerHTML = '<div style="text-align:center;padding:40px;color:#ef4444;">Ошибка загрузки данных</div>';
                    }
                }
            };
            xhr.send('ajax=user_details&user_id=' + encodeURIComponent(userId));
        }

        function closeUserModal() {
            modal.classList.remove('active');
            setTimeout(() => {
                const body = document.getElementById('userModalBody');
                if (body) body.scrollTop = 0;
            }, 200);
        }

        modalClose.addEventListener('click', closeUserModal);
        modal.addEventListener('click', function(e){
            if (e.target === modal) closeUserModal();
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') closeUserModal();
        });

        // Делегирование событий внутри модалки (табы + сохранение подписки)
        modalBody.addEventListener('click', function(e){
            const tabBtn = e.target.closest('.tab-btn');
            if (tabBtn) {
                const tab = tabBtn.getAttribute('data-tab');
                const wrapper = modalBody.querySelector('.user-details-modal');
                if (!wrapper) return;

                const allTabBtns = modalBody.querySelectorAll('.tab-btn');
                allTabBtns.forEach(function(b){ b.classList.remove('active'); });
                tabBtn.classList.add('active');

                const info = modalBody.querySelector('.tab-content-info');
                const sub  = modalBody.querySelector('.tab-content-subscription');
                if (info && sub) {
                    if (tab === 'info') {
                        info.classList.add('active');
                        sub.classList.remove('active');
                        sub.style.display = 'none';
                        info.style.display = 'block';
                    } else {
                        sub.classList.add('active');
                        info.classList.remove('active');
                        info.style.display = 'none';
                        sub.style.display = 'block';
                    }
                }
                return;
            }

            const saveBtn = e.target.closest('#savePlanBtn');
            if (saveBtn) {
                const wrapper = modalBody.querySelector('.user-details-modal');
                if (!wrapper) return;
                const userId = wrapper.getAttribute('data-user-id');
                const select = modalBody.querySelector('#planSelect');
                if (!userId || !select) return;

                const planCode = select.value;
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'users.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onreadystatechange = function(){
                    if (xhr.readyState === 4) {
                        let msg = 'Ошибка сохранения';
                        let res = null;
                        try {
                            res = JSON.parse(xhr.responseText);
                        } catch (err) {
                            res = null;
                        }
                        if (xhr.status === 200 && res) {
                            if (res.message) msg = res.message;
                            if (res.ok && res.html_plan_card) {
                                const cont = modalBody.querySelector('.subscription-current');
                                if (cont) cont.innerHTML = res.html_plan_card;
                            }
                        }
                        alert(msg);
                    }
                };
                xhr.send(
                    'ajax=update_plan'
                    + '&user_id=' + encodeURIComponent(userId)
                    + '&plan_code=' + encodeURIComponent(planCode)
                );
            }
        });
    </script>
</body>
</html>
