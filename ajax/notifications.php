<?php
/** 🔔 Мэдэгдэл: жагсаалт / уншсан болгох. Толгой хэсгийн хонх 60 секунд тутам дуудна. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/news/functions.php';
header('Content-Type: application/json');
requireLogin();

$pdo = getDB();
$me  = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            // Хуваарьт нийтлэлийн цаг болсон бол мэдэгдлийг нь одоо илгээнэ (cron-гүй)
            try { newsDispatchDue($pdo); } catch (Throwable $e) { error_log('[news dispatch] ' . $e->getMessage()); }
            $items = array_map(function ($n) {
                return [
                    'id'     => (int)$n['id'],
                    'type'   => $n['type'],
                    'title'  => $n['title'],
                    'body'   => $n['body'],
                    'link'   => $n['link'] ? BASE_URL . $n['link'] : null,
                    'actor'  => $n['actor_last'] ? employeeName($n['actor_last'], $n['actor_first']) : null,
                    'time'   => timeAgoMn($n['created_at']),
                    'unread' => $n['read_at'] === null,
                ];
            }, notificationLatest($pdo, $me, 15));
            echo json_encode(['success' => true, 'unread' => notificationUnreadCount($pdo, $me), 'items' => $items]);
            break;

        case 'read':
            $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND employee_id = ? AND read_at IS NULL")
                ->execute([intval($_POST['id'] ?? 0), $me]);
            echo json_encode(['success' => true]);
            break;

        case 'read_all':
            $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE employee_id = ? AND read_at IS NULL")->execute([$me]);
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
