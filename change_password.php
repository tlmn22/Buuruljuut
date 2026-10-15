<?php
$pageTitle  = 'Нууц үг солих';
$activePage = 'change-password';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/password.php';
requireLogin();

$pdo    = getDB();
$forced = !empty($_SESSION['must_change_password']);
$error  = '';
$done   = false;

if (empty($_SESSION['csrf_pw'])) $_SESSION['csrf_pw'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['auth_user_id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!hash_equals($_SESSION['csrf_pw'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Хуудасны хугацаа дууссан байна. Дахин оролдоно уу.';
    } elseif (!password_verify($current, $hash)) {
        $error = 'Одоогийн нууц үг буруу байна.';
    } elseif ($new !== $confirm) {
        $error = 'Шинэ нууц үг давтан оруулсантай таарахгүй байна.';
    } elseif (password_verify($new, $hash)) {
        $error = 'Шинэ нууц үг одоогийнхоосоо өөр байх ёстой.';
    } elseif ($policy = passwordPolicyError($new)) {
        $error = $policy;
    } else {
        setUserPassword($pdo, (int)$_SESSION['auth_user_id'], $new, false);
        $_SESSION['must_change_password'] = false;
        session_regenerate_id(true);
        unset($_SESSION['csrf_pw']);
        if ($forced) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
        $done = true;
    }
}

include __DIR__ . '/includes/header.php';
$inputCls = 'w-full border border-slate-200 dark:border-[#2a2f3b] dark:bg-[#272c38] rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30';
?>

<div class="max-w-lg w-full mx-auto">
    <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
        <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
        <span class="mx-2">/</span>
        <span class="text-[#f1592a] font-medium">Нууц үг солих</span>
    </nav>
    <h1 class="text-3xl font-bold mb-6">Нууц үг солих</h1>

    <?php if ($forced): ?>
    <div class="mb-5 flex items-start gap-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 text-amber-700 dark:text-amber-400 rounded-xl px-4 py-3 text-sm">
        <span class="material-icons-outlined text-lg">lock_reset</span>
        Та түр нууц үгээр нэвтэрсэн байна. Үргэлжлүүлэхийн өмнө өөрийн шинэ нууц үгийг тохируулна уу.
    </div>
    <?php endif; ?>

    <?php if ($done): ?>
    <div class="mb-5 flex items-center gap-3 bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900/40 text-green-700 dark:text-green-400 rounded-xl px-4 py-3 text-sm">
        <span class="material-icons-outlined text-lg">check_circle</span>
        Нууц үг амжилттай солигдлоо.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-5 flex items-center gap-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 text-red-600 dark:text-red-400 rounded-xl px-4 py-3 text-sm">
        <span class="material-icons-outlined text-lg">error_outline</span>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_pw']) ?>">
        <div>
            <label class="block text-sm font-semibold mb-1.5"><?= $forced ? 'Түр нууц үг' : 'Одоогийн нууц үг' ?></label>
            <input type="password" name="current_password" required autocomplete="current-password" class="<?= $inputCls ?>">
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1.5">Шинэ нууц үг</label>
            <input type="password" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" class="<?= $inputCls ?>">
            <p class="text-xs text-slate-400 dark:text-[#8b93a1] mt-1.5">Хамгийн багадаа <?= PASSWORD_MIN_LENGTH ?> тэмдэгт, дор хаяж нэг үсэг, нэг тоо.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1.5">Шинэ нууц үг (давтах)</label>
            <input type="password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" class="<?= $inputCls ?>">
        </div>
        <div class="flex items-center justify-between gap-3 pt-2">
            <?php if ($forced): ?>
                <a href="<?= BASE_URL ?>/logout.php" class="text-sm text-slate-500 dark:text-[#8b93a1] hover:underline">Гарах</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <button type="submit" class="bg-[#f1592a] text-white px-5 py-2.5 rounded-lg font-medium hover:bg-[#c33e12] transition-colors">Нууц үг солих</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
