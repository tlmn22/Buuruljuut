<?php
/** Хурлын өрөө захиалгын туслах функцууд */

require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/mailer.php';

// Календарийн ажлын цаг (захиалгыг энэ хүрээнд хийнэ)
const MR_GRID_START_HOUR = 7;
const MR_GRID_END_HOUR   = 18;   // 18:00-д дуусна

const MR_AMENITIES = [
    'projector'        => ['Проектор',      'videocam'],
    'screen'           => ['Дэлгэц',        'present_to_all'],
    'tv'               => ['Телевизор',     'tv'],
    'camera'           => ['Видео хурал',   'camera_alt'],
    'mic'              => ['Микрофон',      'mic'],
    'whiteboard'       => ['Цагаан самбар', 'draw'],
    'conference_phone' => ['Хурлын утас',   'call'],
];

const MR_RECURRENCE_LABELS = ['none' => 'Нэг удаа', 'daily' => 'Өдөр бүр', 'weekly' => '7 хоног бүр', 'monthly' => 'Сар бүр'];

const MR_STATUS = [
    'pending'   => ['Хүлээгдэж байна', 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',   'schedule'],
    'approved'  => ['Баталгаажсан',    'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',   'check_circle'],
    'rejected'  => ['Татгалзсан',      'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400',           'cancel'],
    'cancelled' => ['Цуцлагдсан',      'bg-slate-100 dark:bg-[#272c38] text-slate-500 dark:text-[#8b93a1]',     'remove_circle'],
];

/** Захиалга батлах, өрөө / хаалт удирдах эрх: хариуцагч эсвэл superadmin */
function mrIsManager(PDO $pdo, int $employeeId): bool {
    if (isSuperAdmin()) return true;
    $st = $pdo->prepare("SELECT 1 FROM meetingroom_managers WHERE employee_id = ?");
    $st->execute([$employeeId]);
    return (bool)$st->fetchColumn();
}

/** Хариуцагч томилох эрх: HR эсвэл superadmin */
function mrCanAssignManagers(): bool {
    return isHR() || isSuperAdmin();
}

/** Хариуцагчдын id жагсаалт (мэдэгдэл илгээхэд) */
function mrManagerIds(PDO $pdo): array {
    return array_map('intval', $pdo->query("SELECT mm.employee_id FROM meetingroom_managers mm
        JOIN employees e ON e.id = mm.employee_id WHERE e.is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
}

/** Өрөөний зургийн URL (зөвхөн хадгалсан файлын нэр зөвшөөрөгдөнө) */
function mrPhotoUrl(?string $photo): ?string {
    $name = basename((string)$photo);
    return ($name !== '' && preg_match('/^room_[a-f0-9]+\.(jpg|png|webp)$/', $name) && is_file(MEETINGROOM_UPLOAD_PATH . $name))
        ? MEETINGROOM_UPLOAD_URL . $name : null;
}

/**
 * Эхний удаагийн эхлэх/дуусах цаг + давтамжаас бүх удаагийн [start, end]-ийг гаргана.
 * $allDay = true бол дуусах нь дараагийн өдрийн 00:00 (хаалтад).
 */
function mrExpandRecurrence(string $startDt, string $endDt, string $recurrence, ?string $recurrenceEnd, bool $allDay = false): array {
    if ($recurrence === 'none') return [['start' => $startDt, 'end' => $endDt]];
    if (!$recurrenceEnd) throw new Exception('Давтамж дуусах огноог оруулна уу.');
    $start = new DateTime($startDt);
    $end = new DateTime($endDt);
    $until = new DateTime($recurrenceEnd . ' 23:59:59');
    if ($until <= $start) throw new Exception('Давтамж дуусах огноо эхлэх өдрөөс хойш байх ёстой.');

    $step = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$recurrence];
    $duration = $start->diff($end);
    $dates = []; $cursor = clone $start; $limit = 366;
    while ($cursor <= $until && $limit-- > 0) {
        $occEnd = (clone $cursor)->add($duration);
        $dates[] = ['start' => $cursor->format('Y-m-d H:i:s'), 'end' => $occEnd->format('Y-m-d H:i:s')];
        $cursor->modify($step);
    }
    return $dates;
}

/** "2026.10.29 (Пү) 10:00–11:30" */
function mrSlotLabel(string $start, string $end): string {
    $days = ['Ня', 'Да', 'Мя', 'Лх', 'Пү', 'Ба', 'Бя'];
    $s = strtotime($start); $e = strtotime($end);
    return date('Y.m.d', $s) . ' (' . $days[(int)date('w', $s)] . ') ' . date('H:i', $s) . '–' . date('H:i', $e);
}

/**
 * Мэдэгдэл: 🔔 + имэйл (имэйл тохиргоо байгаа бөгөөд хүлээн авагч имэйлтэй үед).
 * Имэйлийн алдаа үйлдлийг зогсоохгүй — лог руу бичнэ.
 */
function mrNotify(PDO $pdo, array $employeeIds, string $type, string $title, string $body, string $link, int $actorId): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), function ($id) use ($actorId) { return $id && $id !== $actorId; })));
    if (!$ids) return;
    notifyEmployees($pdo, $ids, $type, $title, $body, $link, $actorId);
    if (!mailConfigured()) return;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT email, last_name, first_name FROM employees WHERE id IN ($ph) AND is_active = 1 AND email LIKE '%@%'");
    $st->execute($ids);
    $url = rtrim(APP_PUBLIC_URL, '/') . $link;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1c2430;line-height:1.6">'
              . '<p style="font-size:16px;font-weight:bold;margin:0 0 10px">' . htmlspecialchars($title) . '</p>'
              . '<p style="margin:0 0 16px;white-space:pre-line">' . htmlspecialchars($body) . '</p>'
              . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#f1592a;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Харах</a></p>'
              . '<p style="color:#64748b;font-size:12px">Оффисын сүлжээнээс нээнэ үү.</p></div>';
        try {
            sendMail($r['email'], employeeName($r['last_name'], $r['first_name']), $title, $html, $title . "\n\n" . $body . "\n\n" . $url);
        } catch (Exception $e) {
            error_log('[meetingroom mail] ' . $e->getMessage());
        }
    }
}
