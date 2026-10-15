<?php
/** Нэвтрээгүй үеийн хуудасны (нууц үг мартсан / сэргээх) энгийн layout — login.php-ийн баруун талын загвартай */

function authPageStart(string $title, string $subtitle): void { ?>
<!DOCTYPE html>
<html lang="mn">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="referrer" content="no-referrer"/>
<title><?= htmlspecialchars($title) ?> | Buuruljuut Intranet</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet"/>
<style>* { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-[#f5f6f8]">
<div class="min-h-screen flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="mb-8">
            <div class="w-12 h-12 bg-[#f1592a] rounded-2xl flex items-center justify-center mb-6">
                <span class="material-icons-outlined text-white">lock_reset</span>
            </div>
            <h2 class="text-3xl font-bold text-slate-800 mb-2"><?= htmlspecialchars($title) ?></h2>
            <p class="text-slate-500 text-sm"><?= htmlspecialchars($subtitle) ?></p>
        </div>
<?php }

function authAlert(string $type, string $message): void {
    $styles = [
        'error'   => ['bg-red-50 border-red-200 text-red-600', 'error_outline'],
        'success' => ['bg-green-50 border-green-200 text-green-700', 'check_circle'],
        'info'    => ['bg-amber-50 border-amber-200 text-amber-700', 'info'],
    ][$type]; ?>
        <div class="mb-6 flex items-start gap-3 border rounded-xl px-4 py-3 text-sm <?= $styles[0] ?>">
            <span class="material-icons-outlined text-lg"><?= $styles[1] ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
<?php }

function authPageEnd(): void { ?>
        <p class="text-center text-sm text-slate-500 mt-8">
            <a href="<?= BASE_URL ?>/login.php" class="font-semibold text-[#f1592a] hover:underline">← Нэвтрэх хуудас руу буцах</a>
        </p>
    </div>
</div>
</body>
</html>
<?php }

const AUTH_INPUT_CLS = 'w-full bg-white border border-slate-200 rounded-xl px-5 py-3.5 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#f1592a]/30 focus:border-[#f1592a] transition-all shadow-sm';
const AUTH_BUTTON_CLS = 'w-full bg-[#f1592a] hover:bg-[#c33e12] text-white font-semibold py-3.5 rounded-xl transition-all duration-200 text-base';
