<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/password.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}
if (empty($_SESSION['csrf_forgot'])) $_SESSION['csrf_forgot'] = bin2hex(random_bytes(16));

$mailReady = mailConfigured();
$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mailReady) {
    $username = trim($_POST['username'] ?? '');
    if (!hash_equals($_SESSION['csrf_forgot'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Хуудасны хугацаа дууссан байна. Дахин оролдоно уу.';
    } elseif ($username === '') {
        $error = 'Ажилтны кодоо оруулна уу.';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT u.id AS auth_user_id, e.email, e.last_name, e.first_name
             FROM users u JOIN employees e ON e.id = u.employee_id
             WHERE (u.username = ? OR e.employee_code = ?) AND u.is_active = 1 AND e.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        // Бүртгэлгүй, имэйлгүй, хязгаар хэтэрсэн аль ч тохиолдолд хариу ижил — хэн бүртгэлтэйг таахаас сэргийлнэ
        if ($user && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $recent = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
            $recent->execute([$user['auth_user_id']]);
            if ((int)$recent->fetchColumn() < PASSWORD_RESET_MAX_PER_HOUR) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
                               VALUES (?, ?, NOW() + INTERVAL " . PASSWORD_RESET_TTL_MINUTES . " MINUTE, ?)")
                    ->execute([$user['auth_user_id'], hash('sha256', $token), $_SERVER['REMOTE_ADDR'] ?? null]);

                $link = rtrim(APP_PUBLIC_URL, '/') . '/reset_password.php?token=' . $token;
                $name = employeeName($user['last_name'], $user['first_name']);
                $minutes = PASSWORD_RESET_TTL_MINUTES;
                $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1c2430;line-height:1.6">'
                      . '<p>Сайн байна уу, ' . htmlspecialchars($name) . '.</p>'
                      . '<p>Buuruljuut Intranet-ийн нууц үгээ сэргээх хүсэлт ирлээ. Доорх товч дээр дарж шинэ нууц үг тохируулна уу:</p>'
                      . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#f1592a;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Нууц үг сэргээх</a></p>'
                      . '<p style="color:#64748b;font-size:12px">Холбоос ' . $minutes . ' минут хүчинтэй, нэг удаа ашиглагдана. Оффисын сүлжээнээс нээнэ үү.<br>'
                      . 'Хэрэв та энэ хүсэлтийг илгээгээгүй бол энэ имэйлийг үл тоомсорлоно уу — нууц үг тань өөрчлөгдөхгүй.</p>'
                      . '</div>';
                $text = "Сайн байна уу, $name.\n\nНууц үгээ сэргээхийн тулд дараах холбоосыг нээнэ үү ($minutes минут хүчинтэй):\n$link\n\n"
                      . "Хэрэв та энэ хүсэлтийг илгээгээгүй бол энэ имэйлийг үл тоомсорлоно уу.";
                try {
                    sendMail($user['email'], $name, 'Нууц үг сэргээх — Buuruljuut Intranet', $html, $text);
                } catch (Exception $e) {
                    // Хэрэглэгчид ижил хариу өгнө; алдааг серверийн лог руу бичнэ
                    error_log('[password reset] user ' . $user['auth_user_id'] . ': ' . $e->getMessage());
                }
            }
        }
        $sent = true;
    }
}

authPageStart('Нууц үг мартсан', 'Ажилтны кодоо оруулбал бүртгэлтэй имэйл рүү тань сэргээх холбоос илгээнэ.');

if (!$mailReady) {
    authAlert('info', 'Имэйлээр сэргээх боломж одоогоор тохируулагдаагүй байна. Түр нууц үг авахын тулд HR-т хандана уу.');
} elseif ($sent) {
    authAlert('success', 'Хэрэв энэ код бүртгэлтэй бөгөөд имэйл хаягтай бол ' . PASSWORD_RESET_TTL_MINUTES
        . ' минут хүчинтэй сэргээх холбоос илгээгдлээ. Имэйлээ шалгана уу. Имэйл ирэхгүй бол HR-т хандаж түр нууц үг авна уу.');
} else {
    if ($error) authAlert('error', $error); ?>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_forgot']) ?>">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Ажилтны код</label>
                <input type="text" name="username" required autocomplete="username" placeholder="Таны ажилтны код"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="<?= AUTH_INPUT_CLS ?>"/>
            </div>
            <button type="submit" class="<?= AUTH_BUTTON_CLS ?>">Сэргээх холбоос илгээх</button>
        </form>
        <p class="text-xs text-slate-400 mt-4">Бүртгэлтэй имэйл хаяггүй бол HR-т хандаж түр нууц үг авна уу.</p>
<?php }

authPageEnd();
