<?php
$pageTitle  = 'KPI2 — Багийн харагдац';
$activePage = 'kpi2-team';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/evaluator.php';
requireLogin();
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];
$viewAll = isHR() || isSuperAdmin();

if (!$viewAll && !kpi2IsManager($pdo, $myEmployeeId) && !kpi2IsDirector($pdo, $myEmployeeId)) {
    header('Location: ' . BASE_URL . '/denied.php');
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';

$managedUnitIds = $viewAll ? [] : kpi2ManagedOrgUnitIds($pdo, $myEmployeeId);

$periods = $pdo->query("SELECT * FROM kpi2_periods ORDER BY start_date DESC")->fetchAll();
$selectedPeriodId = intval($_GET['period_id'] ?? 0);
if (!$selectedPeriodId && $periods) {
    foreach ($periods as $p) { if ($p['is_active']) { $selectedPeriodId = (int)$p['id']; break; } }
    if (!$selectedPeriodId) $selectedPeriodId = (int)$periods[0]['id'];
}

$rows = [];
if ($selectedPeriodId) {
    $params = [$selectedPeriodId];
    $whereScope = '';
    if (!$viewAll) {
        if (!$managedUnitIds) { $whereScope = " AND 1=0"; }
        else {
            $ph = implode(',', array_fill(0, count($managedUnitIds), '?'));
            $whereScope = " AND (ev.evaluator_id = ? OR e.org_unit_id IN ($ph))";
            $params[] = $myEmployeeId;
            array_push($params, ...$managedUnitIds);
        }
    }
    $params[] = $myEmployeeId;

    $baseSql = "
        SELECT e.id as employee_id, e.employee_code, e.last_name, e.first_name, e.position, e.org_unit_id,
               ev.id as eval_id, ev.status, ev.planning_submitted_at, ev.final_total_score, ev.evaluator_id,
               ev.created_at as ev_created_at, ev.approved_at, ev.self_submitted_at, ev.manager_submitted_at
        FROM employees e
        LEFT JOIN kpi2_evaluations ev ON ev.employee_id = e.id AND ev.period_id = ?
        WHERE e.is_active = 1" . $whereScope . " AND e.id != ?
        ORDER BY e.last_name, e.first_name
    ";
    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

/**
 * DB-ийн төлөвийг загварын төлөв рүү хөрвүүлнэ:
 * none / plan / approve / self-eval / mgr-eval / done.
 * ("returned" — буцаагдсаныг DB тусад нь хадгалдаггүй тул plan-аар харагдана.)
 */
function kpi2TeamState(array $r): string {
    if (!$r['eval_id']) return 'none';
    switch ($r['status']) {
        case 'planning':          return $r['planning_submitted_at'] ? 'approve' : 'plan';
        case 'planning_approved': return 'self-eval';
        case 'self_evaluated':    return 'mgr-eval';
        case 'completed':         return 'done';
    }
    return 'none';
}

/** Одоогийн алхам хэзээ эхэлснээс хойш өнгөрсөн бүтэн хоног */
function kpi2DaysSince(?string $ts): ?int {
    if (!$ts) return null;
    $diff = (new DateTime($ts))->diff(new DateTime());
    return (int)$diff->days;
}

/**
 * Албан тушаалын удирдах түвшин — бага тоо нь жагсаалтын дээр гарна.
 * "захирлын" (жишээ нь "Жолооч /захирлын/") нь "захирал" гэсэн дэд мөр агуулдаггүй тул удирдлагад тооцогдохгүй.
 */
function kpi2PositionRank(?string $position): int {
    $p = mb_strtolower((string)$position, 'UTF-8');
    if ($p === '') return 9;
    if (mb_strpos($p, 'захирал') !== false) return mb_strpos($p, 'орлогч') !== false ? 1 : 0;
    foreach (['дарга' => 2, 'менежер' => 3, 'ахлах' => 4] as $kw => $rank) {
        if (mb_strpos($p, $kw) !== false) return $rank;
    }
    return 5;
}

/** Хэлтсийн ажилтнуудыг удирдах түвшин, дараа нь овог нэрээр эрэмбэлнэ */
function kpi2SortByPosition(array $emps): array {
    usort($emps, function ($a, $b) {
        return [kpi2PositionRank($a['position']), $a['last_name'], $a['first_name']]
           <=> [kpi2PositionRank($b['position']), $b['last_name'], $b['first_name']];
    });
    return $emps;
}

function kpi2TeamEmployee(array $r, int $idx, int $myEmployeeId): array {
    $state = kpi2TeamState($r);
    $stageStart = [
        'plan'      => $r['ev_created_at'],
        'approve'   => $r['planning_submitted_at'],
        'self-eval' => $r['approved_at'],
        'mgr-eval'  => $r['self_submitted_at'],
    ][$state] ?? null;
    $isEvaluator = $r['eval_id'] && (int)$r['evaluator_id'] === $myEmployeeId;
    $d = function ($ts) { return $ts ? substr($ts, 0, 10) : null; };
    return [
        'idx'    => $idx,
        'name'   => $r['last_name'] . '. ' . $r['first_name'],
        'id'     => $r['employee_code'],
        'position' => $r['position'],
        'lead'   => kpi2PositionRank($r['position']) <= 4,
        'state'  => $state,
        'days'   => kpi2DaysSince($stageStart),
        'mine'   => $isEvaluator && in_array($state, ['approve', 'mgr-eval'], true),
        'score'  => $state === 'done' && $r['final_total_score'] !== null ? number_format($r['final_total_score'] * 100, 1) : null,
        'closed' => $state === 'done' ? $d($r['manager_submitted_at']) : null,
        'dates'  => [$d($r['planning_submitted_at']), $d($r['approved_at']), $d($r['self_submitted_at']), $d($r['manager_submitted_at'])],
        'href'   => $r['eval_id'] ? BASE_URL . '/modules/kpi2/sheet.php?id=' . (int)$r['eval_id'] : null,
    ];
}

$employeesByUnit = [];
$unassigned = [];
foreach ($rows as $r) {
    if ($r['org_unit_id']) $employeesByUnit[(int)$r['org_unit_id']][] = $r;
    else $unassigned[] = $r;
}

$unitRows = $viewAll
    ? $pdo->query("SELECT id, parent_id, name FROM org_units ORDER BY sort_order, name")->fetchAll()
    : (function () use ($pdo, $managedUnitIds) {
        if (!$managedUnitIds) return [];
        $ph = implode(',', array_fill(0, count($managedUnitIds), '?'));
        $stmt = $pdo->prepare("SELECT id, parent_id, name FROM org_units WHERE id IN ($ph) ORDER BY sort_order, name");
        $stmt->execute($managedUnitIds);
        return $stmt->fetchAll();
    })();

$unitIncludedIds = array_column($unitRows, 'id');
$unitsByParent = [];
foreach ($unitRows as $u) {
    $parentKey = ($u['parent_id'] && in_array((int)$u['parent_id'], $unitIncludedIds, true)) ? (int)$u['parent_id'] : 0;
    $unitsByParent[$parentKey][] = $u;
}

/** Дээд нэгж бүрийн доорх ажилтантай нэгжүүдийг (өөрийг нь оруулан) мод дарааллаар хавтгайруулна */
function kpi2CollectDepts(array $unit, array $unitsByParent, array $employeesByUnit, int $depth, int $myEmployeeId, array &$out): void {
    $emps = kpi2SortByPosition($employeesByUnit[(int)$unit['id']] ?? []);
    if ($emps) {
        $list = [];
        foreach ($emps as $i => $r) $list[] = kpi2TeamEmployee($r, $i + 1, $myEmployeeId);
        $out[] = ['title' => $unit['name'], 'depth' => $depth, 'emps' => $list];
    }
    foreach ($unitsByParent[$unit['id']] ?? [] as $child) {
        kpi2CollectDepts($child, $unitsByParent, $employeesByUnit, $depth + 1, $myEmployeeId, $out);
    }
}

$cards = [];
foreach ($unitsByParent[0] ?? [] as $rootUnit) {
    $depts = [];
    kpi2CollectDepts($rootUnit, $unitsByParent, $employeesByUnit, 0, $myEmployeeId, $depts);
    if ($depts) $cards[] = ['title' => $rootUnit['name'], 'depts' => $depts];
}
if ($unassigned) {
    $list = [];
    foreach (kpi2SortByPosition($unassigned) as $i => $r) $list[] = kpi2TeamEmployee($r, $i + 1, $myEmployeeId);
    $cards[] = ['title' => 'Нэгж тодорхойгүй', 'depts' => [['title' => 'Нэгж тодорхойгүй', 'depth' => 0, 'emps' => $list]]];
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/kpi2/assets/kpi2-status.css?v=5">

<div class="kpi-root">
    <div>
        <div class="kpi-crumb">
            <a href="<?= BASE_URL ?>/dashboard.php">Нүүр</a> <span style="opacity:.6">/</span> <b>Багийн KPI2</b>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:6px;flex-wrap:wrap">
            <h1 style="margin:0;font:700 24px/30px var(--font-sans)"><?= $viewAll ? 'Бүх ажилтны KPI2' : 'Багийн KPI2' ?> <span style="font:400 12px var(--font-sans);color:var(--text-muted)">(туршилтын)</span></h1>
            <?php if ($periods): ?>
            <select class="kpi-period" aria-label="Үнэлгээний хугацаа" onchange="location.href='<?= BASE_URL ?>/modules/kpi2/team.php?period_id='+this.value">
                <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $selectedPeriodId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </div>

<?php if (!$periods): ?>
    <div class="kpi-card kpi-empty">Одоогоор KPI2 үнэлгээний хугацаа тохируулаагүй байна.</div>
<?php elseif (!$cards): ?>
    <div class="kpi-card kpi-empty"><?= $viewAll ? 'Ажилтан байхгүй байна' : 'Танд харьяалагддаг ажилтан одоогоор байхгүй байна' ?></div>
<?php else: ?>
    <div class="kpi-stats" id="kpiStats"></div>

    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
        <span id="kpiTotal" class="kpi-dept__count"></span>
        <div style="display:flex;gap:8px">
            <button type="button" class="kpi-period" data-expand="1">Бүгдийг дэлгэх</button>
            <button type="button" class="kpi-period" data-expand="0">Бүгдийг хураах</button>
        </div>
    </div>

    <div id="kpiCards" style="display:flex;flex-direction:column;gap:16px"></div>
    <div id="kpiEmpty" class="kpi-card kpi-empty" style="display:none">Энэ шүүлтүүрт тохирох ажилтан алга.</div>
<?php endif; ?>
</div>

<?php if ($cards): ?>
<script src="<?= BASE_URL ?>/modules/kpi2/assets/kpi2-status.js?v=3"></script>
<script>
(function () {
  var CARDS_DATA = <?= json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  var ICON = {
    none: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="8" stroke-dasharray="3 3"/></svg>',
    plan: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h10M4 12h7M4 17h5"/><path d="M14 19l1-3 5-5 2 2-5 5z"/></svg>',
    approve: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10M7 21h10M8 3c0 5 8 5 8 9s-8 4-8 9M16 3c0 5-8 5-8 9s8 4 8 9"/></svg>',
    eval: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 12l3-3 2 2 3-3"/></svg>',
    done: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/></svg>'
  };
  var CARDS = [["none", "none", "KPI үүсгээгүй"], ["plan", "plan", "Төлөвлөж байгаа"], ["approve", "approve", "Батлахаар хүлээгдэж байгаа"], ["eval", "self-eval", "Үнэлгээ хийгдэж байгаа"], ["done", "done", "Дууссан"]];
  var bucket = null;
  // Эвхэгдсэн/дэлгэгдсэн төлөв: "c<карт>" = газар, "c<карт>d<хэлтэс>" = хэлтэс. Анх бүгд хураагдсан.
  var open = {};
  var CHEV = '<svg class="kpi-fold__chev" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5"/></svg>';

  function countsOf(emps) {
    var c = { none: 0, plan: 0, approve: 0, eval: 0, done: 0 };
    emps.forEach(function (e) { c[KPI2.bucketOf(e.state)]++; });
    return c;
  }
  var all = [];
  CARDS_DATA.forEach(function (card) { card.depts.forEach(function (d) { all = all.concat(d.emps); }); });
  var totalCounts = countsOf(all);

  function visible(e) {
    return !bucket || KPI2.bucketOf(e.state) === bucket;
  }
  function isOpen(key) { return !!open[key]; }
  // Карт шүүлт сонгоход тохирох мөртэй газар/хэлтсийг дэлгэнэ, шүүлт арилахад бүгдийг хураана
  function openMatching() {
    open = {};
    if (!bucket) return;
    CARDS_DATA.forEach(function (card, ci) {
      card.depts.forEach(function (d, di) {
        if (d.emps.some(visible)) { open["c" + ci] = true; open["c" + ci + "d" + di] = true; }
      });
    });
  }

  function render() {
    document.getElementById("kpiStats").innerHTML = CARDS.map(function (c) {
      return '<button type="button" class="kpi-stat" data-b="' + c[0] + '" data-state="' + c[1] + '" aria-pressed="' + (bucket === c[0]) + '"><span class="kpi-stat__icon">' + ICON[c[0]] +
        '</span><span><div class="kpi-stat__value">' + totalCounts[c[0]] + '</div><div class="kpi-stat__label">' + c[2] + "</div></span></button>";
    }).join("");

    var shown = 0;
    document.getElementById("kpiCards").innerHTML = CARDS_DATA.map(function (card, ci) {
      var cardKey = "c" + ci, cardOpen = isOpen(cardKey);
      var cardEmps = [];
      var depts = card.depts.map(function (d, di) {
        var rows = d.emps.filter(visible);
        if (!rows.length) return "";
        shown += rows.length;
        cardEmps = cardEmps.concat(d.emps);
        var key = cardKey + "d" + di, deptOpen = isOpen(key), c = countsOf(d.emps);
        return '<div class="kpi-dept kpi-fold" role="button" tabindex="0" aria-expanded="' + deptOpen + '" data-fold="' + key + '" style="padding-left:' + (16 + d.depth * 20) + 'px">' +
            '<div class="kpi-dept__title">' + CHEV + KPI2.esc(d.title) + ' <span class="kpi-dept__count">' + d.emps.length + ' ажилтан</span></div>' +
            '<div class="kpi-dept__bar">' + KPI2.dist(c) + KPI2.legend(c) + '</div>' +
          '</div>' + (deptOpen ? KPI2.listHead() + rows.map(KPI2.row).join("") : "");
      }).join("");
      if (!depts) return "";
      var cc = countsOf(cardEmps);
      return '<div class="kpi-card">' +
          '<div class="kpi-card__title kpi-fold" role="button" tabindex="0" aria-expanded="' + cardOpen + '" data-fold="' + cardKey + '">' +
            '<span class="kpi-dept__title" style="font-size:15px">' + CHEV + KPI2.esc(card.title) + ' <span class="kpi-dept__count">' + cardEmps.length + ' ажилтан · ' + card.depts.length + ' нэгж</span></span>' +
            '<span class="kpi-dept__bar">' + KPI2.dist(cc) + '</span>' +
          '</div>' + (cardOpen ? depts : "") +
        '</div>';
    }).join("");

    document.getElementById("kpiEmpty").style.display = shown ? "none" : "block";
    document.getElementById("kpiTotal").innerHTML = "Нийт: <b style=\"color:var(--text)\">" + all.length + "</b> ажилтан" + (shown !== all.length ? " · шүүлтэд тохирох " + shown : "");
  }

  function toggleFold(el) {
    var key = el.dataset.fold;
    open[key] = !open[key];
    render();
  }
  var root = document.querySelector(".kpi-root");
  root.addEventListener("click", function (ev) {
    var s = ev.target.closest(".kpi-stat"); if (s) { bucket = bucket === s.dataset.b ? null : s.dataset.b; openMatching(); render(); return; }
    var f = ev.target.closest(".kpi-fold"); if (f) { toggleFold(f); return; }
    var x = ev.target.closest("[data-expand]");
    if (x) {
      open = {};
      if (x.dataset.expand === "1") CARDS_DATA.forEach(function (card, ci) { open["c" + ci] = true; card.depts.forEach(function (d, di) { open["c" + ci + "d" + di] = true; }); });
      render();
    }
  });
  root.addEventListener("keydown", function (ev) {
    var f = ev.target.closest(".kpi-fold");
    if (f && (ev.key === "Enter" || ev.key === " ")) { ev.preventDefault(); toggleFold(f); }
  });
  render();
})();
</script>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
