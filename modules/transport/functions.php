<?php
/** Тээврийн захиалгын модулийн туслах функцууд. */

// "Шууд удирдлага" гэдгийг одоо байгаа KPI-ийн үнэлэгч тооцооллоор (org_units-ийн удирдагчийн шат) ашиглана
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi/evaluator_sync.php';

/** Аяллын төрөл → зорилгууд. transport_requests.travel_purpose-д зөвхөн зорилгыг хадгална (төрлийг зорилгоос тодорхойлно) */
const TRANSPORT_PURPOSE_GROUPS = [
    'Ээлжийн'     => ['14/14 Ростер', '7/7 Ростер', '5/2 ээлж', '3/2 ээлж', 'Ашиглалтын ээлж', 'Уулын ээлж'],
    'Ээлжийн бус' => ['Эвэнт', 'Томилолт', 'Зочин'],
];

/** Бүх зорилгын хавтгай жагсаалт (шалгалтад) */
function transportPurposeOptions(): array {
    return array_merge(...array_values(TRANSPORT_PURPOSE_GROUPS));
}

/** Зорилго аль төрөлд хамаарахыг буцаана (олдохгүй бол '') */
function transportPurposeGroup(?string $purpose): string {
    foreach (TRANSPORT_PURPOSE_GROUPS as $group => $purposes) {
        if (in_array($purpose, $purposes, true)) return $group;
    }
    return '';
}

/** Машины төрлийн каталог — DB биш, шууд hardcode (key нь transport_request_vehicles.vehicle_type_id-тай тохирно) */
const TRANSPORT_VEHICLE_TYPES = [
    'lc'  => ['name' => 'Ланд-200',   'full' => 'Ланд-200',    'meta' => '7 зорчигч',  'seats' => 7,  'kind' => 'suv'],
    'alp' => ['name' => 'Алпард',     'full' => 'Алпард',      'meta' => '7 зорчигч',  'seats' => 7,  'kind' => 'suv'],
    'h10' => ['name' => 'Haice-10',   'full' => 'Haice-10',    'meta' => '10 зорчигч', 'seats' => 10, 'kind' => 'bus'],
    'h14' => ['name' => 'Haice-14',   'full' => 'Haice-14',    'meta' => '14 зорчигч', 'seats' => 14, 'kind' => 'bus'],
    'cty' => ['name' => 'Каунти',     'full' => 'Каунти',      'meta' => '25 зорчигч', 'seats' => 25, 'kind' => 'bus'],
    'b45' => ['name' => '45 автобус', 'full' => '45 автобус',  'meta' => '45 зорчигч', 'seats' => 45, 'kind' => 'bus'],
];

/** Гадны түрээсийн компаниуд (transport_*_units.rental_company-д нэрээр нь хадгална) */
const TRANSPORT_RENTAL_COMPANIES = ['Сейфти приорити', 'Асем-Од'];

/** Тээвэр хариуцсан менежерийн дуусгах маягтын түлшний төрөл (key нь transport_vehicle_units.fuel_type-тай тохирно) */
const TRANSPORT_FUEL_TYPES = ['benz' => 'Бензин', 'diesel' => 'Дизель'];

const TRANSPORT_STATUS_LABELS = [
    'pending_manager'          => 'Шууд удирдлагад хүлээгдэж байна',
    'manager_rejected'         => 'Шууд удирдлага буцаасан',
    'pending_director'         => 'Тээвэр хариуцсан захиралд хүлээгдэж байна',
    'director_rejected'        => 'Захирал буцаасан',
    'pending_transport_manager' => 'Тээвэр хариуцсан менежерт хүлээгдэж байна',
    'merged'                   => 'Нэгтгэгдсэн',
    'completed'                => 'Баталгаажсан',
];

const TRANSPORT_STATUS_COLORS = [
    'pending_manager'           => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',
    'manager_rejected'          => 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400',
    'pending_director'          => 'bg-blue-50 dark:bg-[#1c2a3d] text-[#f1592a]',
    'director_rejected'         => 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400',
    'pending_transport_manager' => 'bg-violet-50 dark:bg-violet-950/30 text-violet-600 dark:text-violet-400',
    'merged'                    => 'bg-indigo-50 dark:bg-indigo-950/30 text-indigo-600 dark:text-indigo-400',
    'completed'                 => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',
];

/** Нэгтгэсэн тээврийн захиалгын (transport_orders) төлөв */
const TRANSPORT_ORDER_STATUS_LABELS = [
    'draft'             => 'Менежер бөглөж байна',
    'pending_director'  => 'Тээвэр хариуцсан захиралд хүлээгдэж байна',
    'director_rejected' => 'Захирал буцаасан — менежерт',
    'completed'         => 'Баталгаажсан',
];

const TRANSPORT_ORDER_STATUS_COLORS = [
    'draft'             => 'bg-slate-100 dark:bg-[#2a2f3b] text-slate-600 dark:text-[#c9cdd6]',
    'pending_director'  => 'bg-blue-50 dark:bg-[#1c2a3d] text-[#f1592a]',
    'director_rejected' => 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400',
    'completed'         => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',
];

/** Тухайн employee_id-тай хүн одоо ямар role-той эсэхийг шалгана (director / transport_manager) */
function transportHasRole(PDO $pdo, int $employeeId, string $role): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transport_roles WHERE employee_id=? AND role=?");
    $stmt->execute([$employeeId, $role]);
    return (bool)$stmt->fetchColumn();
}

/** Тухайн role-ийг эзэмшигч бүх ажилтны id-г буцаана */
function transportRoleHolders(PDO $pdo, string $role): array {
    $stmt = $pdo->prepare("SELECT employee_id FROM transport_roles WHERE role=?");
    $stmt->execute([$role]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Ажилтны "шууд удирдлага"-г тодорхойлно (KPI-ийн автомат үнэлэгч тооцооллыг дахин ашиглав) */
function transportResolveManager(PDO $pdo, int $employeeId): ?int {
    $stmt = $pdo->prepare("SELECT org_unit_id FROM employees WHERE id=?");
    $stmt->execute([$employeeId]);
    $orgUnitId = $stmt->fetchColumn();
    return kpiResolveAutoEvaluator($pdo, $orgUnitId ? (int)$orgUnitId : null, $employeeId);
}

/**
 * Тухайн хүн өөр ажилтны "шууд удирдлага" болж болзошгүй эсэх (sidebar-т "Батлах
 * хүсэлтүүд" харуулах эсэхийг шийднэ) — transportResolveManager()-ийн ашигладаг
 * org_units.manager_employee_id-г шалгана (KPI-ийн default_evaluator_id-с тусдаа,
 * учир нь тээврийн модуль өөр талбар ашигладаг).
 */
function transportIsAnyonesManager(PDO $pdo, int $employeeId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM org_units WHERE manager_employee_id = ?");
    $stmt->execute([$employeeId]);
    if ($stmt->fetchColumn()) return true;

    // Одоогийн org_unit бүтэц өөрчлөгдсөн ч, өмнө нь шууд удирдлагаар нь тохируулагдсан
    // захиалга байвал (батлах хүсэлтүүдийн түүхэнд харагдах учир) мөн харуулна
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transport_requests WHERE manager_id = ?");
    $stmt->execute([$employeeId]);
    return (bool)$stmt->fetchColumn();
}
