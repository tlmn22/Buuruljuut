<?php
// Нүүр хуудас нь dashboard.php — хуучин departments/divisions бүтэцтэй самбар устсан (org_units-ээр солигдсон)
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
header('Location: ' . BASE_URL . '/dashboard.php');
exit;
