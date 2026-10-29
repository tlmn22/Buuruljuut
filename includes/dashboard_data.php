<?php
/**
 * Нүүр хуудасны (dashboard.php) бодит өгөгдөл — хэсэг бүр тусдаа функц.
 * Модуль бүрийн "хийх зүйл"-ийн дүрэм тухайн модулийн өөрийн логиктой ижил байна.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi2/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi2/evaluator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/transport/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/news/functions.php';

/** org_units: id => [parent_id, name], мөн дээд түвшний (газрын) id-г олох */
function dashUnits(PDO $pdo): array {
    static $units = null;
    if ($units === null) {
        $units = [];
        foreach ($pdo->query("SELECT id, parent_id, name FROM org_units")->fetchAll(PDO::FETCH_ASSOC) as $u) $units[(int)$u['id']] = $u;
    }
    return $units;
}
function dashRootUnit(PDO $pdo, ?int $unitId): ?int {
    $units = dashUnits($pdo); $guard = 0;
    while ($unitId && !empty($units[$unitId]['parent_id']) && $guard++ < 20) $unitId = (int)$units[$unitId]['parent_id'];
    return $unitId ?: null;
}

/** "2 жил 4 сар" */
function dashTenure(?string $hireDate): ?string {
    if (!$hireDate) return null;
    $d = (new DateTime($hireDate))->diff(new DateTime('today'));
    if ($d->invert) return null;
    $parts = [];
    if ($d->y) $parts[] = $d->y . ' жил';
    if ($d->m) $parts[] = $d->m . ' сар';
    return $parts ? implode(' ', $parts) : ($d->days . ' хоног');
}

/** Монгол регистрийн дугаараас төрсөн сар, өдөр (2000 оноос хойш төрөгсдийн сар +20). Таарахгүй бол null */
function dashBirthday(?string $register): ?array {
    if (!preg_match('/^\p{Cyrillic}{2}(\d{2})(\d{2})(\d{2})\d{2}$/u', (string)$register, $m)) return null;
    $yy = (int)$m[1]; $mm = (int)$m[2]; $dd = (int)$m[3];
    $year = $mm > 20 ? 2000 + $yy : 1900 + $yy;
    if ($mm > 20) $mm -= 20;
    return checkdate($mm, $dd, $year) ? [$mm, $dd] : null;
}

/** Өнөөдрөөс хойш тухайн сар-өдөр хүртэл хэдэн хоног (0 = өнөөдөр) */
function dashDaysUntil(int $month, int $day): int {
    $today = new DateTime('today');
    $y = (int)$today->format('Y');
    if ($month === 2 && $day === 29 && !checkdate(2, 29, $y)) $day = 28;
    $next = new DateTime(sprintf('%04d-%02d-%02d', $y, $month, $day));
    if ($next < $today) $next->modify('+1 year');
    return (int)$today->diff($next)->days;
}
function dashWhenLabel(int $days): string {
    return $days === 0 ? 'Өнөөдөр' : ($days === 1 ? 'Маргааш' : $days . ' хоногийн дараа');
}

