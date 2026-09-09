<?php
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
