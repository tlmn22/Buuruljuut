<?php
/**
 * KPI2 (туршилтын дээрээс доош урсгалтай модуль) тооцоолол.
 * Оноон томьёо kpi/functions.php-тэй яг адил (kpi_ хувилбарын хуулбар,
 * зөвхөн kpi2_ хүснэгт дээр ажилладаг), sanitize/score-option-уудыг
 * тэндээс шууд дахин ашиглана.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/modules/kpi/functions.php';

/** Нэг хэсгийн (section) дотор Өөрийн/Удирдлагын харьцааг тооцоолно (kpiSectionRatios-той адил) */
function kpi2SectionRatios(array $items): array {
    return kpiSectionRatios($items);
}

/** Тухайн үнэлгээний бүх items-ийг татаж, эцсийн 3 оноог тооцоолно */
function kpi2ComputeScores(PDO $pdo, int $evaluationId, array $period): array {
    $stmt = $pdo->prepare("SELECT section, importance_weight, difficulty_weight, self_score, manager_score FROM kpi2_items WHERE evaluation_id = ?");
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

/** kpi2_evaluations.final_* баганыг дахин тооцоолж хадгална */
function kpi2RecalculateAndSave(PDO $pdo, int $evaluationId): void {
    $eval = $pdo->prepare("SELECT e.*, p.personal_kpi_weight, p.core_duty_weight, p.special_task_weight, p.self_weight, p.manager_weight
                            FROM kpi2_evaluations e JOIN kpi2_periods p ON p.id = e.period_id WHERE e.id = ?");
    $eval->execute([$evaluationId]);
    $row = $eval->fetch();
    if (!$row) return;

    $scores = kpi2ComputeScores($pdo, $evaluationId, $row);
    $pdo->prepare("UPDATE kpi2_evaluations SET final_main_score=?, final_bonus_score=?, final_total_score=? WHERE id=?")
        ->execute([$scores['main'], $scores['bonus'], $scores['total'], $evaluationId]);
}

const KPI2_SECTION_LABELS = KPI_SECTION_LABELS;
const KPI2_STATUS_LABELS  = KPI_STATUS_LABELS;
const KPI2_FREQUENCY_LABELS = KPI_FREQUENCY_LABELS;
