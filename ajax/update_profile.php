<?php
/**
 * =====================================================
 * PROFILE UPDATE AJAX HANDLER
 * =====================================================
 * Ажилчин өөрийн профайл мэдээллээ (зураг, утас) засах
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';

header('Content-Type: application/json');

// Нэвтэрсэн эсэхийг шалгах
requireLogin();

$action = $_POST['action'] ?? '';
$pdo = getDB();
$userId = $_SESSION['user_id'];

/**
 * Зураг upload хийх функц
 */
function uploadProfilePhoto() {
    if(empty($_FILES['photo']['name'])) {
        return null;
    }

    $file = $_FILES['photo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if(!in_array($ext, $allowed)) {
        throw new Exception('Зурагны төрөл буруу байна. JPG, PNG, WEBP зөвшөөрөгдөнө.');
    }

    if($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('Зураг 2MB-аас хэтэрч байна.');
    }

    // Upload folder үүсгэх
    if(!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }

    $filename = uniqid('emp_', true) . '.' . $ext;
    
    if(!move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $filename)) {
        throw new Exception('Зураг хадгалахад алдаа гарлаа.');
    }

    return $filename;
}

try {
    switch($action) {
        
        case 'update_profile':
            $phone = trim($_POST['phone'] ?? '');
            
            // Validation
            if($phone && !preg_match('/^[0-9]{8,11}$/', $phone)) {
                throw new Exception('Утасны дугаар буруу байна. 8-11 оронтой тоо байх ёстой.');
            }
            
            // Зураг upload
            $newPhoto = uploadProfilePhoto();
            
            // Хуучин зураг устгах
            if($newPhoto) {
                $oldPhotoStmt = $pdo->prepare("SELECT photo FROM employees WHERE id = ?");
                $oldPhotoStmt->execute([$userId]);
                $oldPhoto = $oldPhotoStmt->fetchColumn();
                
                if($oldPhoto && file_exists(UPLOAD_PATH . $oldPhoto)) {
                    unlink(UPLOAD_PATH . $oldPhoto);
                }
                
                // Шинэ зураг хадгалах
                $photoStmt = $pdo->prepare("UPDATE employees SET photo = ? WHERE id = ?");
                $photoStmt->execute([$newPhoto, $userId]);
            }
            
            // Утас шинэчлэх
            if($phone !== '') {
                $phoneStmt = $pdo->prepare("UPDATE employees SET phone = ? WHERE id = ?");
                $phoneStmt->execute([$phone, $userId]);
            }
            
            // Хариу буцаах
            $message = [];
            if($newPhoto) $message[] = 'Зураг';
            if($phone !== '') $message[] = 'Утас';
            
            $successMsg = count($message) > 0 
                ? implode(' болон ', $message) . ' амжилттай шинэчлэгдлээ!'
                : 'Мэдээлэл шинэчлэгдсэнгүй.';
            
            echo json_encode([
                'success' => true,
                'message' => $successMsg
            ]);
            break;
            
        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