/** Миний мэдээлэл: албан тушаал, нэгж, газар, ажилласан хугацаа, шууд удирдлага */
function dashProfile(PDO $pdo, int $me): array {
    $stmt = $pdo->prepare("SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.id = ?");
    $stmt->execute([$me]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $units = dashUnits($pdo);
    $root = dashRootUnit($pdo, $p['org_unit_id'] ? (int)$p['org_unit_id'] : null);
    $p['root_id'] = $root;
    $p['root_name'] = $root && $root !== (int)$p['org_unit_id'] ? $units[$root]['name'] : null;
    $p['tenure'] = dashTenure($p['hire_date'] ?? null);

    // Шууд удирдлага: HR-ийн үнэлэгч (default_evaluator_id), эс бөгөөс нэгжийн удирдагч
    $managerId = $p['default_evaluator_id'] ?? null;
    if (!$managerId && $p['org_unit_id']) {
        $m = $pdo->prepare("SELECT manager_employee_id FROM org_units WHERE id = ?");
        $m->execute([$p['org_unit_id']]);
        $managerId = $m->fetchColumn() ?: null;
    }
    $p['manager'] = null;
    if ($managerId && (int)$managerId !== $me) {
        $m = $pdo->prepare("SELECT id, last_name, first_name, position, phone, email, photo FROM employees WHERE id = ? AND is_active = 1");
        $m->execute([$managerId]);
        $p['manager'] = $m->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $t = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE org_unit_id = ? AND is_active = 1 AND id <> ?");
    $t->execute([$p['org_unit_id'] ?? 0, $me]);
    $p['team_count'] = (int)$t->fetchColumn();
    return $p;
}

/** Миний KPI: идэвхтэй хугацааны хуудас (төлөв, хэнээс хүлээж буй) + сүүлийн дууссан оноо */
function dashMyKpi(PDO $pdo, int $me): array {
    $cur = $pdo->prepare("
        SELECT ev.id AS eval_id, ev.status, ev.planning_submitted_at, p.name AS period_name, p.id AS period_id,
               ev2.last_name AS ev_last, ev2.first_name AS ev_first
        FROM kpi2_periods p
        LEFT JOIN kpi2_evaluations ev ON ev.period_id = p.id AND ev.employee_id = ?
        LEFT JOIN employees ev2 ON ev2.id = ev.evaluator_id
        WHERE p.is_active = 1 ORDER BY (ev.id IS NOT NULL) DESC, p.start_date DESC LIMIT 1"); // хуудастай хугацааг түрүүлж
    $cur->execute([$me]);
    $current = $cur->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($current) $current['state'] = kpi2TeamState($current);

    $last = $pdo->prepare("SELECT ev.final_total_score, p.name FROM kpi2_evaluations ev JOIN kpi2_periods p ON p.id = ev.period_id
                           WHERE ev.employee_id = ? AND ev.status = 'completed' ORDER BY p.end_date DESC LIMIT 1");
    $last->execute([$me]);
    return ['current' => $current, 'last' => $last->fetch(PDO::FETCH_ASSOC) ?: null];
}

/**
 * "Таны хийх зүйлс" — үүрэг бүрээс хамаарсан шаардлагатай үйлдлүүд.
 * Буцаах: [['icon','text','sub','link','tone' => 'action'|'info'], ...]
 */
function dashTodos(PDO $pdo, int $me, array $kpi, ?string $employeeCode): array {
    $todos = [];
    $add = function ($icon, $text, $sub, $link, $tone = 'action') use (&$todos) { $todos[] = compact('icon', 'text', 'sub', 'link', 'tone'); };
    $one = function ($sql, $params = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); };

    // ── KPI: өөрийн хуудас ──
    $cur = $kpi['current'];
    if ($cur && $cur['eval_id']) {
        $link = BASE_URL . '/modules/kpi2/sheet.php?id=' . (int)$cur['eval_id'];
        if ($cur['state'] === 'plan') $add('edit_note', 'KPI төлөвлөгөөгөө бөглөж илгээх', $cur['period_name'], $link);
        if ($cur['state'] === 'self-eval') $add('rate_review', 'KPI-н өөрийн үнэлгээгээ хийх', $cur['period_name'], $link);
    }
    // ── KPI: үнэлэгчийн хувьд ──
    $n = $one("SELECT COUNT(*) FROM kpi2_evaluations ev JOIN kpi2_periods p ON p.id = ev.period_id AND p.is_active = 1
               WHERE ev.evaluator_id = ? AND ev.status = 'planning' AND ev.planning_submitted_at IS NOT NULL", [$me]);
    if ($n) $add('fact_check', "$n ажилтны KPI төлөвлөгөө батлах", 'Шууд удирдлагын хувьд', BASE_URL . '/modules/kpi2/team.php');
    $n = $one("SELECT COUNT(*) FROM kpi2_evaluations ev JOIN kpi2_periods p ON p.id = ev.period_id AND p.is_active = 1
               WHERE ev.evaluator_id = ? AND ev.status = 'self_evaluated'", [$me]);
    if ($n) $add('grading', "$n ажилтны KPI үнэлэх", 'Ажилтан өөрийн үнэлгээгээ илгээсэн', BASE_URL . '/modules/kpi2/team.php');
    // ── KPI: хэлтсийн менежер — ажлын багц илгээгээгүй ──
    $s = $pdo->prepare("SELECT ou.name, p.name AS period_name FROM kpi2_unit_roles r
                        JOIN org_units ou ON ou.id = r.org_unit_id JOIN kpi2_periods p ON p.is_active = 1
                        LEFT JOIN kpi2_task_batches b ON b.org_unit_id = r.org_unit_id AND b.period_id = p.id
                        WHERE r.employee_id = ? AND r.role = 'manager' AND (b.id IS NULL OR b.status IN ('draft','rejected'))");
    $s->execute([$me]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $add('edit_calendar', 'Хэлтсийн ажлын төлөвлөгөө батлуулахаар илгээх', $row['name'] . ' · ' . $row['period_name'], BASE_URL . '/modules/kpi2/manager.php');
    // ── KPI: захирал — батлах ажлын багц (батлагч нь яг би бол) ──
    $pending = 0;
    foreach ($pdo->query("SELECT org_unit_id FROM kpi2_task_batches WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN) as $unitId) {
        if (kpi2ResolveApprover($pdo, (int)$unitId) === $me) $pending++;
    }
    if ($pending) $add('task_alt', "$pending хэлтсийн ажлын төлөвлөгөө батлах", 'Газрын захирлын хувьд', BASE_URL . '/modules/kpi2/approvals.php');

    // ── Дүрэм журам: танилцах шаардлагатай, уншаагүй ──
    $n = $one("SELECT COUNT(*) FROM regulations r WHERE r.is_active = 1 AND r.require_ack = 1
               AND NOT EXISTS (SELECT 1 FROM regulation_reads rr WHERE rr.regulation_id = r.id AND rr.employee_id = ?)", [$me]);
    if ($n) $add('menu_book', "$n журамтай танилцах", 'Танилцсан гэж бүртгүүлэх шаардлагатай', BASE_URL . '/modules/regulations/index.php');

    // ── Тээвэр: батлах хүсэлтүүд ──
    $n = $one("SELECT COUNT(*) FROM transport_requests WHERE status = 'pending_manager' AND manager_id = ?", [$me]);
    if ($n) $add('local_shipping', "$n тээврийн хүсэлт батлах", 'Шууд удирдлагын хувьд', BASE_URL . '/modules/transport/approvals.php');
    if (transportHasRole($pdo, $me, 'director')) {
        $n = $one("SELECT COUNT(*) FROM transport_requests WHERE status = 'pending_director'")
           + $one("SELECT COUNT(*) FROM transport_orders WHERE status = 'pending_director'");
        if ($n) $add('local_shipping', "$n тээврийн хүсэлт / захиалга батлах", 'Тээвэр хариуцсан захирлын хувьд', BASE_URL . '/modules/transport/approvals.php');
    }
    if (transportHasRole($pdo, $me, 'transport_manager')) {
        $n = $one("SELECT COUNT(*) FROM transport_requests WHERE status = 'pending_transport_manager' AND order_id IS NULL");
        if ($n) $add('merge_type', "$n тээврийн хүсэлт нэгтгэж захиалга үүсгэх", 'Тээвэр хариуцсан менежерийн хувьд', BASE_URL . '/modules/transport/merge.php');
    }

    // ── Хурлын өрөө: хариуцагч — батлах захиалга; бүгд — өнөөдрийн миний хурал ──
    $isMrManager = isSuperAdmin() || $one("SELECT COUNT(*) FROM meetingroom_managers WHERE employee_id = ?", [$me]);
    if ($isMrManager) {
        $n = $one("SELECT COUNT(DISTINCT COALESCE(recurrence_group, id)) FROM meetingroom_bookings WHERE status = 'pending' AND start_dt >= NOW()");
        if ($n) $add('meeting_room', "$n хурлын өрөөний захиалга батлах", 'Хурлын өрөөний хариуцагчийн хувьд', BASE_URL . '/modules/meetingroom/admin.php');
    }
    $s = $pdo->prepare("SELECT b.start_dt, b.end_dt, b.title, b.room_id, r.name FROM meetingroom_bookings b JOIN meetingroom_rooms r ON r.id = b.room_id
                        WHERE b.requested_by = ? AND b.status = 'approved' AND DATE(b.start_dt) = CURDATE() AND b.end_dt >= NOW() ORDER BY b.start_dt");
    $s->execute([$me]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $add('event', 'Өнөөдөр ' . date('H:i', strtotime($m['start_dt'])) . '-д хурал: ' . ($m['title'] ?: $m['name']),
             $m['name'] . ' · ' . date('H:i', strtotime($m['start_dt'])) . '–' . date('H:i', strtotime($m['end_dt'])),
             BASE_URL . '/modules/meetingroom/index.php?room=' . (int)$m['room_id'], 'info');
    }

    // ── OneFit: бүртгэл нээлттэй, бүртгүүлээгүй ──
    $period = $pdo->query("SELECT * FROM onefitoid WHERE Status = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($period && $employeeCode) {
        $reg = $one("SELECT COUNT(*) FROM onefit WHERE ofId = ? AND EmployeeNumber = ?", [$period['id'], $employeeCode]);
        if (!$reg) $add('fitness_center', 'OneFit бүртгэл нээлттэй байна', $period['Name'] . ($period['EndDate'] ? ' · ' . $period['EndDate'] . ' хүртэл' : ''), BASE_URL . '/modules/onefit/index.php', 'info');
    }
    return $todos;
}

/** Уншаагүй мэдээний тоо */
function dashUnreadNews(PDO $pdo, int $me, ?int $orgUnitId): int {
    [$where, $params] = newsVisibilityWhere($pdo, $me, $orgUnitId);
    $s = $pdo->prepare("SELECT COUNT(*) FROM news_posts p WHERE $where AND p.status = 'published' AND p.published_at <= NOW()
                        AND NOT EXISTS (SELECT 1 FROM news_reads r WHERE r.post_id = p.id AND r.employee_id = ?)");
    $s->execute(array_merge($params, [$me]));
    return (int)$s->fetchColumn();
}

/** Миний сүүлийн тээврийн хүсэлтүүд */
function dashMyTransport(PDO $pdo, int $me): array {
    $s = $pdo->prepare("SELECT r.id, r.travel_direction, r.start_date, r.end_date, r.status, o.status AS order_status, o.depart_at, o.depart_location
                        FROM transport_requests r LEFT JOIN transport_orders o ON o.id = r.order_id
                        WHERE r.requester_id = ? ORDER BY r.created_at DESC LIMIT 3");
    $s->execute([$me]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// Нүүр хуудсанд харуулах хугацаа (өнөөдрийг оролцуулан, хоногоор)
const DASH_BIRTHDAY_DAYS    = 5; // өнөөдөр + дараагийн 4 өдөр
const DASH_ANNIVERSARY_DAYS = 2; // өнөөдөр + маргааш
const DASH_NEWCOMER_DAYS    = 7; // сүүлийн 7 хоногт ажилд орсон

/** Төрсөн өдөр, ажлын ой (ойртож буй), шинэ ажилтнууд (саяхан орсон). Миний газрынхан эхэнд. */
function dashCelebrations(PDO $pdo, ?int $myRoot, int $me): array {
    $units = dashUnits($pdo);
    $birthdays = []; $anniversaries = []; $newcomers = [];
    $today = new DateTime('today');
    foreach ($pdo->query("SELECT id, last_name, first_name, position, org_unit_id, register_number, hire_date, photo FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $root = dashRootUnit($pdo, $e['org_unit_id'] ? (int)$e['org_unit_id'] : null);
        $base = [
            'id' => (int)$e['id'], 'name' => employeeName($e['last_name'], $e['first_name']), 'photo' => $e['photo'],
            'unit' => $e['org_unit_id'] ? ($units[(int)$e['org_unit_id']]['name'] ?? '') : '', 'mine' => $myRoot && $root === $myRoot,
        ];
        if ($bd = dashBirthday($e['register_number'])) {
            $days = dashDaysUntil($bd[0], $bd[1]);
            if ($days < DASH_BIRTHDAY_DAYS) $birthdays[] = $base + ['days' => $days, 'date' => sprintf('%02d.%02d', $bd[0], $bd[1])];
        }
        if ($e['hire_date']) {
            $hire = new DateTime($e['hire_date']);
            $days = dashDaysUntil((int)$hire->format('n'), (int)$hire->format('j'));
            $years = (int)$hire->diff((clone $today)->modify("+$days days"))->y; // ойн өдөр хэдэн жил болох
            if ($days < DASH_ANNIVERSARY_DAYS && $years >= 1) $anniversaries[] = $base + ['days' => $days, 'years' => $years];
            if ($hire <= $today && $hire->diff($today)->days < DASH_NEWCOMER_DAYS) $newcomers[] = $base + ['position' => $e['position'], 'hire' => $e['hire_date']];
        }
    }
    $sort = function ($a, $b) { return [!$a['mine'], $a['days'], $a['name']] <=> [!$b['mine'], $b['days'], $b['name']]; };
    usort($birthdays, $sort); usort($anniversaries, $sort);
    usort($newcomers, function ($a, $b) { return [!$a['mine'], $b['hire']] <=> [!$b['mine'], $a['hire']]; });
    return compact('birthdays', 'anniversaries', 'newcomers');
}

/** HR / superadmin: компанийн товч тойм */
function dashHrOverview(PDO $pdo): array {
    $units = dashUnits($pdo);
    $byRoot = [];
    foreach ($pdo->query("SELECT org_unit_id FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $root = dashRootUnit($pdo, $u ? (int)$u : null);
        $name = $root ? $units[$root]['name'] : 'Нэгжгүй';
        $byRoot[$name] = ($byRoot[$name] ?? 0) + 1;
    }
    arsort($byRoot);
    $q = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
    return [
        'headcount'   => array_sum($byRoot),
        'by_root'     => $byRoot,
        'new_month'   => $q("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND hire_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'logins'      => $q("SELECT COUNT(*) FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.is_active = 1 AND e.is_active = 1"),
        'activated'   => $q("SELECT COUNT(*) FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.is_active = 1 AND e.is_active = 1 AND u.must_change_password = 0 AND u.last_login_at IS NOT NULL"),
    ];
}
