<?php
/**
 * Ажилтан өөрийн профайлаа (зураг, утас) засах.
 * Зургийг хөтөч дээр 400×400 болгож тайрдаг (profile.php) тул энд зөвхөн шалгаж хадгална.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? '';
$pdo = getDB();
$userId = (int)$_SESSION['user_id'];

/** Хуучин зургийг устгана (зөвхөн uploads/employees доторх файлын нэр) */
function deleteProfilePhoto(?string $filename): void {
    $name = basename((string)$filename);
    if ($name !== '' && is_file(UPLOAD_PATH . $name)) @unlink(UPLOAD_PATH . $name);
}

/** Зураг шалгаж хадгална, файлын нэрийг буцаана */
function uploadProfilePhoto(): ?string {
    if (empty($_FILES['photo']['name'])) return null;
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Зураг хуулахад алдаа гарлаа.');
    if ($file['size'] > MAX_FILE_SIZE) throw new Exception('Зураг 2MB-аас хэтэрч байна.');

    // Өргөтгөлөөр биш, агуулгаар нь шалгана
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new Exception('Зөвхөн JPG, PNG, WEBP зураг оруулна уу.');
    if ($info[0] > 4000 || $info[1] > 4000) throw new Exception('Зургийн хэмжээ хэт том байна.');

    if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
    $filename = 'emp_' . bin2hex(random_bytes(12)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $filename)) throw new Exception('Зураг хадгалахад алдаа гарлаа.');
    return $filename;
}

try {
    $old = $pdo->prepare("SELECT photo FROM employees WHERE id = ?");
    $old->execute([$userId]);
    $oldPhoto = $old->fetchColumn() ?: null;

    switch ($action) {
        case 'update_photo':
            $newPhoto = uploadProfilePhoto();
            if (!$newPhoto) throw new Exception('Зураг сонгоно уу.');
            $pdo->prepare("UPDATE employees SET photo = ? WHERE id = ?")->execute([$newPhoto, $userId]);
            deleteProfilePhoto($oldPhoto);
            echo json_encode(['success' => true, 'message' => 'Профайл зураг шинэчлэгдлээ.', 'url' => UPLOAD_URL . $newPhoto]);
            break;

        case 'remove_photo':
            $pdo->prepare("UPDATE employees SET photo = NULL WHERE id = ?")->execute([$userId]);
            deleteProfilePhoto($oldPhoto);
            echo json_encode(['success' => true, 'message' => 'Профайл зураг устгагдлаа.']);
            break;

        case 'update_phone':
            $phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
            if ($phone !== '' && !preg_match('/^\+?[0-9]{8,11}$/', $phone)) throw new Exception('Утасны дугаар буруу байна. 8-11 оронтой тоо оруулна уу.');
            $pdo->prepare("UPDATE employees SET phone = ? WHERE id = ?")->execute([$phone !== '' ? $phone : null, $userId]);
            echo json_encode(['success' => true, 'message' => 'Утасны дугаар хадгалагдлаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
