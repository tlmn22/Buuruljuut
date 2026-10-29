<?php
// PHP-ийн цагийг MySQL-ийн (Asia/Ulaanbaatar, +08:00) цагтай тааруулна — date()/time() ба NOW() ижил байх ёстой
date_default_timezone_set('Asia/Ulaanbaatar');

define('APP_NAME', 'KPI Систем');
define('APP_VERSION', '1.0');

// Одоогийн хүсэлтийн протокол (http/https) болон domain-аас BASE_URL-ийг автоматаар тодорхойлно —
// ингэснээр локал (localhost) дээр ч, серверт байршуулсны дараа ямар ч domain дээр ч
// дахин гараар засах шаардлагагүйгээр зөв ажиллана. Аппликейшн үргэлж document root-ийн
// шууд дор "buuruljuut" хавтаст байрладаг гэдэг таамаглал хэвээр (бүх require зам үүнд тулгуурладаг).
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443);
$scheme = $isHttps ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $scheme . '://' . $host . '/buuruljuut');
define('UPLOAD_PATH', __DIR__ . '/../uploads/employees/');
define('UPLOAD_URL', BASE_URL . '/uploads/employees/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

define('REGULATIONS_UPLOAD_PATH', __DIR__ . '/../uploads/regulations/');
define('REGULATIONS_UPLOAD_URL', BASE_URL . '/uploads/regulations/');
define('REGULATIONS_MAX_FILE_SIZE', 15 * 1024 * 1024); // 15MB

define('NEWS_UPLOAD_PATH', __DIR__ . '/../uploads/news/');
define('NEWS_UPLOAD_URL', BASE_URL . '/uploads/news/');
define('NEWS_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

/** Ажилтны нэрийг дэлгэцэнд харуулах нэгдсэн хэлбэр: овгийн эхний үсэг + цэг + нэр ("Б. Болд") */
function employeeName(?string $lastName, ?string $firstName): string {
    $last = trim((string)$lastName);
    $first = trim((string)$firstName);
    if ($last === '') return $first;
    return mb_strtoupper(mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8') . '. ' . $first;
}

define('MEETINGROOM_UPLOAD_PATH', __DIR__ . '/../uploads/meetingroom/');
define('MEETINGROOM_UPLOAD_URL', BASE_URL . '/uploads/meetingroom/');
