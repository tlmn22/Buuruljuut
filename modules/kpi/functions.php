<?php
/**
 * KPI модулийн тооцоолол болон эрхийн нийтлэг функцүүд.
 * Оноон томьёо (kpi template.xlsx-ээс гаргаж авсан):
 *
 *   Хэсгийн харьцаа = Σ(Ач холбогдол × Хүндрэл × Үнэлгээ) / Σ(Ач холбогдол × Хүндрэл × 3)
 *
 *   Үндсэн оноо = (personal_kpi харьцаа × personal_kpi_weight + core_duty харьцаа × core_duty_weight)
 *                 -г Өөрийн/Удирдлагын гэж тус тусад нь бодоод,
 *                 эцэст нь Өөрийн × self_weight + Удирдлагын × manager_weight
 *
 *   Бонус оноо = special_task харьцаа (Өөрийн × self_weight + Удирдлагын × manager_weight) × special_task_weight
 *
 *   Эцсийн нийт = Үндсэн + Бонус (100%-иас давж болно)
 */

/** Нэг хэсгийн (section) дотор Өөрийн/Удирдлагын харьцааг тооцоолно */
function kpiSectionRatios(array $items): array {
    $maxPossible = 0.0;
    $selfAchieved = 0.0;
    $mgmtAchieved = 0.0;

    foreach ($items as $it) {
        $weight = (int)$it['importance_weight'] * (int)$it['difficulty_weight'];
        $maxPossible += $weight * 3;
        if ($it['self_score'] !== null) $selfAchieved += $weight * (float)$it['self_score'];
        if ($it['manager_score'] !== null) $mgmtAchieved += $weight * (float)$it['manager_score'];
    }

    return [
        'self' => $maxPossible > 0 ? $selfAchieved / $maxPossible : 0.0,
        'manager' => $maxPossible > 0 ? $mgmtAchieved / $maxPossible : 0.0,
    ];
}

/**
 * Тухайн үнэлгээний бүх items-ийг татаж, эцсийн 3 оноог (үндсэн/бонус/нийт) тооцоолно.
 * Буцаана: ['main'=>float,'bonus'=>float,'total'=>float] (0-1 масштабтай, харуулахдаа ×100)
 */
function kpiComputeScores(PDO $pdo, int $evaluationId, array $period): array {
    $stmt = $pdo->prepare("SELECT section, importance_weight, difficulty_weight, self_score, manager_score FROM kpi_items WHERE evaluation_id = ?");
    $stmt->execute([$evaluationId]);
    $items = $stmt->fetchAll();

    $bySection = ['personal_kpi' => [], 'core_duty' => [], 'special_task' => []];
    foreach ($items as $it) {
        $bySection[$it['section']][] = $it;
    }

    $personalRatios = kpiSectionRatios($bySection['personal_kpi']);
    $coreRatios     = kpiSectionRatios($bySection['core_duty']);
    $specialRatios  = kpiSectionRatios($bySection['special_task']);

    $mainSelf = $personalRatios['self'] * (float)$period['personal_kpi_weight'] + $coreRatios['self'] * (float)$period['core_duty_weight'];
    $mainMgmt = $personalRatios['manager'] * (float)$period['personal_kpi_weight'] + $coreRatios['manager'] * (float)$period['core_duty_weight'];
    $mainScore = $mainSelf * (float)$period['self_weight'] + $mainMgmt * (float)$period['manager_weight'];

    $bonusBlend = $specialRatios['self'] * (float)$period['self_weight'] + $specialRatios['manager'] * (float)$period['manager_weight'];
    $bonusScore = $bonusBlend * (float)$period['special_task_weight'];

    return [
        'main'  => round($mainScore, 4),
        'bonus' => round($bonusScore, 4),
        'total' => round($mainScore + $bonusScore, 4),
    ];
}

/** kpi_evaluations.final_* баганыг дахин тооцоолж хадгална */
function kpiRecalculateAndSave(PDO $pdo, int $evaluationId): void {
    $eval = $pdo->prepare("SELECT e.*, p.personal_kpi_weight, p.core_duty_weight, p.special_task_weight, p.self_weight, p.manager_weight
                            FROM kpi_evaluations e JOIN kpi_periods p ON p.id = e.period_id WHERE e.id = ?");
    $eval->execute([$evaluationId]);
    $row = $eval->fetch();
    if (!$row) return;

    $scores = kpiComputeScores($pdo, $evaluationId, $row);
    $pdo->prepare("UPDATE kpi_evaluations SET final_main_score=?, final_bonus_score=?, final_total_score=? WHERE id=?")
        ->execute([$scores['main'], $scores['bonus'], $scores['total'], $evaluationId]);
}

/** Rubric-ийн боломжит оноонууд (0-3, 0.5 нарийвчлалтай) */
function kpiScoreOptions(): array {
    return [0, 0.5, 1, 1.5, 2, 2.5, 3];
}

const KPI_SECTION_LABELS = [
    'personal_kpi'  => 'Хувь хүний KPI',
    'core_duty'     => 'Үндсэн чиг үүргийн ажил',
    'special_task'  => 'Нэмэлт/Тусгай ажил',
];

const KPI_STATUS_LABELS = [
    'planning'          => 'Төлөвлөж байна',
    'planning_approved' => 'Батлагдсан',
    'self_evaluated'    => 'Өөрийн үнэлгээ орсон',
    'completed'         => 'Дууссан',
];

const KPI_FREQUENCY_LABELS = [
    'day'     => 'Өдөр',
    'week'    => 'Долоо хоног',
    'month'   => 'Сар',
    'quarter' => 'Улирал',
];

/**
 * "KPI-ийн зорилт" / "Гүйцэтгэлийн тайлбар" талбарууд rich-text editor (Quill)-аас
 * HTML хэлбэрээр ирдэг тул хадгалахаас өмнө заавал цэвэрлэнэ (stored XSS-ээс сэргийлнэ).
 * Зөвшөөрөгдсөн tag-аас бусдыг бүрмөсөн хасаж, бүх attribute-ийг цэвэрлэнэ
 * (href/src/onXXX зэрэг халдлагын векторыг таслах зорилготой).
 */
function kpiSanitizeHtml(?string $html): ?string {
    $html = trim((string)$html);
    if ($html === '') return null;

    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div>' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
    );
    libxml_clear_errors();

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return null;

    kpiSanitizeNode($doc, $root, $allowedTags);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    $out = trim($out);
    return $out === '' ? null : $out;
}

/** kpiSanitizeHtml-ийн дотоод тусламж функц: зөвшөөрөгдөөгүй tag-ийг unwrap хийж, attribute-ийг устгана */
function kpiSanitizeNode(DOMDocument $doc, DOMNode $node, array $allowedTags): void {
    $children = iterator_to_array($node->childNodes);
    foreach ($children as $child) {
        if ($child instanceof DOMElement) {
            kpiSanitizeNode($doc, $child, $allowedTags);

            if (in_array(strtolower($child->tagName), $allowedTags, true)) {
                foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                    $child->removeAttribute($attr->nodeName);
                }
            } else {
                // Зөвшөөрөгдөөгүй tag-ийг устгаад, дотоод агуулгыг эцэг рүү нь гаргана (script/style бол агуулгыг ч хаяна)
                if (in_array(strtolower($child->tagName), ['script', 'style'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
            }
        }
    }
}
