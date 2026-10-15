<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/password.php';
require_once __DIR__ . '/includes/auth_layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo   = getDB();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';

/** Хүчинтэй (ашиглаагүй, хугацаа дуусаагүй, идэвхтэй хэрэглэгчийн) токены мөрийг буцаана */
function findValidReset(PDO $pdo, string $token): ?array {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;
    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.user_id FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         JOIN employees e ON e.id = u.employee_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
           AND u.is_active = 1 AND e.is_active = 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

$reset = findValidReset($pdo, $token);

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($new !== $confirm) {
        $error = 'Шинэ нууц үг давтан оруулсантай таарахгүй байна.';
    } elseif ($policy = passwordPolicyError($new)) {
        $error = $policy;
    } else {
        // Энэ болон бусад хүлээгдэж буй бүх холбоосыг хүчингүй болгоно (setUserPassword дотор)
        setUserPassword($pdo, (int)$reset['user_id'], $new, false);
        header('Location: ' . BASE_URL . '/login.php?reset=1');
        exit;
    }
}

authPageStart('Шинэ нууц үг тохируулах', 'Шинэ нууц үгээ хоёр удаа оруулна уу.');

if (!$reset) {
    authAlert('error', 'Холбоос хүчингүй байна — хугацаа нь дууссан (' . PASSWORD_RESET_TTL_MINUTES . ' минут), аль хэдийн ашиглагдсан эсвэл буруу байна.'); ?>
        <a href="<?= BASE_URL ?>/forgot_password.php" class="<?= AUTH_BUTTON_CLS ?> block text-center">Шинэ холбоос авах</a>
<?php } else {
    if ($error) authAlert('error', $error); ?>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Шинэ нууц үг</label>
                <input type="password" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" class="<?= AUTH_INPUT_CLS ?>"/>
                <p class="text-xs text-slate-400 mt-1.5">Хамгийн багадаа <?= PASSWORD_MIN_LENGTH ?> тэмдэгт, дор хаяж нэг үсэг, нэг тоо.</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Шинэ нууц үг (давтах)</label>
                <input type="password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" class="<?= AUTH_INPUT_CLS ?>"/>
            </div>
            <button type="submit" class="<?= AUTH_BUTTON_CLS ?>">Нууц үг хадгалах</button>
        </form>
<?php }

authPageEnd();
