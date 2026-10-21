<?php
/**
 * Ерөнхий мэдэгдэл (🔔). Аль ч модуль ашиглана:
 *   notifyEmployees($pdo, [12, 15], 'kpi.approved', 'KPI батлагдлаа', null, '/modules/kpi2/sheet.php?id=3', $actorId);
 * link нь BASE_URL-ээс хойших зам (домэйн солигдсон ч ажиллана).
 */

/** Тодорхой ажилтнуудад мэдэгдэл үүсгэнэ (үйлдэл хийсэн хүнд өөрт нь илгээхгүй) */
function notifyEmployees(PDO $pdo, array $employeeIds, string $type, string $title, ?string $body, ?string $link, ?int $actorId = null): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), function ($id) use ($actorId) {
        return $id > 0 && $id !== $actorId;
    })));
    if (!$ids) return 0;
    $stmt = $pdo->prepare("INSERT INTO notifications (employee_id, type, title, body, link, actor_id) VALUES (?,?,?,?,?,?)");
    foreach ($ids as $id) $stmt->execute([$id, $type, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 500) : null, $link, $actorId]);
    return count($ids);
}

/**
 * Олон хүнд нэг дор (INSERT ... SELECT) — $employeeSql нь ганц "id" багана буцаах SELECT.
 * Жишээ: notifyBySql($pdo, "SELECT id FROM employees WHERE is_active=1", [], ...)
 */
function notifyBySql(PDO $pdo, string $employeeSql, array $params, string $type, string $title, ?string $body, ?string $link, ?int $actorId = null): int {
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (employee_id, type, title, body, link, actor_id)
         SELECT r.id, ?, ?, ?, ?, ? FROM ($employeeSql) r WHERE r.id <> COALESCE(?, 0)"
    );
    $stmt->execute(array_merge(
        [$type, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 500) : null, $link, $actorId],
        $params,
        [$actorId]
    ));
    return $stmt->rowCount();
}

function notificationUnreadCount(PDO $pdo, int $employeeId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE employee_id = ? AND read_at IS NULL");
    $stmt->execute([$employeeId]);
    return (int)$stmt->fetchColumn();
}

function notificationLatest(PDO $pdo, int $employeeId, int $limit = 15): array {
    $stmt = $pdo->prepare(
        "SELECT n.id, n.type, n.title, n.body, n.link, n.created_at, n.read_at,
                a.last_name AS actor_last, a.first_name AS actor_first, a.photo AS actor_photo
         FROM notifications n LEFT JOIN employees a ON a.id = n.actor_id
         WHERE n.employee_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT " . (int)$limit
    );
    $stmt->execute([$employeeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** "5 минутын өмнө" гэх мэт харьцангуй хугацаа */
function timeAgoMn(?string $ts): string {
    if (!$ts) return '';
    $diff = time() - strtotime($ts);
    if ($diff < 60) return 'Дөнгөж сая';
    if ($diff < 3600) return floor($diff / 60) . ' минутын өмнө';
    if ($diff < 86400) return floor($diff / 3600) . ' цагийн өмнө';
    if ($diff < 172800) return 'Өчигдөр';
    if ($diff < 604800) return floor($diff / 86400) . ' өдрийн өмнө';
    return date('Y.m.d', strtotime($ts));
}
