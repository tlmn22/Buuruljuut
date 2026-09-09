<?php
/** Бүх org_units-г id => мөр болгож буцаана (нэрээр нь харуулахад ашиглана) */
function regulationsOrgUnitsById(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, parent_id, name FROM org_units ORDER BY sort_order, name")->fetchAll();
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    return $byId;
}

/** regulation_id => [org_unit_id, ...] map. Энэ нь ажилтны харах эрхийг хязгаарлахгүй — зөвхөн
 *  тухайн журам аль газар/хэлтэс/албатай холбоотойг илэрхийлэх шошго; бүх ажилтан бүх журмыг харна. */
function regulationsOrgUnitMap(PDO $pdo): array {
    $rows = $pdo->query("SELECT regulation_id, org_unit_id FROM regulation_org_units")->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[(int)$r['regulation_id']][] = (int)$r['org_unit_id'];
    return $map;
}
