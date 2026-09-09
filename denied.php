<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="mn">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Хандах эрхгүй | Buuruljuut Intranet</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f6f6f8] flex items-center justify-center min-h-screen">
<div class="text-center px-6">
  <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-6">
    <svg class="w-10 h-10 text-[#f1592a]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
    </svg>
  </div>
  <h1 class="text-2xl font-bold text-slate-800 mb-2">Хандах эрх байхгүй байна</h1>
  <p class="text-slate-500 mb-6">Энэ хуудсанд хандах эрх танд олгогдоогүй байна.</p>
  <a href="<?= BASE_URL ?>/dashboard.php" class="inline-block px-6 py-3 bg-[#f1592a] text-white rounded-xl font-medium hover:bg-[#c33e12] transition-colors">
    Миний самбар руу буцах
  </a>
</div>
</body>
</html>
