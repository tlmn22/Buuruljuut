<?php
$pageTitle  = 'Миний KPI';
$activePage = 'kpi';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();
include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/header.php';
$pdo = getDB();
$me  = currentUser();
$myEmployeeId = (int)$me['id'];

$periods = $pdo->query("SELECT * FROM kpi_periods WHERE is_active=1 ORDER BY start_date DESC")->fetchAll();

$mine = $pdo->prepare("SELECT ev.*, p.name as period_name, p.start_date, p.end_date
                        FROM kpi_evaluations ev JOIN kpi_periods p ON p.id = ev.period_id
                        WHERE ev.employee_id = ? ORDER BY p.start_date DESC");
$mine->execute([$myEmployeeId]);
$myEvals = $mine->fetchAll();
$myEvalByPeriod = [];
foreach ($myEvals as $e) { $myEvalByPeriod[$e['period_id']] = $e; }

$statusColors = [
    'planning'          => 'bg-slate-100 dark:bg-[#272c38] text-slate-600 dark:text-[#c9cdd6]',
    'planning_approved' => 'bg-blue-50 dark:bg-[#1c2a3d] text-[#f1592a]',
    'self_evaluated'    => 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400',
    'completed'         => 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400',
];

// ─── Идэвхтэй хугацаанууд болон миний өнгөрсөн үнэлгээнүүдийг нэг table мөр болгож нэгтгэнэ ───
$activePeriodIds = array_column($periods, 'id');
$rows = [];
foreach ($periods as $p) {
    $rows[] = ['period_id' => $p['id'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date'], 'eval' => $myEvalByPeriod[$p['id']] ?? null, 'is_active_period' => true];
}
foreach ($myEvals as $e) {
    if (in_array($e['period_id'], $activePeriodIds)) continue;
    $rows[] = ['period_id' => $e['period_id'], 'name' => $e['period_name'], 'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'eval' => $e, 'is_active_period' => false];
}
usort($rows, fn($a, $b) => strcmp($b['start_date'], $a['start_date']));
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <nav class="flex text-sm text-slate-500 dark:text-[#8b93a1] mb-2">
            <a href="<?= BASE_URL ?>/dashboard.php" class="hover:text-[#f1592a]">Нүүр</a>
            <span class="mx-2">/</span>
            <span class="text-[#f1592a] font-medium">Миний KPI</span>
        </nav>
        <h1 class="text-3xl font-bold">Миний KPI</h1>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm p-16 text-center text-slate-400 dark:text-[#5a6172]">
        <span class="material-icons-outlined block mb-3" style="font-size:48px">event_busy</span>
        Одоогоор идэвхтэй KPI үнэлгээний хугацаа байхгүй байна.
    </div>
<?php else: ?>
<div class="bg-white dark:bg-[#1c212b] rounded-xl border border-slate-200 dark:border-[#2a2f3b] shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-100 dark:border-[#2a2f3b] text-left text-xs text-slate-400 dark:text-[#8b93a1] uppercase">
                <th class="px-6 py-3 font-medium">Хугацаа</th>
                <th class="px-6 py-3 font-medium">Эхлэх — Дуусах огноо</th>
                <th class="px-6 py-3 font-medium">Төлөв</th>
                <th class="px-6 py-3 font-medium text-right">Дүн</th>
                <th class="px-6 py-3 font-medium text-right">Үйлдэл</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-[#2a2f3b]">
            <?php foreach ($rows as $r): $e = $r['eval']; ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-[#20242e] transition-colors">
                <td class="px-6 py-4 font-medium"><?= htmlspecialchars($r['name']) ?></td>
                <td class="px-6 py-4 text-slate-400 dark:text-[#8b93a1]"><?= htmlspecialchars($r['start_date']) ?> — <?= htmlspecialchars($r['end_date']) ?></td>
                <td class="px-6 py-4">
                    <?php if ($e): ?>
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?= $statusColors[$e['status']] ?>"><?= KPI_STATUS_LABELS[$e['status']] ?></span>
                    <?php else: ?>
                        <span class="text-slate-300 dark:text-[#5a6172] text-xs">—</span>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-right font-semibold <?= ($e && $e['status'] === 'completed') ? 'text-[#f1592a]' : 'text-slate-300 dark:text-[#5a6172]' ?>">
                    <?= ($e && $e['status'] === 'completed') ? number_format($e['final_total_score'] * 100, 1) . '%' : '—' ?>
                </td>
                <td class="px-6 py-4 text-right">
                    <?php if ($e): ?>
                        <a href="<?= BASE_URL ?>/modules/kpi/sheet.php?id=<?= $e['id'] ?>"
                           class="inline-block px-4 py-2 border border-slate-200 dark:border-[#2a2f3b] rounded-lg font-medium text-xs hover:bg-slate-50 dark:hover:bg-[#272c38] transition-colors">
                            Нээх
                        </a>
                    <?php elseif ($r['is_active_period']): ?>
                        <button onclick="createEval(<?= $r['period_id'] ?>)"
                           class="px-4 py-2 bg-[#f1592a] text-white rounded-lg font-medium text-xs hover:bg-[#c33e12] transition-colors">
                            Эхлүүлэх
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function createEval(periodId) {
    $.post('<?= BASE_URL ?>/modules/kpi/ajax.php', { action: 'create_evaluation', period_id: periodId }, function (r) {
        if (r.success) { window.location.href = '<?= BASE_URL ?>/modules/kpi/sheet.php?id=' + r.id; }
        else showToast(r.message, 'error');
    }, 'json');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/footer.php'; ?>
