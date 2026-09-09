<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
if(session_status() === PHP_SESSION_NONE) session_start();

if(isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if(!$username || !$password) {
        $error = 'Ажилтны код болон нууц үгээ оруулна уу.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT u.id AS auth_user_id, u.password_hash, u.is_active AS user_active,
                    e.id, e.employee_code, e.last_name, e.first_name, e.position, e.org_unit_id, e.is_active AS employee_active
             FROM users u
             JOIN employees e ON e.id = u.employee_id
             WHERE (u.username = ? OR e.employee_code = ?)
             LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if(!$user) {
            $error = 'Ажилтны код эсвэл нууц үг буруу байна.';
        } elseif(!$user['user_active'] || !$user['employee_active']) {
            $error = 'Энэ бүртгэл идэвхгүй болсон байна. IT албатай холбогдоно уу.';
        } elseif(empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $error = 'Ажилтны код эсвэл нууц үг буруу байна.';
        } else {
            $rolesStmt = $pdo->prepare(
                "SELECT r.code FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ? AND r.is_active = 1"
            );
            $rolesStmt->execute([$user['auth_user_id']]);
            $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);

            session_regenerate_id(true);
            $_SESSION['user_id']          = $user['id'];
            $_SESSION['auth_user_id']     = $user['auth_user_id'];
            $_SESSION['user_name']        = $user['last_name'] . '. ' . $user['first_name'];
            $_SESSION['user_roles']       = $roles;
            $_SESSION['user_role']        = $roles[0] ?? 'employee';
            $_SESSION['user_code']        = $user['employee_code'];
            $_SESSION['user_position']    = $user['position'] ?? '';
            $_SESSION['user_org_unit_id'] = $user['org_unit_id'];

            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['auth_user_id']]);

            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Нэвтрэх | Enterprise HR</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet"/>
<style>
* { font-family: 'Inter', sans-serif; }
.circle-bg { position:absolute; border-radius:50%; border:1px solid rgba(255,255,255,0.15); }
</style>
</head>
<body class="bg-[#f5f6f8]">
<div class="min-h-screen flex">

    <!-- ЗҮҮН ТАЛ -->
    <div class="hidden lg:flex w-[52%] bg-[#f1592a] relative overflow-hidden flex-col justify-between p-14">
        <div class="circle-bg w-[500px] h-[500px] top-[-100px] left-[-100px]"></div>
        <div class="circle-bg w-[400px] h-[400px] top-[80px] left-[80px]"></div>
        <div class="circle-bg w-[300px] h-[300px] bottom-[100px] right-[-50px]"></div>
        <div class="circle-bg w-[200px] h-[200px] bottom-[50px] right-[50px]"></div>
        <div class="relative z-10">
            <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center">
                <span class="material-icons-outlined text-white text-3xl">trending_up</span>
            </div>
        </div>
        <div class="relative z-10 space-y-6">
            <h1 class="text-5xl font-extrabold text-white leading-tight">INTRANET<br/>Систем</h1>
            <p class="text-white/70 text-lg leading-relaxed max-w-sm">
            </p>
            <div class="grid grid-cols-2 gap-4 pt-4">
                <div class="bg-white/15 backdrop-blur-sm rounded-2xl p-5">
                    <span class="material-icons-outlined text-white mb-2 block">trending_up</span>
                    <p class="text-white font-semibold text-sm">Гүйцэтгэл</p>
                    <p class="text-white/60 text-xs mt-1">Бодит цагийн хяналт</p>
                </div>
                <div class="bg-white/15 backdrop-blur-sm rounded-2xl p-5">
                    <span class="material-icons-outlined text-white mb-2 block">groups</span>
                    <p class="text-white font-semibold text-sm">Багийн ажиллагаа</p>
                    <p class="text-white/60 text-xs mt-1">Хамтын зорилго</p>
                </div>
            </div>
        </div>
        <div class="relative z-10">
            <p class="text-white/40 text-xs">© <?= date('Y') ?> INTRANET SYSTEM V2.0</p>
        </div>
    </div>

    <!-- БАРУУН ТАЛ -->
    <div class="flex-1 flex items-center justify-center px-8 py-12 bg-[#f5f6f8]">
        <div class="w-full max-w-md">
            <div class="mb-10">
                <h2 class="text-4xl font-bold text-slate-800 mb-2">Нэвтрэх</h2>
                <p class="text-[#f1592a] text-base">Системд нэвтрэх мэдээллээ оруулна уу.</p>
            </div>

            <?php if($error): ?>
            <div class="mb-6 flex items-center gap-3 bg-red-50 border border-red-200 text-red-600 rounded-xl px-4 py-3 text-sm">
                <span class="material-icons-outlined text-lg">error_outline</span>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Ажилтны код</label>
                    <input type="text" name="username" required autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="Таны ажилтны код"
                           class="w-full bg-white border border-slate-200 rounded-xl px-5 py-3.5 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30 focus:border-[#f1592a] transition-all shadow-sm"/>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Нууц үг</label>
                    <div class="relative">
                        <input type="password" name="password" id="passwordInput" required autocomplete="current-password"
                               placeholder="Таны нууц үг"
                               class="w-full bg-white border border-slate-200 rounded-xl px-5 py-3.5 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30 focus:border-[#f1592a] transition-all shadow-sm pr-12"/>
                        <button type="button" id="togglePass"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#f1592a] transition-colors">
                            <span class="material-icons-outlined" id="eyeIcon">visibility</span>
                        </button>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded text-[#f1592a] cursor-pointer"/>
                        <span class="text-sm text-slate-600">Намайг санах</span>
                    </label>
                    <a href="#" class="text-sm font-semibold text-[#f1592a] hover:underline">Нууц үг мартсан?</a>
                </div>
                <button type="submit"
                        class="w-full bg-[#f1592a] hover:bg-blue-700 text-white font-semibold py-4 rounded-xl transition-all duration-200 shadow-lg shadow-blue-200 text-base">
                    Нэвтрэх
                </button>
            </form>

            <p class="text-center text-sm text-slate-500 mt-8">
                Асуудал гарсан уу?
                <a href="#" class="font-semibold text-[#f1592a] hover:underline">Мэдээллийн технологийн албатай холбогдох</a>
            </p>
        </div>
    </div>
</div>
<script>
document.getElementById('togglePass').addEventListener('click', function(){
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
    if(input.type === 'password'){ input.type = 'text'; icon.textContent = 'visibility_off'; }
    else { input.type = 'password'; icon.textContent = 'visibility'; }
});
</script>
</body>
</html>
