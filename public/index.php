<?php
require_once __DIR__ . '/../app/services/PredictionService.php';
require_login();

$user = current_user();
$allowedDatasets = ['B-dataset', 'C-dataset', 'F-dataset'];
$dataset   = $_REQUEST['dataset'] ?? 'C-dataset';
$dataset   = in_array($dataset, $allowedDatasets, true) ? $dataset : 'C-dataset';
$topK         = max(1, min(20, (int) ($_POST['top_k'] ?? 10)));
$drugInputs   = normalize_request_values($_POST['drug_input'] ?? '');
$diseaseInputs = normalize_request_values($_POST['disease_input'] ?? '');
$drugInput    = $drugInputs[0] ?? '';
$diseaseInput = $diseaseInputs[0] ?? '';
// Primary direction + optional fallback when both fields are filled
if ($drugInput !== '') {
    $queryType     = 'drug_to_disease';
    $inputText     = $drugInput;
    $fallbackType  = ($diseaseInput !== '') ? 'disease_to_drug' : null;
    $fallbackText  = $diseaseInput;
} elseif ($diseaseInput !== '') {
    $queryType     = 'disease_to_drug';
    $inputText     = $diseaseInput;
    $fallbackType  = null;
    $fallbackText  = '';
} else {
    $queryType     = 'drug_to_disease';
    $inputText     = '';
    $fallbackType  = null;
    $fallbackText  = '';
}
$resultData = null;
$resultGroups = [];
$pairMatrixData = null;
$error      = null;
$apiHealthy = PredictionService::isApiHealthy();

if (empty($_SESSION['_csrf_predict'])) {
    $_SESSION['_csrf_predict'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['_csrf_predict'];

function format_score(mixed $score): string
{
    return number_format((float) $score, 4);
}

function format_delta_score(mixed $score): string
{
    if ($score === null || $score === '') {
        return '—';
    }

    $value = (float) $score;
    return ($value > 0 ? '+' : '') . number_format($value, 4);
}

function normalize_request_values(mixed $raw): array
{
    $values = is_array($raw) ? $raw : (($raw === null || $raw === '') ? [] : [$raw]);
    $normalized = [];

    foreach ($values as $value) {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }

        $key = strtolower($trimmed);
        if (!isset($normalized[$key])) {
            $normalized[$key] = $trimmed;
        }
    }

    return array_values($normalized);
}

function unique_entity_options(array $items): array
{
    $unique = [];

    foreach ($items as $item) {
        $resolvedId = trim((string) ($item['id'] ?? ''));
        $resolvedName = trim((string) ($item['name'] ?? $resolvedId));
        if ($resolvedId === '' && $resolvedName === '') {
            continue;
        }

        $key = strtolower($resolvedId !== '' ? $resolvedId : $resolvedName);
        if (isset($unique[$key])) {
            continue;
        }

        $unique[$key] = [
            'id' => $resolvedId !== '' ? $resolvedId : $resolvedName,
            'name' => $resolvedName !== '' ? $resolvedName : $resolvedId,
        ];
    }

    uasort($unique, static function (array $left, array $right): int {
        return strnatcasecmp(
            (string) (($left['name'] ?? '') . ' ' . ($left['id'] ?? '')),
            (string) (($right['name'] ?? '') . ' ' . ($right['id'] ?? ''))
        );
    });

    return array_values($unique);
}

function dataset_counts(string $dataset): array
{
    $base = realpath(__DIR__ . '/../AMDGT_original/data/' . $dataset);
    $count = static function (?string $base, string $file): int {
        if ($base === null) return 0;
        $path = $base . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) return 0;
        $handle = @fopen($path, 'r');
        if ($handle === false) return 0;
        $rows = 0;
        while (fgets($handle) !== false) $rows++;
        fclose($handle);
        return max(0, $rows - 1);
    };
    return [
        'drugs'    => $count($base ?: null, 'DrugFingerprint.csv'),
        'diseases' => $count($base ?: null, 'DiseaseGIP.csv'),
        'pairs'    => $count($base ?: null, 'DrugDiseaseAssociationNumber.csv'),
    ];
}

function load_csv_assoc(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $header = array_map(static fn($value) => trim((string) $value), $header);
    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $assoc = [];
        foreach ($header as $index => $key) {
            $assoc[$key !== '' ? $key : ('col_' . $index)] = trim((string) ($row[$index] ?? ''));
        }
        $rows[] = $assoc;
    }

    fclose($handle);
    return $rows;
}

function count_csv_lines(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }

    $rows = 0;
    while (fgets($handle) !== false) {
        $rows++;
    }

    fclose($handle);
    return $rows;
}

function load_node_entries_php(string $dataDir): array
{
    foreach (['AllNode.csv', 'Allnode.csv'] as $filename) {
        $path = $dataDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            continue;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            continue;
        }

        $entries = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(static fn($value) => trim((string) $value), $row);
            if (count($row) === 1) {
                $nodeId = $row[0] ?? '';
                $label = $row[0] ?? '';
            } else {
                $nodeId = $row[0] ?? '';
                $label = $row[count($row) - 1] ?? '';
            }

            if ($label === '' || in_array(strtolower($label), ['id', 'nan'], true)) {
                continue;
            }
            if ($nodeId === '' || in_array(strtolower($nodeId), ['id', 'nan'], true)) {
                $nodeId = $label;
            }

            $entries[] = ['node_id' => $nodeId, 'label' => $label];
        }

        fclose($handle);
        return $entries;
    }

    return [];
}

function looks_like_compact_id_php(string $value): bool
{
    return preg_match('/^[A-Za-z]{0,5}\d[\w-]*$/', trim($value)) === 1;
}

function load_disease_name_cache_php(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $path = __DIR__ . '/../scripts/cache/disease_name_map.json';
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    $cache = is_array($decoded) ? $decoded : [];
    return $cache;
}

function load_dataset_entities(string $dataset): array
{
    $dataDir = realpath(__DIR__ . '/../AMDGT_original/data/' . $dataset);
    if ($dataDir === false) {
        return ['drugs' => [], 'diseases' => []];
    }

    $drugCount = count_csv_lines($dataDir . DIRECTORY_SEPARATOR . 'Drug_mol2vec.csv');
    $diseaseCount = count_csv_lines($dataDir . DIRECTORY_SEPARATOR . 'DiseaseFeature.csv');
    $nodeEntries = load_node_entries_php($dataDir);
    $drugNodes = $drugCount > 0 ? array_slice($nodeEntries, 0, $drugCount) : $nodeEntries;
    $diseaseNodes = $diseaseCount > 0 ? array_slice($nodeEntries, $drugCount, $diseaseCount) : array_slice($nodeEntries, $drugCount);

    $drugInfoMap = [];
    foreach (load_csv_assoc($dataDir . DIRECTORY_SEPARATOR . 'DrugInformation.csv') as $row) {
        $name = strtolower(trim((string) ($row['name'] ?? $row['drug_name'] ?? '')));
        $id = strtolower(trim((string) ($row['id'] ?? $row['drug_id'] ?? '')));
        if ($name !== '') {
            $drugInfoMap[$name] = $row;
        }
        if ($id !== '') {
            $drugInfoMap[$id] = $row;
        }
    }

    $drugs = [];
    foreach ($drugNodes as $entry) {
        $node = trim((string) ($entry['label'] ?? ''));
        $row = $drugInfoMap[strtolower($node)] ?? null;
        $drugs[] = [
            'id' => (string) ($row['id'] ?? $row['drug_id'] ?? $node),
            'name' => (string) ($row['name'] ?? $row['drug_name'] ?? $node),
        ];
    }

    $diseaseNameCache = load_disease_name_cache_php();
    $diseaseInfoMap = [];
    foreach (load_csv_assoc($dataDir . DIRECTORY_SEPARATOR . 'DiseaseInformation.csv') as $row) {
        $name = strtolower(trim((string) ($row['name'] ?? $row['disease_name'] ?? '')));
        $id = strtolower(trim((string) ($row['id'] ?? $row['disease_id'] ?? '')));
        if ($name !== '') {
            $diseaseInfoMap[$name] = $row;
        }
        if ($id !== '') {
            $diseaseInfoMap[$id] = $row;
        }
    }

    $diseases = [];
    foreach ($diseaseNodes as $entry) {
        $nodeId = trim((string) ($entry['node_id'] ?? ''));
        $nodeLabel = trim((string) ($entry['label'] ?? ''));
        $row = $diseaseInfoMap[strtolower($nodeLabel)] ?? $diseaseInfoMap[strtolower($nodeId)] ?? null;

        if ($row !== null) {
            $resolvedId = trim((string) ($row['id'] ?? $row['disease_id'] ?? ''));
            $resolvedName = trim((string) ($row['name'] ?? $row['disease_name'] ?? ''));
            if ($resolvedId === '') {
                $resolvedId = looks_like_compact_id_php($nodeLabel) ? $nodeLabel : $nodeId;
            }
            if ($resolvedName === '' || strtolower($resolvedName) === strtolower($resolvedId)) {
                $resolvedName = (string) ($diseaseNameCache[$resolvedId] ?? $diseaseNameCache[$nodeLabel] ?? $resolvedName ?: $nodeLabel);
            }
        } else {
            $resolvedId = looks_like_compact_id_php($nodeLabel) ? $nodeLabel : ($nodeId !== '' ? $nodeId : $nodeLabel);
            $resolvedName = (string) ($diseaseNameCache[$resolvedId] ?? $diseaseNameCache[$nodeLabel] ?? $nodeLabel);
        }

        $diseases[] = ['id' => $resolvedId, 'name' => $resolvedName];
    }

    return ['drugs' => unique_entity_options($drugs), 'diseases' => unique_entity_options($diseases)];
}

function aggregate_prediction_group(array $inputs, string $queryType, int $topK, string $dataset, ?array $seedPayloads = null): array
{
    $normalizedInputs = normalize_request_values($inputs);
    $payloads = is_array($seedPayloads) ? $seedPayloads : [];
    $sourceType = str_starts_with($queryType, 'drug') ? 'drug' : 'disease';
    $targetType = $sourceType === 'drug' ? 'disease' : 'drug';

    if ($payloads === []) {
        foreach ($normalizedInputs as $input) {
            $payloads[] = PredictionService::callPythonApi($queryType, $input, $topK, $dataset);
        }
    }

    $matchedInputs = [];
    $matchedSeen = [];
    $resultAccumulator = [];

    foreach ($payloads as $payload) {
        $matched = $payload['matched_input'] ?? null;
        if (is_array($matched) && ($matched['id'] ?? '') !== '') {
            $matchedKey = strtolower((string) (($matched['type'] ?? $sourceType) . ':' . ($matched['id'] ?? '')));
            if (!isset($matchedSeen[$matchedKey])) {
                $matchedSeen[$matchedKey] = true;
                $matchedInputs[] = $matched;
            }
        }

        foreach (($payload['results'] ?? []) as $item) {
            $itemId = (string) ($item['id'] ?? '');
            $itemType = (string) ($item['type'] ?? $targetType);
            if ($itemId === '') {
                continue;
            }

            $itemKey = strtolower($itemType . ':' . $itemId);
            if (!isset($resultAccumulator[$itemKey])) {
                $resultAccumulator[$itemKey] = [
                    'id' => $itemId,
                    'name' => (string) ($item['name'] ?? $itemId),
                    'type' => $itemType,
                    'score_sum' => 0.0,
                    'improved_score_sum' => 0.0,
                    'improved_score_count' => 0,
                    'original_score_sum' => 0.0,
                    'original_score_count' => 0,
                    'support_count' => 0,
                    'score_peak' => 0.0,
                ];
            }

            $improvedScore = (float) ($item['improved_score'] ?? $item['score'] ?? 0);
            $originalScore = array_key_exists('original_score', $item) && $item['original_score'] !== null
                ? (float) $item['original_score']
                : null;

            $resultAccumulator[$itemKey]['score_sum'] += $improvedScore;
            $resultAccumulator[$itemKey]['improved_score_sum'] += $improvedScore;
            $resultAccumulator[$itemKey]['improved_score_count']++;
            $resultAccumulator[$itemKey]['support_count']++;
            $resultAccumulator[$itemKey]['score_peak'] = max((float) $resultAccumulator[$itemKey]['score_peak'], $improvedScore);

            if ($originalScore !== null) {
                $resultAccumulator[$itemKey]['original_score_sum'] += $originalScore;
                $resultAccumulator[$itemKey]['original_score_count']++;
            }
        }
    }

    foreach ($resultAccumulator as &$item) {
        $item['improved_score'] = round((float) $item['improved_score_sum'] / max(1, (int) $item['improved_score_count']), 4);
        $item['score'] = $item['improved_score'];
        $item['original_score'] = (int) ($item['original_score_count'] ?? 0) > 0
            ? round((float) $item['original_score_sum'] / max(1, (int) $item['original_score_count']), 4)
            : null;
        $item['score_peak'] = round((float) $item['score_peak'], 4);
        unset($item['score_sum']);
        unset($item['improved_score_sum']);
        unset($item['improved_score_count']);
        unset($item['original_score_sum']);
        unset($item['original_score_count']);
    }
    unset($item);

    $results = array_values($resultAccumulator);
    usort($results, static function (array $left, array $right): int {
        $supportCmp = (int) ($right['support_count'] ?? 0) <=> (int) ($left['support_count'] ?? 0);
        if ($supportCmp !== 0) {
            return $supportCmp;
        }

        $scoreCmp = (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }

        return strnatcasecmp((string) ($left['name'] ?? $left['id'] ?? ''), (string) ($right['name'] ?? $right['id'] ?? ''));
    });
    $results = array_slice($results, 0, $topK);

    $allowedTargetIds = [];
    foreach ($results as $item) {
        $allowedTargetIds[(string) (($item['type'] ?? $targetType) . ':' . ($item['id'] ?? ''))] = true;
    }

    $sourceNodes = [];
    $proteinNodes = [];
    $targetNodes = [];
    $proteinUsage = [];
    $sourceProteinCandidates = [];
    $linkMap = [];

    $mergeLink = static function (array $link) use (&$linkMap): void {
        $sourceId = (string) ($link['source'] ?? '');
        $targetId = (string) ($link['target'] ?? '');
        $kind = (string) ($link['kind'] ?? 'prediction');
        if ($sourceId === '' || $targetId === '') {
            return;
        }

        $key = strtolower($sourceId . '|' . $targetId . '|' . $kind);
        $score = round((float) ($link['score'] ?? 0), 4);

        if (!isset($linkMap[$key])) {
            $linkMap[$key] = ['source' => $sourceId, 'target' => $targetId, 'kind' => $kind, 'score' => $score];
            return;
        }

        $linkMap[$key]['score'] = round(max((float) ($linkMap[$key]['score'] ?? 0), $score), 4);
    };

    foreach ($payloads as $payload) {
        foreach (($payload['graph']['nodes'] ?? []) as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '') {
                continue;
            }

            if (!empty($node['is_source'])) {
                $sourceNodes[$nodeId] = $node;
                continue;
            }

            if (($node['type'] ?? '') === 'protein') {
                if (!isset($proteinNodes[$nodeId])) {
                    $proteinNodes[$nodeId] = $node;
                }
                $proteinNodes[$nodeId]['support'] = max((int) ($proteinNodes[$nodeId]['support'] ?? 0), (int) ($node['support'] ?? 0));
                continue;
            }

            if (isset($allowedTargetIds[$nodeId])) {
                $targetNodes[$nodeId] = $node;
            }
        }

        foreach (($payload['graph']['links'] ?? []) as $link) {
            $kind = (string) ($link['kind'] ?? 'prediction');
            $sourceId = (string) ($link['source'] ?? '');
            $targetId = (string) ($link['target'] ?? '');

            if ($kind === 'prediction') {
                if (!isset($allowedTargetIds[$targetId])) {
                    continue;
                }
                $mergeLink($link);
                continue;
            }

            if ($kind === 'protein-target') {
                if (!isset($allowedTargetIds[$targetId])) {
                    continue;
                }
                $proteinUsage[$sourceId] = true;
                $mergeLink($link);
                continue;
            }

            if ($kind === 'source-protein') {
                $sourceProteinCandidates[] = $link;
            }
        }
    }

    $hasProteinTargetLinks = $proteinUsage !== [];
    foreach ($sourceProteinCandidates as $link) {
        $proteinId = (string) ($link['target'] ?? '');
        if ($proteinId === '') {
            continue;
        }
        if ($hasProteinTargetLinks && !isset($proteinUsage[$proteinId])) {
            continue;
        }
        $proteinUsage[$proteinId] = true;
        $mergeLink($link);
    }

    foreach ($results as $item) {
        $nodeId = (string) (($item['type'] ?? $targetType) . ':' . ($item['id'] ?? ''));
        if (!isset($targetNodes[$nodeId])) {
            $targetNodes[$nodeId] = [
                'id' => $nodeId,
                'actual_id' => (string) ($item['id'] ?? ''),
                'label' => (string) ($item['name'] ?? $item['id'] ?? ''),
                'type' => (string) ($item['type'] ?? $targetType),
                'color' => $targetType === 'drug' ? '#2563eb' : '#dc2626',
                'smiles' => '',
            ];
        }

        $targetNodes[$nodeId]['score'] = (float) ($item['score'] ?? 0);
        $targetNodes[$nodeId]['support_count'] = (int) ($item['support_count'] ?? 1);
    }

    $proteinNodes = array_filter($proteinNodes, static fn(array $node): bool => isset($proteinUsage[(string) ($node['id'] ?? '')]));

    $sourceNodes = array_values($sourceNodes);
    $proteinNodes = array_values($proteinNodes);
    $targetNodes = array_values($targetNodes);

    usort($sourceNodes, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });
    usort($proteinNodes, static function (array $left, array $right): int {
        $supportCmp = (int) ($right['support'] ?? 0) <=> (int) ($left['support'] ?? 0);
        if ($supportCmp !== 0) {
            return $supportCmp;
        }
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });
    usort($targetNodes, static function (array $left, array $right): int {
        $supportCmp = (int) ($right['support_count'] ?? 0) <=> (int) ($left['support_count'] ?? 0);
        if ($supportCmp !== 0) {
            return $supportCmp;
        }
        $scoreCmp = (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });

    return [
        'title' => $sourceType === 'drug' ? 'Thuốc → Bệnh' : 'Bệnh → Thuốc',
        'source_type' => $sourceType,
        'target_type' => $targetType,
        'query_type' => $queryType,
        'matched_input' => count($matchedInputs) === 1 ? $matchedInputs[0] : null,
        'matched_inputs' => $matchedInputs,
        'results' => $results,
        'graph' => ['nodes' => array_merge($sourceNodes, $proteinNodes, $targetNodes), 'links' => array_values($linkMap)],
        'note' => count($matchedInputs) > 1
            ? sprintf('Tổng hợp từ %d nguồn %s đã chọn trên %s.', count($matchedInputs), $sourceType === 'drug' ? 'thuốc' : 'bệnh', $dataset)
            : (string) (($payloads[0]['note'] ?? '') ?: ''),
        'single_payload' => count($payloads) === 1 ? $payloads[0] : null,
    ];
}

function render_pair_model_chart_svg(array $pairs): string
{
    if ($pairs === []) {
        return '<p class="muted" style="padding:1rem 0;">Không có dữ liệu biểu đồ để so sánh.</p>';
    }

    $plotHeight = 200;
    $topPad = 24;
    $bottomPad = 118;
    $leftPad = 58;
    $groupWidth = 30;
    $groupGap = 18;
    $barWidth = 10;
    $innerGap = 6;
    $width = max(780, $leftPad + count($pairs) * ($groupWidth + $groupGap) + 40);
    $height = $topPad + $plotHeight + $bottomPad;
    $baseY = $topPad + $plotHeight;

    ob_start();
    ?>
    <div class="pair-chart-scroll">
        <svg class="pair-chart-svg" viewBox="0 0 <?= $width ?> <?= $height ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Biểu đồ so sánh hai mô hình theo từng cặp thuốc-bệnh">
            <?php foreach ([0, 0.25, 0.5, 0.75, 1.0] as $tick): ?>
                <?php $y = $topPad + (1 - $tick) * $plotHeight; ?>
                <line x1="<?= $leftPad - 6 ?>" y1="<?= $y ?>" x2="<?= $width - 18 ?>" y2="<?= $y ?>" class="pair-chart-grid" />
                <text x="<?= $leftPad - 12 ?>" y="<?= $y + 4 ?>" class="pair-chart-axis-label" text-anchor="end"><?= e(number_format($tick, 2)) ?></text>
            <?php endforeach; ?>

            <?php foreach ($pairs as $index => $pair): ?>
                <?php
                $improvedScore = max(0.0, min(1.0, (float) ($pair['improved_score'] ?? 0)));
                $hasOriginal = array_key_exists('original_score', $pair) && $pair['original_score'] !== null;
                $originalScore = $hasOriginal ? max(0.0, min(1.0, (float) $pair['original_score'])) : 0.0;
                $groupX = $leftPad + 12 + $index * ($groupWidth + $groupGap);
                $improvedX = $groupX;
                $originalX = $groupX + $barWidth + $innerGap;
                $improvedHeight = $plotHeight * $improvedScore;
                $originalHeight = $plotHeight * $originalScore;
                $improvedBarHeight = max(4.0, $improvedHeight);
                $originalBarHeight = max(4.0, $originalHeight);
                $label = (string) (($pair['drug_id'] ?? '') . '→' . ($pair['disease_id'] ?? ''));
                $title = sprintf(
                    '%s → %s | Cải tiến %s | Gốc %s',
                    (string) ($pair['drug_name'] ?? $pair['drug_id'] ?? ''),
                    (string) ($pair['disease_name'] ?? $pair['disease_id'] ?? ''),
                    format_score($improvedScore),
                    $hasOriginal ? format_score($pair['original_score']) : '—'
                );
                ?>
                <rect x="<?= $improvedX ?>" y="<?= $baseY - $improvedBarHeight ?>" width="<?= $barWidth ?>" height="<?= $improvedBarHeight ?>" rx="4" class="pair-chart-bar pair-chart-bar-improved">
                    <title><?= e($title) ?></title>
                </rect>
                <rect x="<?= $originalX ?>" y="<?= $baseY - $originalBarHeight ?>" width="<?= $barWidth ?>" height="<?= $originalBarHeight ?>" rx="4" class="pair-chart-bar pair-chart-bar-original" style="opacity:<?= $hasOriginal ? '1' : '.25' ?>;">
                    <title><?= e($title) ?></title>
                </rect>
                <g transform="translate(<?= $groupX + 4 ?>,<?= $baseY + 16 ?>) rotate(55)">
                    <text class="pair-chart-label"><?= e($label) ?></text>
                </g>
            <?php endforeach; ?>
        </svg>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_pair_matrix_section(array $payload): string
{
    $pairs = $payload['pairs'] ?? [];
    $rankedPairs = $payload['ranked_pairs'] ?? $pairs;
    $matrix = $payload['matrix'] ?? [];
    $selectedDrugs = $payload['selected_drugs'] ?? [];
    $selectedDiseases = $payload['selected_diseases'] ?? [];
    $note = trim((string) ($payload['note'] ?? ''));

    ob_start();
    ?>
    <section class="prediction-group-section">
        <?php if ($note !== ''): ?>
            <div class="alert" style="background:rgba(96,165,250,.1);border-color:rgba(96,165,250,.3);margin-bottom:1rem;">
                <?= e($note) ?>
            </div>
        <?php endif; ?>

        <div class="matched-card">
            <div class="matched-icon">⇄</div>
            <div class="matched-details">
                <h4>Chấm điểm từng cặp thuốc – bệnh</h4>
                <div class="muted" style="font-size:.82rem;">
                    Đã tạo <strong><?= count($pairs) ?></strong> cặp từ <strong><?= count($selectedDrugs) ?></strong> thuốc và <strong><?= count($selectedDiseases) ?></strong> bệnh đã chọn. Khi chọn cả hai cột, <strong>Top-K không áp dụng</strong>.
                </div>
                <div class="selected-source-tags">
                    <?php foreach ($selectedDrugs as $item): ?>
                        <span class="selected-source-chip">
                            <strong>Thuốc</strong>
                            <?= e((string) ($item['name'] ?? $item['id'] ?? '')) ?>
                            <code><?= e((string) ($item['id'] ?? '')) ?></code>
                        </span>
                    <?php endforeach; ?>
                    <?php foreach ($selectedDiseases as $item): ?>
                        <span class="selected-source-chip">
                            <strong>Bệnh</strong>
                            <?= e((string) ($item['name'] ?? $item['id'] ?? '')) ?>
                            <code><?= e((string) ($item['id'] ?? '')) ?></code>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="glass-card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Biểu đồ so sánh 2 model</h3>
                <p class="muted" style="font-size:.82rem;">Mỗi cặp thuốc-bệnh có hai cột: xanh lá là model cải tiến, xanh dương là model gốc.</p>
            </div>
            <div class="pair-chart-legend">
                <span class="pair-chart-legend-item"><span class="pair-chart-swatch pair-chart-swatch-improved"></span>Cải tiến</span>
                <span class="pair-chart-legend-item"><span class="pair-chart-swatch pair-chart-swatch-original"></span>Gốc</span>
            </div>
            <?= render_pair_model_chart_svg($rankedPairs) ?>
        </div>

        <div class="glass-card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h3 class="card-title">Bảng điểm từng cặp</h3>
                <p class="muted" style="font-size:.82rem;">Danh sách được sắp theo điểm model cải tiến giảm dần để anh nhìn cặp mạnh nhất trước.</p>
            </div>
            <div class="table-wrap">
                <table class="table result-table">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>Thuốc</th>
                            <th>Bệnh</th>
                            <th>Cải tiến</th>
                            <th>Gốc</th>
                            <th>Chênh lệch</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rankedPairs === []): ?>
                        <tr>
                            <td colspan="6" class="muted" style="padding:1rem 1.25rem;">Không có cặp thuốc-bệnh nào để hiển thị.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rankedPairs as $index => $pair): ?>
                            <?php
                            $originalScore = array_key_exists('original_score', $pair) && $pair['original_score'] !== null ? (float) $pair['original_score'] : null;
                            $delta = array_key_exists('delta_score', $pair) ? $pair['delta_score'] : null;
                            $deltaClass = $delta === null ? 'is-neutral' : (((float) $delta) >= 0 ? 'is-positive' : 'is-negative');
                            ?>
                            <tr>
                                <td><span class="rank-badge"><?= $index + 1 ?></span></td>
                                <td>
                                    <strong><?= e((string) ($pair['drug_name'] ?? $pair['drug_id'] ?? '')) ?></strong><br>
                                    <code style="font-size:.76rem;color:#93c5fd;"><?= e((string) ($pair['drug_id'] ?? '')) ?></code>
                                </td>
                                <td>
                                    <strong><?= e((string) ($pair['disease_name'] ?? $pair['disease_id'] ?? '')) ?></strong><br>
                                    <code style="font-size:.76rem;color:#93c5fd;"><?= e((string) ($pair['disease_id'] ?? '')) ?></code>
                                </td>
                                <td>
                                    <div class="score-bar-wrap">
                                        <span style="min-width:52px;font-weight:600;color:#22c55e;"><?= format_score($pair['improved_score'] ?? 0) ?></span>
                                        <div class="score-bar" style="width:<?= (int) (((float) ($pair['improved_score'] ?? 0)) * 120) ?>px"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="score-bar-wrap">
                                        <span style="min-width:52px;font-weight:600;color:#60a5fa;"><?= $originalScore !== null ? format_score($originalScore) : '—' ?></span>
                                        <div class="score-bar" style="width:<?= $originalScore !== null ? (int) ($originalScore * 120) : 4 ?>px;background:linear-gradient(90deg,#2563eb,#93c5fd);opacity:<?= $originalScore !== null ? '1' : '.25' ?>;"></div>
                                    </div>
                                </td>
                                <td><span class="delta-pill <?= $deltaClass ?>"><?= format_delta_score($delta) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="glass-card">
            <div class="card-header">
                <h3 class="card-title">Ma trận thuốc × bệnh</h3>
                <p class="muted" style="font-size:.82rem;">Mỗi ô là đúng một cặp đã chọn, hiển thị điểm của cả hai model và độ chênh giữa chúng.</p>
            </div>
            <div class="pair-matrix-scroll">
                <table class="table pair-matrix-table">
                    <thead>
                        <tr>
                            <th>Thuốc \ Bệnh</th>
                            <?php foreach ($selectedDiseases as $item): ?>
                                <th>
                                    <div class="pair-matrix-heading">
                                        <strong><?= e((string) ($item['name'] ?? $item['id'] ?? '')) ?></strong>
                                        <code><?= e((string) ($item['id'] ?? '')) ?></code>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matrix as $row): ?>
                        <tr>
                            <th>
                                <div class="pair-matrix-heading">
                                    <strong><?= e((string) ($row['drug_name'] ?? $row['drug_id'] ?? '')) ?></strong>
                                    <code><?= e((string) ($row['drug_id'] ?? '')) ?></code>
                                </div>
                            </th>
                            <?php foreach (($row['cells'] ?? []) as $cell): ?>
                                <?php
                                $cellOriginalScore = array_key_exists('original_score', $cell) && $cell['original_score'] !== null ? (float) $cell['original_score'] : null;
                                $cellDelta = array_key_exists('delta_score', $cell) ? $cell['delta_score'] : null;
                                $cellDeltaClass = $cellDelta === null ? 'is-neutral' : (((float) $cellDelta) >= 0 ? 'is-positive' : 'is-negative');
                                ?>
                                <td>
                                    <div class="pair-matrix-cell">
                                        <div class="pair-matrix-score">
                                            <span class="pair-matrix-score-label">Cải tiến</span>
                                            <strong><?= format_score($cell['improved_score'] ?? 0) ?></strong>
                                        </div>
                                        <div class="pair-matrix-score">
                                            <span class="pair-matrix-score-label">Gốc</span>
                                            <strong><?= $cellOriginalScore !== null ? format_score($cellOriginalScore) : '—' ?></strong>
                                        </div>
                                        <div class="pair-matrix-delta <?= $cellDeltaClass ?>">Δ <?= format_delta_score($cellDelta) ?></div>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        // --- Build a link graph from pair data ---
        $graphNodes = [];
        $graphLinks = [];
        $drugSmilesList = [];
        foreach ($pairs as $pair) {
            $drugId   = 'drug:' . (string) ($pair['drug_id'] ?? '');
            $diseaseId = 'disease:' . (string) ($pair['disease_id'] ?? '');
            $drugSmiles = trim((string) ($pair['drug_smiles'] ?? ''));
            if (!isset($graphNodes[$drugId])) {
                $graphNodes[$drugId] = [
                    'id'        => $drugId,
                    'actual_id' => (string) ($pair['drug_id'] ?? ''),
                    'label'     => (string) ($pair['drug_name'] ?? $pair['drug_id'] ?? ''),
                    'type'      => 'drug',
                    'color'     => '#2563eb',
                    'smiles'    => $drugSmiles,
                    'is_source' => true,
                ];
                if ($drugSmiles !== '') {
                    $drugSmilesList[$drugId] = $graphNodes[$drugId];
                }
            }
            if (!isset($graphNodes[$diseaseId])) {
                $graphNodes[$diseaseId] = [
                    'id'        => $diseaseId,
                    'actual_id' => (string) ($pair['disease_id'] ?? ''),
                    'label'     => (string) ($pair['disease_name'] ?? $pair['disease_id'] ?? ''),
                    'type'      => 'disease',
                    'color'     => '#dc2626',
                    'smiles'    => '',
                    'score'     => (float) ($pair['improved_score'] ?? $pair['score'] ?? 0),
                    'support_count' => 1,
                ];
            }
            $graphLinks[] = [
                'source' => $drugId,
                'target' => $diseaseId,
                'kind'   => 'prediction',
                'score'  => round((float) ($pair['improved_score'] ?? $pair['score'] ?? 0), 4),
            ];
        }
        $pairGraph = [
            'nodes' => array_values($graphNodes),
            'links' => $graphLinks,
        ];
        ?>

        <?php if (!empty($pairGraph['nodes'])): ?>
            <div class="glass-card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined" style="vertical-align:-5px;font-size:18px;">hub</span> Đồ thị liên kết phân tử (2D)</h3>
                    <p class="muted" style="font-size:.82rem;">Hover vào node để xem cấu trúc hóa học, protein, hoặc mở cùng dữ liệu trong không gian 3D.</p>
                </div>
                <?= render_prediction_graph($pairGraph) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($drugSmilesList)): ?>
            <div class="glass-card" style="margin-top:1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined" style="vertical-align:-5px;font-size:18px;">science</span> Cấu trúc hóa học phân tử</h3>
                    <p class="muted" style="font-size:.82rem;">Biểu đồ 2D cấu trúc SMILES của các thuốc đã chọn.</p>
                </div>
                <div class="molecule-strip">
                    <?php foreach ($drugSmilesList as $idx => $node): ?>
                        <?php $canvasId = 'pair-mol-' . md5((string) $idx); ?>
                        <div class="molecule-card">
                            <div class="molecule-card-top">
                                <span class="graph-chip graph-chip-source">Input molecule</span>
                                <span class="molecule-card-meta"><?= e((string) ($node['actual_id'] ?? '')) ?></span>
                            </div>
                            <div class="molecule-card-title"><?= e((string) ($node['label'] ?? 'Drug')) ?></div>
                            <canvas id="<?= e($canvasId) ?>" class="molecule-canvas" width="280" height="184" data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"></canvas>
                            <div class="molecule-card-meta" style="margin-top:10px;">Input molecule</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

function render_prediction_graph(array $graph): string
{
    static $graphInstance = 0;
    $graphInstance++;

    $nodes = $graph['nodes'] ?? [];
    $links = $graph['links'] ?? [];
    $sourceNodes = [];
    $proteinNodes = [];
    $targetNodes = [];

    foreach ($nodes as $node) {
        $type = $node['type'] ?? '';
        if (!empty($node['is_source'])) {
            $sourceNodes[] = $node;
        } elseif ($type === 'protein') {
            $proteinNodes[] = $node;
        } elseif (in_array($type, ['drug', 'disease'], true)) {
            $targetNodes[] = $node;
        }
    }

    if ($sourceNodes === [] && $targetNodes !== []) {
        $promoted = array_shift($targetNodes);
        $promoted['is_source'] = true;
        $sourceNodes[] = $promoted;
    }

    if ($sourceNodes === []) {
        return '<p class="muted" style="text-align:center;padding:2rem;">Không có dữ liệu đồ thị.</p>';
    }

    $sourceType = (string) ($sourceNodes[0]['type'] ?? 'drug');
    $structureNodes = [];
    $seenStructureIds = [];
    foreach (array_merge($sourceNodes, $targetNodes) as $node) {
        if (($node['type'] ?? '') !== 'drug' || empty($node['smiles'])) {
            continue;
        }
        $structureKey = (string) ($node['actual_id'] ?? $node['id'] ?? '');
        if ($structureKey !== '' && isset($seenStructureIds[$structureKey])) {
            continue;
        }
        $seenStructureIds[$structureKey] = true;
        $structureNodes[] = $node;
    }

    usort($sourceNodes, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });
    usort($proteinNodes, static function (array $left, array $right): int {
        $supportCmp = (int) ($right['support'] ?? 0) <=> (int) ($left['support'] ?? 0);
        if ($supportCmp !== 0) {
            return $supportCmp;
        }
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });

    $targetProteinHints = [];
    foreach ($links as $link) {
        if (($link['kind'] ?? '') !== 'protein-target') {
            continue;
        }
        $targetProteinHints[(string) ($link['target'] ?? '')][] = (string) ($link['source'] ?? '');
    }

    $proteinOrderMap = [];
    foreach ($proteinNodes as $index => $node) {
        $proteinOrderMap[(string) ($node['id'] ?? '')] = $index;
    }

    usort($targetNodes, static function (array $left, array $right) use ($targetProteinHints, $proteinOrderMap): int {
        $leftHint = $targetProteinHints[(string) ($left['id'] ?? '')] ?? [];
        $rightHint = $targetProteinHints[(string) ($right['id'] ?? '')] ?? [];
        $leftOrder = $leftHint === [] ? 9999 : min(array_map(static fn(string $id): int => $proteinOrderMap[$id] ?? 9999, $leftHint));
        $rightOrder = $rightHint === [] ? 9999 : min(array_map(static fn(string $id): int => $proteinOrderMap[$id] ?? 9999, $rightHint));
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }
        $supportCmp = (int) ($right['support_count'] ?? 0) <=> (int) ($left['support_count'] ?? 0);
        if ($supportCmp !== 0) {
            return $supportCmp;
        }
        $scoreCmp = (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return strnatcasecmp((string) ($left['label'] ?? $left['actual_id'] ?? ''), (string) ($right['label'] ?? $right['actual_id'] ?? ''));
    });

    $rowCount = max(count($sourceNodes), count($proteinNodes), count($targetNodes), 1);
    $height = max(460, 140 + $rowCount * 122);
    $width = 1320;
    $col1X = 150;
    $col2X = 650;
    $col3X = 1110;
    $topPad = 88;
    $bottomPad = 84;
    $targetCardWidth = 250;

    $distributeY = static function (int $count) use ($height, $topPad, $bottomPad): array {
        if ($count <= 1) {
            return [(int) round($height / 2)];
        }
        $usable = $height - $topPad - $bottomPad;
        $positions = [];
        for ($index = 0; $index < $count; $index++) {
            $positions[] = (int) round($topPad + ($index + 0.5) * $usable / $count);
        }
        return $positions;
    };

    $positions = [];
    foreach ($sourceNodes as $index => $node) {
        $positions[(string) ($node['id'] ?? '')] = [$col1X, $distributeY(count($sourceNodes))[$index] ?? (int) round($height / 2)];
    }
    foreach ($proteinNodes as $index => $node) {
        $positions[(string) ($node['id'] ?? '')] = [$col2X, $distributeY(count($proteinNodes))[$index] ?? (int) round($height / 2)];
    }
    foreach ($targetNodes as $index => $node) {
        $positions[(string) ($node['id'] ?? '')] = [$col3X, $distributeY(count($targetNodes))[$index] ?? (int) round($height / 2)];
    }

    $linkKey = static fn(array $link): string => strtolower((string) (($link['source'] ?? '') . '|' . ($link['target'] ?? '') . '|' . ($link['kind'] ?? 'prediction')));
    $laneOut = [];
    $laneIn = [];
    $outgoing = [];
    $incoming = [];

    foreach ($links as $link) {
        $outgoing[(string) (($link['source'] ?? '') . '|' . ($link['kind'] ?? 'prediction'))][] = $link;
        $incoming[(string) (($link['target'] ?? '') . '|' . ($link['kind'] ?? 'prediction'))][] = $link;
    }

    foreach ($outgoing as $group) {
        usort($group, static function (array $left, array $right) use ($positions): int {
            return (int) (($positions[(string) ($left['target'] ?? '')][1] ?? 0)) <=> (int) (($positions[(string) ($right['target'] ?? '')][1] ?? 0));
        });
        $middle = (count($group) - 1) / 2;
        foreach ($group as $index => $item) {
            $laneOut[$linkKey($item)] = $index - $middle;
        }
    }

    foreach ($incoming as $group) {
        usort($group, static function (array $left, array $right) use ($positions): int {
            return (int) (($positions[(string) ($left['source'] ?? '')][1] ?? 0)) <=> (int) (($positions[(string) ($right['source'] ?? '')][1] ?? 0));
        });
        $middle = (count($group) - 1) / 2;
        foreach ($group as $index => $item) {
            $laneIn[$linkKey($item)] = $index - $middle;
        }
    }

    $orderedLinks = $links;
    usort($orderedLinks, static function (array $left, array $right): int {
        $order = ['prediction' => 0, 'source-protein' => 1, 'protein-target' => 2];
        return ($order[(string) ($left['kind'] ?? 'prediction')] ?? 9) <=> ($order[(string) ($right['kind'] ?? 'prediction')] ?? 9);
    });

    ob_start();
    ?>
    <div class="graph-2d-wrapper" data-graph='<?= json_encode($graph, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>'>
        <div class="graph-toolbar">
            <span class="graph-legend">
                <span class="legend-item legend-drug">⬡ Thuốc</span>
                <span class="legend-item legend-protein">H Protein</span>
                <span class="legend-item legend-disease">✚ Bệnh</span>
            </span>
            <button class="btn btn-sm btn-ghost" onclick="open3DModal(this)" type="button">
                <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px">view_in_ar</span>
                Xem 3D
            </button>
        </div>
        <svg class="prediction-network" viewBox="0 0 <?= $width ?> <?= $height ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Sơ đồ liên kết phân tử">
            <defs>
                <filter id="glow-src-<?= $graphInstance ?>" x="-60%" y="-60%" width="220%" height="220%">
                    <feGaussianBlur stdDeviation="5" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>

            <rect x="28" y="54" width="246" height="<?= max(330, $height - 108) ?>" rx="20" class="graph-panel-bg"/>
            <rect x="502" y="54" width="294" height="<?= max(330, $height - 108) ?>" rx="20" class="graph-panel-bg"/>
            <rect x="930" y="54" width="356" height="<?= max(330, $height - 108) ?>" rx="20" class="graph-panel-bg"/>

            <!-- Column labels -->
            <text x="<?= $col1X ?>" y="34" class="graph-col-label" text-anchor="middle"><?= $sourceType === 'drug' ? 'Thuốc nguồn' : 'Bệnh nguồn' ?></text>
            <?php if ($proteinNodes !== []): ?>
                <text x="<?= $col2X ?>" y="34" class="graph-col-label" text-anchor="middle">Protein trung gian</text>
            <?php endif; ?>
            <text x="<?= $col3X ?>" y="34" class="graph-col-label" text-anchor="middle"><?= $sourceType === 'drug' ? 'Bệnh đích' : 'Thuốc đích' ?></text>

            <!-- Edges -->
            <?php foreach ($orderedLinks as $link): ?>
                <?php
                $srcId = (string) ($link['source'] ?? '');
                $tgtId = (string) ($link['target'] ?? '');
                $src = $positions[$srcId] ?? null;
                $tgt = $positions[$tgtId] ?? null;
                if (!$src || !$tgt) continue;
                $kind = (string) ($link['kind'] ?? 'prediction');
                $score = max(0.05, min(1.0, (float) ($link['score'] ?? 0.5)));
                $opacity = 0.22 + $score * 0.48;
                $sw = 1.3 + $score * 3.1;
                $stroke = '#22c55e';
                $dash = 'none';
                $startY = $src[1] + (($laneOut[$linkKey($link)] ?? 0) * ($kind === 'prediction' ? 12 : 9));
                $endY = $tgt[1] + (($laneIn[$linkKey($link)] ?? 0) * ($kind === 'prediction' ? 10 : 7));
                $startX = $src[0] + ($kind === 'protein-target' ? 24 : 30);
                $endX = $kind === 'prediction' || $kind === 'protein-target' ? $tgt[0] - (int) ($targetCardWidth / 2) : $tgt[0] - 24;
                if ($kind === 'source-protein') {
                    $stroke = '#f59e0b';
                } elseif ($kind === 'protein-target') {
                    $stroke = '#22c55e';
                } else {
                    $stroke = '#38bdf8';
                    $dash = '7 7';
                    $opacity *= 0.72;
                    $sw = max(1.2, $sw - 0.8);
                }
                $control1X = $startX + ($kind === 'prediction' ? 170 : 110);
                $control2X = $endX - ($kind === 'prediction' ? 170 : 110);
                ?>
                <path d="M <?= $startX ?> <?= $startY ?> C <?= $control1X ?> <?= $startY ?>, <?= $control2X ?> <?= $endY ?>, <?= $endX ?> <?= $endY ?>"
                      fill="none"
                      stroke="<?= $stroke ?>"
                      stroke-width="<?= number_format($sw, 1) ?>"
                      stroke-opacity="<?= number_format($opacity, 2) ?>"
                      stroke-dasharray="<?= $dash ?>"
                      class="graph-edge"/>
            <?php endforeach; ?>

            <!-- Source nodes -->
            <?php foreach ($sourceNodes as $node): ?>
                <?php [$x, $y] = $positions[(string) ($node['id'] ?? '')]; ?>
                <g class="graph-node graph-node-<?= e((string) ($node['type'] ?? 'drug')) ?> graph-node-source"
                   data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"
                   data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                   data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                   data-type="<?= e((string) ($node['type'] ?? '')) ?>"
                   data-seq-len="0">
                     <?php if (($node['type'] ?? '') === 'drug'): ?>
                         <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="#1a3a6b" fill-opacity="0.92" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                         <g transform="translate(<?= $x ?>,<?= $y ?>)">
                             <polygon points="0,-12 10.4,-6 10.4,6 0,12 -10.4,6 -10.4,-6" fill="none" stroke="#60a5fa" stroke-width="2"/>
                             <circle cx="0" cy="0" r="6" fill="none" stroke="#93c5fd" stroke-width="1.5"/>
                         </g>
                     <?php else: ?>
                         <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="#6b1a1a" fill-opacity="0.92" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                         <g transform="translate(<?= $x ?>,<?= $y ?>)">
                             <rect x="-10" y="-3.5" width="20" height="7" rx="2" fill="#f87171"/>
                             <rect x="-3.5" y="-10" width="7" height="20" rx="2" fill="#f87171"/>
                         </g>
                     <?php endif; ?>
                     <text x="<?= $x ?>" y="<?= $y + 39 ?>" class="graph-node-label" text-anchor="middle"><?= e((string) ($node['label'] ?? '')) ?></text>
                     <text x="<?= $x ?>" y="<?= $y + 53 ?>" class="graph-node-sublabel" text-anchor="middle"><?= e((string) ($node['actual_id'] ?? '')) ?></text>
                 </g>
            <?php endforeach; ?>

            <!-- Protein nodes -->
            <?php foreach ($proteinNodes as $node): ?>
                <?php [$x, $y] = $positions[(string) ($node['id'] ?? '')]; ?>
                <g class="graph-node graph-node-protein"
                   data-smiles=""
                   data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                   data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                   data-type="protein"
                   data-seq-len="<?= (int) ($node['seq_len'] ?? 0) ?>">
                     <circle cx="<?= $x ?>" cy="<?= $y ?>" r="18" fill="#78350f" fill-opacity="0.86"/>
                     <g transform="translate(<?= $x ?>,<?= $y ?>)">
                         <line x1="-6" y1="-8" x2="-6" y2="8" stroke="#fcd34d" stroke-width="2.2" stroke-linecap="round"/>
                         <line x1="6" y1="-8" x2="6" y2="8" stroke="#fcd34d" stroke-width="2.2" stroke-linecap="round"/>
                         <line x1="-6" y1="0" x2="6" y2="0" stroke="#fcd34d" stroke-width="2" stroke-linecap="round"/>
                     </g>
                     <text x="<?= $x ?>" y="<?= $y + 31 ?>" class="graph-node-label" text-anchor="middle"><?= e((string) ($node['label'] ?? $node['actual_id'] ?? '')) ?></text>
                     <text x="<?= $x ?>" y="<?= $y + 45 ?>" class="graph-node-sublabel" text-anchor="middle">support <?= e((string) ($node['support'] ?? 1)) ?></text>
                 </g>
            <?php endforeach; ?>

            <!-- Target nodes -->
            <?php foreach ($targetNodes as $node): ?>
                <?php
                [$x, $y] = $positions[(string) ($node['id'] ?? '')];
                $nodeType = (string) ($node['type'] ?? 'disease');
                $cardX = $x - (int) ($targetCardWidth / 2);
                $cardY = $y - 30;
                ?>
                <g class="graph-node graph-node-<?= e($nodeType) ?>"
                   data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"
                   data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                   data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                   data-type="<?= e($nodeType) ?>"
                   data-seq-len="0">
                     <rect x="<?= $cardX ?>" y="<?= $cardY ?>" width="<?= $targetCardWidth ?>" height="60" rx="16" fill="<?= $nodeType === 'drug' ? 'rgba(37,99,235,.14)' : 'rgba(220,38,38,.14)' ?>" stroke="<?= $nodeType === 'drug' ? 'rgba(96,165,250,.28)' : 'rgba(248,113,113,.28)' ?>"/>
                     <?php if ($nodeType === 'drug'): ?>
                         <g transform="translate(<?= $cardX + 28 ?>,<?= $y ?>)">
                             <polygon points="0,-9 7.8,-4.5 7.8,4.5 0,9 -7.8,4.5 -7.8,-4.5" fill="none" stroke="#60a5fa" stroke-width="1.8"/>
                             <circle cx="0" cy="0" r="4.5" fill="none" stroke="#93c5fd" stroke-width="1.3"/>
                         </g>
                     <?php else: ?>
                         <g transform="translate(<?= $cardX + 28 ?>,<?= $y ?>)">
                             <rect x="-8" y="-2.5" width="16" height="5" rx="1.5" fill="#f87171"/>
                             <rect x="-2.5" y="-8" width="5" height="16" rx="1.5" fill="#f87171"/>
                         </g>
                     <?php endif; ?>
                     <text x="<?= $cardX + 52 ?>" y="<?= $y - 4 ?>" class="graph-node-label graph-node-label-left"><?= e((string) ($node['label'] ?? '')) ?></text>
                     <text x="<?= $cardX + 52 ?>" y="<?= $y + 14 ?>" class="graph-node-sublabel graph-node-label-left">score <?= e(number_format((float) ($node['score'] ?? 0), 4)) ?> · hits <?= e((string) ($node['support_count'] ?? 1)) ?></text>
                 </g>
            <?php endforeach; ?>
        </svg>
        <?php if (!empty($structureNodes)): ?>
            <div class="molecule-strip">
                 <?php foreach ($structureNodes as $index => $node): ?>
                     <?php $canvasId = 'molecule-canvas-' . $graphInstance . '-' . $index; ?>
                     <div class="molecule-card">
                         <div class="molecule-card-top">
                             <span class="graph-chip <?= !empty($node['is_source']) ? 'graph-chip-source' : 'graph-chip-target' ?>">
                                 <?= !empty($node['is_source']) ? 'Input molecule' : 'Predicted molecule' ?>
                             </span>
                             <span class="molecule-card-meta"><?= e((string) ($node['actual_id'] ?? '')) ?></span>
                         </div>
                         <div class="molecule-card-title"><?= e((string) ($node['label'] ?? 'Drug')) ?></div>
                         <canvas id="<?= e($canvasId) ?>" class="molecule-canvas" width="280" height="184" data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"></canvas>
                         <div class="molecule-card-meta" style="margin-top:10px;">
                             <?= !empty($node['score']) ? 'Score: ' . e(number_format((float) $node['score'], 4)) : 'Input molecule' ?>
                         </div>
                     </div>
                 <?php endforeach; ?>
             </div>
        <?php endif; ?>
        <!-- Hover tooltip -->
        <div class="graph-tooltip">
            <canvas class="graph-tooltip-canvas" width="220" height="180" style="display:none;"></canvas>
            <div class="tooltip-body"></div>
        </div>
     </div>
     <?php
     return (string) ob_get_clean();
 }

 function render_prediction_group_section(array $group): string
 {
     $results = $group['results'] ?? [];
     $graph = $group['graph'] ?? ['nodes' => [], 'links' => []];
     $matchedInputs = $group['matched_inputs'] ?? [];
     $sourceType = (string) ($group['source_type'] ?? 'drug');
     $title = (string) ($group['title'] ?? ($sourceType === 'drug' ? 'Thuốc → Bệnh' : 'Bệnh → Thuốc'));
     $note = trim((string) ($group['note'] ?? ''));
     $graphNodes = $graph['nodes'] ?? [];
     $graphLinks = $graph['links'] ?? [];
     $proteinNodeCount = count(array_filter($graphNodes, static fn(array $node): bool => (string) ($node['type'] ?? '') === 'protein'));
     $proteinBridgeCount = count(array_filter($graphLinks, static fn(array $link): bool => (string) ($link['kind'] ?? '') === 'protein-target'));
     $graphInsight = '';
     if ($graphNodes !== []) {
         if ($proteinNodeCount === 0) {
             $graphInsight = 'Nguồn đang chọn hiện chưa có protein trung gian khả dụng trong dữ liệu, nên đồ thị chỉ hiển thị các cạnh dự đoán trực tiếp của mô hình.';
         } elseif ($proteinBridgeCount === 0) {
             $graphInsight = 'Cột protein đang hiển thị các protein gắn với nguồn vào. Do chưa có protein giao cắt với các đích top hiện tại, các đường xanh đứt biểu diễn cạnh dự đoán trực tiếp.';
         }
     }

     ob_start();
     ?>
     <section class="prediction-group-section">
         <?php if ($note !== ''): ?>
             <div class="alert" style="background:rgba(96,165,250,.1);border-color:rgba(96,165,250,.3);margin-bottom:1rem;">
                 <?= e($note) ?>
             </div>
         <?php endif; ?>

         <?php if ($matchedInputs !== []): ?>
             <div class="matched-card">
                 <div class="matched-icon"><?= $sourceType === 'drug' ? '⬡' : '✚' ?></div>
                 <div class="matched-details">
                     <h4><?= e($title) ?></h4>
                     <div class="muted" style="font-size:.82rem;">
                         Tổng hợp từ <strong><?= count($matchedInputs) ?></strong> nguồn đã chọn.
                     </div>
                     <div class="selected-source-tags">
                         <?php foreach ($matchedInputs as $item): ?>
                             <span class="selected-source-chip">
                                 <?= e((string) ($item['name'] ?? $item['id'] ?? '')) ?>
                                 <code><?= e((string) ($item['id'] ?? '')) ?></code>
                             </span>
                         <?php endforeach; ?>
                     </div>
                 </div>
             </div>
         <?php endif; ?>

         <div class="glass-card" style="margin-bottom:1.5rem;">
             <div class="card-header">
                 <h3 class="card-title"><?= e($title) ?> · Top-<?= count($results) ?></h3>
                 <p class="muted" style="font-size:.82rem;">Kết quả được xếp theo mức đồng thuận giữa các nguồn và điểm dự đoán trung bình của mô hình cải tiến, đồng thời hiển thị điểm mô hình gốc để đối chiếu.</p>
             </div>
             <div class="table-wrap">
                 <table class="table result-table">
                     <thead>
                         <tr>
                             <th style="width:48px">#</th>
                             <th>Tên</th>
                             <th>ID</th>
                             <th>Nguồn khớp</th>
                             <th>Điểm dự đoán<br><span class="muted" style="font-size:.68rem;font-weight:500;">Cải tiến / Gốc</span></th>
                         </tr>
                     </thead>
                     <tbody>
                     <?php if ($results === []): ?>
                         <tr>
                             <td colspan="5" class="muted" style="padding:1rem 1.25rem;">Không có kết quả phù hợp trong nhóm dự đoán này.</td>
                         </tr>
                     <?php else: ?>
                         <?php foreach ($results as $i => $row): ?>
                             <?php
                             $improvedScore = (float) ($row['improved_score'] ?? $row['score'] ?? 0);
                             $originalScore = array_key_exists('original_score', $row) && $row['original_score'] !== null
                                 ? (float) $row['original_score']
                                 : null;
                             ?>
                             <tr>
                                 <td><span class="rank-badge"><?= $i + 1 ?></span></td>
                                 <td><strong><?= e((string) ($row['name'] ?? $row['id'] ?? '')) ?></strong></td>
                                 <td><code style="font-size:.8rem;color:#93c5fd;"><?= e((string) ($row['id'] ?? '')) ?></code></td>
                                 <td><span class="support-pill"><?= (int) ($row['support_count'] ?? 1) ?> nguồn</span></td>
                                 <td>
                                     <div style="display:grid;gap:.45rem;min-width:220px;">
                                         <div>
                                             <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.2rem;">
                                                 <span style="font-size:.72rem;color:#94a3b8;">Cải tiến</span>
                                                 <span style="min-width:52px;font-weight:600;color:#22c55e;text-align:right;"><?= format_score($improvedScore) ?></span>
                                             </div>
                                             <div class="score-bar" style="width:<?= (int) ($improvedScore * 120) ?>px"></div>
                                         </div>
                                         <div>
                                             <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.2rem;">
                                                 <span style="font-size:.72rem;color:#94a3b8;">Gốc</span>
                                                 <span style="min-width:52px;font-weight:600;color:#60a5fa;text-align:right;"><?= $originalScore !== null ? format_score($originalScore) : '—' ?></span>
                                             </div>
                                             <div class="score-bar" style="width:<?= $originalScore !== null ? (int) ($originalScore * 120) : 4 ?>px;background:linear-gradient(90deg,#2563eb,#93c5fd);opacity:<?= $originalScore !== null ? '1' : '.25' ?>;"></div>
                                         </div>
                                     </div>
                                 </td>
                             </tr>
                         <?php endforeach; ?>
                     <?php endif; ?>
                     </tbody>
                 </table>
             </div>
         </div>

         <?php if (!empty($graph['nodes'])): ?>
             <div class="glass-card">
                 <div class="card-header">
                     <h3 class="card-title"><span class="material-symbols-outlined" style="vertical-align:-5px;font-size:18px;">hub</span> Đồ thị liên kết phân tử (2D)</h3>
                     <p class="muted" style="font-size:.82rem;">Hover vào node để xem cấu trúc hóa học, protein, hoặc mở cùng dữ liệu trong không gian 3D.</p>
                 </div>
                 <?php if ($graphInsight !== ''): ?>
                     <div class="alert" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.25);margin-bottom:1rem;">
                         <?= e($graphInsight) ?>
                     </div>
                 <?php endif; ?>
                 <?= render_prediction_graph($graph) ?>
             </div>
         <?php endif; ?>
     </section>
     <?php
     return (string) ob_get_clean();
 }

 if ($_SERVER['REQUEST_METHOD'] === 'POST') {
     $submitted = (string) ($_POST['csrf_token'] ?? '');
     if (!hash_equals($csrfToken, $submitted)) {
         $error = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    } elseif (!$apiHealthy) {
        $error = 'Python API đang ngoại tuyến. Vui lòng khởi động FastAPI ở cổng 8000.';
    } elseif ($drugInputs === [] && $diseaseInputs === []) {
        $error = 'Vui lòng nhập tên thuốc hoặc tên bệnh.';
    } else {
       try {
           $totalSelected = count($drugInputs) + count($diseaseInputs);

            if ($drugInputs !== [] && $diseaseInputs !== []) {
                $pairMatrixData = PredictionService::callPairMatrixApi($drugInputs, $diseaseInputs, $dataset);
                flash('success', 'Đã chấm điểm cho từng cặp thuốc-bệnh đã chọn.');
            } else {
                if ($drugInputs !== []) {
                    $drugPayloads = [];
                    foreach ($drugInputs as $value) {
                        $drugPayloads[] = PredictionService::callPythonApi('drug_to_disease', $value, $topK, $dataset);
                    }
                    $resultGroups[] = aggregate_prediction_group($drugInputs, 'drug_to_disease', $topK, $dataset, $drugPayloads);

                    if ($totalSelected === 1 && !empty($drugPayloads[0]['matched_input'])) {
                        PredictionService::saveHistory((int) $user['id'], 'drug_to_disease', (string) $drugInputs[0], $topK, $drugPayloads[0]);
                    }
                }

                if ($diseaseInputs !== []) {
                    $diseasePayloads = [];
                    foreach ($diseaseInputs as $value) {
                        $diseasePayloads[] = PredictionService::callPythonApi('disease_to_drug', $value, $topK, $dataset);
                    }
                    $resultGroups[] = aggregate_prediction_group($diseaseInputs, 'disease_to_drug', $topK, $dataset, $diseasePayloads);

                    if ($totalSelected === 1 && !empty($diseasePayloads[0]['matched_input'])) {
                        PredictionService::saveHistory((int) $user['id'], 'disease_to_drug', (string) $diseaseInputs[0], $topK, $diseasePayloads[0]);
                    }
                }

                if ($resultGroups !== []) {
                    $resultData = $resultGroups[0];
                    flash('success', $totalSelected > 1 ? 'Đã tổng hợp dự đoán từ nhiều nguồn.' : 'Dự đoán thành công.');
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

 $success      = flash('success');
 $counts       = dataset_counts($dataset);
 $entityChoices = load_dataset_entities($dataset);
$drugChoices   = $entityChoices['drugs'];
$diseaseChoices = $entityChoices['diseases'];
$hasPairMatrix = is_array($pairMatrixData) && $pairMatrixData !== [];
$hasGraphResults = !empty($resultGroups) || $hasPairMatrix;
$hasResults   = $hasPairMatrix || !empty($resultGroups);
$matchedInput = $resultData['matched_input'] ?? null;
$results      = $resultData['results'] ?? [];
$graphData    = $resultData['graph'] ?? ['nodes' => [], 'links' => []];
$noteText     = $resultData['note'] ?? '';
?>
 <!DOCTYPE html>
 <html lang="vi">
 <head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>AMNTDDA AI · Dự đoán Thuốc – Bệnh</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap">
    <style>
        /* ── Graph 2D ── */
        .graph-2d-wrapper { position: relative; margin-top: 1.5rem; }
        .graph-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: rgba(255,255,255,.04); border-radius: 8px 8px 0 0; border: 1px solid rgba(255,255,255,.1); border-bottom: none; }
        .graph-legend { display: flex; gap: 1.2rem; font-size: .78rem; }
        .legend-item { display: flex; align-items: center; gap: .35rem; color: var(--text-muted, #94a3b8); }
        .legend-drug { color: #60a5fa; }
        .legend-protein { color: #fcd34d; }
        .legend-disease { color: #f87171; }
        .prediction-network { width: 100%; background: rgba(10,18,35,.65); border: 1px solid rgba(255,255,255,.1); border-radius: 0 0 10px 10px; display: block; }
        .graph-col-label { fill: #94a3b8; font-size: 13px; font-weight: 600; letter-spacing: .03em; }
        .graph-node { cursor: pointer; transition: opacity .15s; }
        .graph-node:hover { opacity: .85; }
        .graph-node-label { fill: #e2e8f0; font-size: 11px; font-weight: 500; }
        .graph-node-label-left { text-anchor: start; }
        .graph-node-sublabel { fill: #94a3b8; font-size: 9.5px; }
        .graph-edge { pointer-events: none; }
        .graph-panel-bg { fill: rgba(15,23,42,.55); stroke: rgba(148,163,184,.12); stroke-width: 1; }
        .molecule-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-top: 18px; }
        .molecule-card { background: rgba(8,15,29,.76); border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 16px; min-height: 276px; }
        .molecule-card-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .molecule-card-title { color: #f8fafc; font-size: .92rem; font-weight: 600; line-height: 1.3; }
        .molecule-card-meta { color: #94a3b8; font-size: .72rem; }
        .molecule-card canvas { width: 280px; max-width: 100%; height: 184px; display: block; margin: 0 auto; background: radial-gradient(circle at center, rgba(59,130,246,.08), rgba(15,23,42,0)); border-radius: 12px; }
        .graph-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 9px; font-size: .68rem; font-weight: 700; }
        .graph-chip-source { background: rgba(96,165,250,.14); color: #93c5fd; }
        .graph-chip-target { background: rgba(34,197,94,.12); color: #86efac; }
        /* Tooltip */
        .graph-tooltip { display: none; position: fixed; z-index: 9000; background: rgba(15,23,42,.97); border: 1px solid rgba(255,255,255,.15); border-radius: 10px; padding: 10px 12px; min-width: 200px; max-width: 260px; pointer-events: none; box-shadow: 0 8px 32px rgba(0,0,0,.5); }
        .tooltip-body { font-size: 12px; color: #e2e8f0; line-height: 1.55; }
        .tooltip-body strong { color: #f8fafc; font-size: 13px; display: block; margin-bottom: 4px; }
        .tooltip-body code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 4px; font-size: 10.5px; color: #93c5fd; }
        /* 3D modal */
        #modal-3d { display: none; position: fixed; inset: 0; z-index: 8000; background: rgba(5,10,20,.96); flex-direction: column; }
        .modal-3d-header { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,.1); }
        .modal-3d-header h3 { margin: 0; font-size: 1rem; color: #e2e8f0; }
        #canvas-3d { flex: 1; display: block; width: 100%; }
        /* Result table */
        .result-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
        .result-table .rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: rgba(96,165,250,.18); color: #60a5fa; font-size: 11px; font-weight: 700; }
        .score-bar-wrap { display: flex; align-items: center; gap: .5rem; }
        .score-bar { height: 6px; border-radius: 3px; background: linear-gradient(90deg, #22c55e, #86efac); min-width: 4px; }
        .support-pill { display: inline-flex; align-items: center; justify-content: center; padding: .25rem .55rem; border-radius: 999px; background: rgba(96,165,250,.12); color: #bfdbfe; font-size: .74rem; font-weight: 600; }
        .prediction-group-section + .prediction-group-section { margin-top: 2rem; }
        .pair-chart-legend { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; color: #94a3b8; font-size: .78rem; }
        .pair-chart-legend-item { display: inline-flex; align-items: center; gap: .45rem; }
        .pair-chart-swatch { display: inline-block; width: 16px; height: 8px; border-radius: 999px; }
        .pair-chart-swatch-improved { background: linear-gradient(90deg, #22c55e, #86efac); }
        .pair-chart-swatch-original { background: linear-gradient(90deg, #2563eb, #93c5fd); }
        .pair-chart-scroll { overflow-x: auto; padding-bottom: .35rem; }
        .pair-chart-svg { width: 100%; height: auto; display: block; min-width: 780px; }
        .pair-chart-grid { stroke: rgba(148,163,184,.16); stroke-width: 1; }
        .pair-chart-axis-label { fill: #94a3b8; font-size: 10px; }
        .pair-chart-label { fill: #94a3b8; font-size: 10px; }
        .pair-chart-bar-improved { fill: #22c55e; }
        .pair-chart-bar-original { fill: #60a5fa; }
        .delta-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 76px; padding: .32rem .6rem; border-radius: 999px; font-size: .74rem; font-weight: 700; }
        .delta-pill.is-positive { background: rgba(34,197,94,.12); color: #86efac; }
        .delta-pill.is-negative { background: rgba(239,68,68,.12); color: #fca5a5; }
        .delta-pill.is-neutral { background: rgba(148,163,184,.14); color: #cbd5e1; }
        .pair-matrix-scroll { overflow-x: auto; }
        .pair-matrix-table { min-width: 900px; }
        .pair-matrix-table th { white-space: normal; vertical-align: top; }
        .pair-matrix-heading { display: flex; flex-direction: column; gap: 4px; min-width: 160px; }
        .pair-matrix-heading code { color: #93c5fd; font-size: .72rem; }
        .pair-matrix-cell { min-width: 170px; display: grid; gap: .45rem; }
        .pair-matrix-score { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .74rem; }
        .pair-matrix-score-label { color: #94a3b8; }
        .pair-matrix-score strong { color: #f8fafc; font-size: .8rem; }
        .pair-matrix-delta { font-size: .72rem; font-weight: 700; }
        .pair-matrix-delta.is-positive { color: #86efac; }
        .pair-matrix-delta.is-negative { color: #fca5a5; }
        .pair-matrix-delta.is-neutral { color: #cbd5e1; }
        /* Predict form */
        .query-toggle { display: flex; gap: 0; border: 1px solid rgba(255,255,255,.15); border-radius: 8px; overflow: hidden; margin-bottom: 1rem; }
        .query-toggle label { flex: 1; text-align: center; padding: .55rem .75rem; font-size: .82rem; cursor: pointer; color: #94a3b8; transition: background .15s, color .15s; }
        .query-toggle input[type=radio]:checked + label { background: rgba(96,165,250,.2); color: #60a5fa; font-weight: 600; }
        .query-toggle input[type=radio] { display: none; }
        .matched-card { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.3); border-radius: 10px; margin-bottom: 1.5rem; }
        .matched-icon { font-size: 2rem; flex-shrink: 0; }
        .matched-details h4 { margin: 0 0 .2rem; font-size: 1rem; }
        .matched-details .smiles-preview { font-size: .72rem; color: #94a3b8; margin-top: .35rem; word-break: break-all; }
        .selected-source-tags { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .8rem; }
        .selected-source-chip { display: inline-flex; align-items: center; gap: .45rem; padding: .35rem .65rem; border-radius: 999px; background: rgba(255,255,255,.08); color: #e2e8f0; font-size: .76rem; }
        .selected-source-chip code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 4px; color: #93c5fd; }
        .picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .picker-panel { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 1rem; }
        .picker-panel-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .35rem; }
        .picker-panel-meta { color: #94a3b8; font-size: .72rem; }
        .picker-panel-note { margin: 0 0 .85rem; color: #94a3b8; font-size: .78rem; line-height: 1.5; }
        .entity-picker-dark { background: rgba(8,15,29,.78); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 10px; }
        .entity-picker-dark .entity-picker-tags { display: flex; flex-wrap: wrap; gap: 8px; min-height: 24px; margin-bottom: 10px; }
        .entity-picker-dark .entity-picker-tag { display: inline-flex; align-items: center; gap: 8px; padding: 5px 10px; border-radius: 999px; background: rgba(96,165,250,.14); color: #dbeafe; font-size: .76rem; }
        .entity-picker-dark .entity-picker-tag button { border: 0; background: transparent; color: #bfdbfe; cursor: pointer; padding: 0; line-height: 1; font-size: 14px; }
        .entity-picker-dark .entity-picker-search { width: 100%; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); color: #e2e8f0; border-radius: 10px; padding: .75rem .9rem; }
        .entity-picker-dark .entity-picker-search:focus { outline: none; border-color: rgba(96,165,250,.65); box-shadow: 0 0 0 3px rgba(96,165,250,.12); }
        .entity-picker-dark .entity-picker-list { margin-top: 10px; max-height: 248px; overflow: auto; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; background: rgba(2,6,23,.58); }
        .entity-picker-dark .entity-picker-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: .7rem .85rem; border-bottom: 1px solid rgba(255,255,255,.05); cursor: pointer; }
        .entity-picker-dark .entity-picker-item:last-child { border-bottom: none; }
        .entity-picker-dark .entity-picker-item:hover,
        .entity-picker-dark .entity-picker-item.is-selected { background: rgba(96,165,250,.12); }
        .entity-picker-dark .entity-picker-item-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .entity-picker-dark .entity-picker-item-name { color: #f8fafc; font-size: .84rem; }
        .entity-picker-dark .entity-picker-item-id { color: #93c5fd; font-size: .72rem; }
        .entity-picker-dark .entity-picker-check { color: #86efac; font-size: .76rem; font-weight: 700; }
        .entity-picker-dark .entity-picker-empty { padding: .9rem; color: #94a3b8; text-align: center; font-size: .8rem; }
        .entity-picker-dark .entity-picker-footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: 10px; color: #94a3b8; font-size: .74rem; }
        .entity-picker-dark .entity-picker-clear { border: 0; background: transparent; color: #93c5fd; cursor: pointer; padding: 0; }
        @media (max-width: 980px) {
            .picker-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div id="loader" class="loading-overlay" style="display:none;">Đang chạy mô hình AI...</div>

<!-- 3D Fullscreen Modal -->
<div id="modal-3d">
    <div class="modal-3d-header">
        <h3><span class="material-symbols-outlined" style="vertical-align:-5px;font-size:18px;margin-right:6px;">view_in_ar</span>Đồ thị liên kết 3D</h3>
        <button class="btn btn-ghost btn-sm" onclick="close3DModal()" type="button">
            <span class="material-symbols-outlined" style="font-size:16px">close</span> Đóng
        </button>
    </div>
    <div id="canvas-3d"></div>
</div>

<div class="container">
    <div class="navbar">
        <div>
            <div class="brand">AMNTDDA AI</div>
            <div class="muted">Nền tảng dự đoán liên kết Thuốc – Bệnh dựa trên đồ thị HGT.</div>
        </div>
        <div class="nav-links">
            <a class="btn btn-sm" href="#predict-form-panel">Bắt đầu dự đoán</a>
            <a class="btn btn-ghost btn-sm" href="history.php">Lịch sử</a>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a class="btn btn-ghost btn-sm" href="admin.php">Quản trị</a>
            <?php endif; ?>
            <a class="btn btn-danger btn-sm" href="logout.php">Đăng xuất</a>
        </div>
    </div>

    <div class="app-shell">
        <aside class="side-nav glass-card">
            <div class="side-nav-title-wrap">
                <div class="side-nav-title">AMNTDDA</div>
                <div class="side-nav-meta">Precision Medical AI</div>
            </div>
            <div class="side-nav-menu">
                <a class="side-nav-item side-nav-item-active" href="#overview">
                    <span class="material-symbols-outlined">dashboard</span><span>Tổng quan</span>
                </a>
                <a class="side-nav-item" href="#predict-form-panel">
                    <span class="material-symbols-outlined">biotech</span><span>Dự đoán</span>
                </a>
                <a class="side-nav-item" href="<?= $hasResults ? '#result-section' : '#predict-form-panel' ?>">
                    <span class="material-symbols-outlined"><?= $hasResults ? 'insights' : 'menu_book' ?></span>
                    <span><?= $hasResults ? 'Kết quả' : 'Hướng dẫn' ?></span>
                </a>
                <a class="side-nav-item" href="history.php">
                    <span class="material-symbols-outlined">history</span><span>Lịch sử</span>
                </a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a class="side-nav-item" href="admin.php">
                        <span class="material-symbols-outlined">admin_panel_settings</span><span>Quản trị</span>
                    </a>
                <?php endif; ?>
            </div>
        </aside>

        <div class="main-shell">

            <!-- ── Overview Hero ── -->
            <div class="glass-card hero-banner" id="overview">
                <div class="hero-grid">
                    <div class="hero-pitch">
                        <span class="badge badge-drug">AMNTDDA · Graph Neural Network</span>
                        <h1>Dự đoán liên kết Thuốc – Bệnh bằng AI đồ thị.</h1>
                        <p class="muted">Chọn một hay nhiều thuốc, bệnh và để mô hình HGT tổng hợp kết quả theo từng chiều dự đoán, kèm đồ thị phân tử 2D &amp; 3D trực quan hơn.</p>
                        <div class="hero-actions">
                            <a class="btn" href="#predict-form-panel">Bắt đầu dự đoán</a>
                            <?php if ($hasResults): ?>
                                <a class="btn btn-ghost" href="#result-section">Xem kết quả hiện tại</a>
                            <?php endif; ?>
                        </div>
                        <div class="hero-bullets">
                            <div class="hero-bullet">Có thể chọn nhiều thuốc, nhiều bệnh và hệ thống sẽ tách thành các nhóm phân tích riêng.</div>
                            <div class="hero-bullet">Đồ thị 2D được bố trí theo tầng để giảm chồng chéo đường liên kết.</div>
                            <div class="hero-bullet">Card cấu trúc phân tử lớn hơn để người dùng nhìn rõ từng mục.</div>
                        </div>
                    </div>
                    <div class="status-card">
                        <div class="label">Trạng thái hệ thống</div>
                        <span class="badge <?= $apiHealthy ? 'badge-success' : 'badge-neutral' ?>">
                            <?= $apiHealthy ? 'AI API · Trực tuyến' : 'AI API · Ngoại tuyến' ?>
                        </span>
                        <p class="muted"><?= $apiHealthy ? 'Server FastAPI sẵn sàng phục vụ.' : 'Hãy khởi động Python API ở cổng 8000.' ?></p>
                        <div class="status-card-row"><span>Bộ dữ liệu</span><span><?= e($dataset) ?></span></div>
                        <div class="status-card-row"><span>Thuốc</span><span><?= number_format($counts['drugs']) ?></span></div>
                        <div class="status-card-row"><span>Bệnh</span><span><?= number_format($counts['diseases']) ?></span></div>
                        <div class="status-card-row"><span>Cặp đã biết</span><span><?= number_format($counts['pairs']) ?></span></div>
                    </div>
                </div>
            </div>

            <!-- ── Flash / Error ── -->
            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- ── Prediction Form ── -->
            <div class="glass-card" id="predict-form-panel" style="margin-top:1.5rem;">
                <div class="card-header">
                    <h2 class="card-title"><span class="material-symbols-outlined" style="vertical-align:-5px;">biotech</span> Dự đoán liên kết</h2>
                    <p class="muted">Chọn tối đa 5 thuốc và 5 bệnh. Nếu chọn cả hai cột, hệ thống sẽ chấm mọi cặp thuốc-bệnh đã chọn và so sánh trực tiếp 2 model.</p>
                </div>
                <form method="POST" action="" id="predict-form" onsubmit="return handlePredictSubmit()">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <p class="muted" style="font-size:.8rem;margin:0 0 .85rem;">
                        Chọn <strong>một hoặc nhiều</strong> nguồn trong từng cột. Nếu chỉ chọn một bên, hệ thống chạy <strong>Top-K một chiều</strong>. Nếu chọn cả hai bên, hệ thống sẽ <strong>chấm toàn bộ cặp thuốc × bệnh đã chọn</strong>.
                    </p>
                    <div class="picker-grid">
                        <div class="picker-panel">
                            <div class="picker-panel-head">
                                <label class="form-label">⬡ Tên thuốc</label>
                                <span class="picker-panel-meta">Tối đa 5 nguồn</span>
                            </div>
                            <p class="picker-panel-note">Chọn 1–5 thuốc. Nếu cột bệnh cũng có dữ liệu, từng thuốc sẽ được chấm với từng bệnh đã chọn.</p>
                            <div id="drug-picker-host" class="entity-picker entity-picker-dark"></div>
                        </div>
                        <div class="picker-panel">
                            <div class="picker-panel-head">
                                <label class="form-label">✚ Tên bệnh</label>
                                <span class="picker-panel-meta">Tối đa 5 nguồn</span>
                            </div>
                            <p class="picker-panel-note">Chọn 1–5 bệnh. Nếu cột thuốc cũng có dữ liệu, từng bệnh sẽ được chấm với từng thuốc đã chọn.</p>
                            <div id="disease-picker-host" class="entity-picker entity-picker-dark"></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="top_k">Top-K (1 chiều)</label>
                            <select class="form-control" name="top_k" id="top_k" style="width:110px;">
                                <?php foreach ([5, 10, 15, 20] as $k): ?>
                                    <option value="<?= $k ?>" <?= $topK === $k ? 'selected' : '' ?>><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="dataset">Dataset</label>
                            <select class="form-control" name="dataset" id="dataset" style="width:120px;">
                                <?php foreach (['B-dataset', 'C-dataset', 'F-dataset'] as $ds): ?>
                                    <option value="<?= $ds ?>" <?= $dataset === $ds ? 'selected' : '' ?>><?= $ds ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:1rem;">
                        <button class="btn" type="submit" <?= !$apiHealthy ? 'disabled' : '' ?>>
                            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px">search</span>
                            Chạy dự đoán
                        </button>
                        <?php if (!$apiHealthy): ?>
                            <span class="muted" style="margin-left:1rem;font-size:.82rem;">API ngoại tuyến</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- ── Results ── -->
            <?php if ($hasResults): ?>
            <div id="result-section" style="margin-top:2rem;">
                <?php if ($hasPairMatrix): ?>
                    <?= render_pair_matrix_section($pairMatrixData) ?>
                <?php else: ?>
                    <?php foreach ($resultGroups as $group): ?>
                        <?= render_prediction_group_section($group) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Quick-start guide -->
            <div class="glass-card" id="quick-start" style="margin-top:2rem;">
                <div class="card-header"><h3 class="card-title">Cách sử dụng</h3></div>
                <ol style="padding-left:1.5rem;line-height:2;color:#94a3b8;">
                    <li>Chọn tối đa 5 thuốc và 5 bệnh ngay trong hai picker trực quan.</li>
                    <li>Nếu chỉ chọn một bên, hệ thống sẽ chạy Top-K một chiều như trước.</li>
                    <li>Nếu chọn cả hai bên, hệ thống sẽ chấm mọi cặp thuốc × bệnh đã chọn và vẽ biểu đồ so sánh 2 model.</li>
                    <li>Top-K chỉ áp dụng cho chế độ một chiều; ở chế độ cặp chính xác, toàn bộ cặp đã chọn sẽ được hiển thị.</li>
                    <li>Nhấn <strong>Chạy dự đoán</strong> để xem bảng điểm từng cặp, ma trận thuốc × bệnh, hoặc đồ thị phân tử khi chạy một chiều.</li>
                </ol>
            </div>
            <?php endif; ?>

        </div><!-- end main-shell -->
    </div><!-- end app-shell -->
</div><!-- end container -->

<!-- SmilesDrawer -->
<script src="https://cdn.jsdelivr.net/npm/smiles-drawer@1.0.10/dist/smiles-drawer.min.js"></script>
<?php if ($hasGraphResults): ?>
<script src="https://unpkg.com/three@0.161.0/build/three.min.js"></script>
<script src="https://unpkg.com/three-spritetext@1.9.0/dist/three-spritetext.min.js"></script>
<script src="https://unpkg.com/3d-force-graph"></script>
<?php endif; ?>

<script>
// Hide loader on page load (in case POST returned with errors)
document.getElementById('loader').style.display = 'none';

function handlePredictSubmit() {
    const apiHealthy = <?= $apiHealthy ? 'true' : 'false' ?>;
    if (!apiHealthy) {
        alert('Python API đang ngoại tuyến. Vui lòng khởi động FastAPI ở cổng 8000 trước khi chạy dự đoán.');
        return false;
    }
    // Check if at least one entity is selected
    const drugInputs = document.querySelectorAll('input[name="drug_input[]"]');
    const diseaseInputs = document.querySelectorAll('input[name="disease_input[]"]');
    if (drugInputs.length === 0 && diseaseInputs.length === 0) {
        alert('Vui lòng chọn ít nhất một thuốc hoặc một bệnh.');
        return false;
    }
    document.getElementById('loader').style.display = 'flex';
    return true;
}

const pickerBootstrap = {
    drugs: <?= json_encode($drugChoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    diseases: <?= json_encode($diseaseChoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    selectedDrugs: <?= json_encode($drugInputs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    selectedDiseases: <?= json_encode($diseaseInputs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
};

const dsEl = document.getElementById('dataset');
if (dsEl) {
    dsEl.addEventListener('change', () => {
        window.location.href = `?dataset=${encodeURIComponent(dsEl.value)}`;
    });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char] || char));
}

function initEntityPicker(hostId, config) {
    const host = document.getElementById(hostId);
    if (!host) return;

    const rawOptions = Array.isArray(config.options) ? config.options : [];
    const maxSelected = Number(config.maxSelected || 5);
    const normalizedOptions = rawOptions.map((option) => ({
        id: String(option.id || '').trim(),
        name: String(option.name || option.id || '').trim(),
    })).filter((option) => option.id && option.name).map((option) => ({
        ...option,
        idLower: option.id.toLowerCase(),
        nameLower: option.name.toLowerCase(),
        search: `${option.name} ${option.id}`.toLowerCase(),
    }));

    const byId = new Map(normalizedOptions.map((option) => [option.idLower, option]));
    const byName = new Map(normalizedOptions.map((option) => [option.nameLower, option]));
    const initialSelection = Array.isArray(config.selected) ? config.selected : [];
    const selectedIds = [];

    initialSelection.forEach((value) => {
        const token = String(value || '').trim().toLowerCase();
        const resolved = byId.get(token) || byName.get(token);
        const resolvedId = resolved ? resolved.id : String(value || '').trim();
        if (!resolvedId || selectedIds.includes(resolvedId)) return;
        if (selectedIds.length >= maxSelected) return;
        selectedIds.push(resolvedId);
    });

    host.innerHTML = `
        <div class="entity-picker-tags"></div>
        <input type="text" class="entity-picker-search" placeholder="Tìm theo tên hoặc ID...">
        <div class="entity-picker-list"></div>
        <div class="entity-picker-footer">
            <span class="entity-picker-count"></span>
            <button type="button" class="entity-picker-clear">Xóa hết</button>
        </div>
        <div class="entity-picker-hidden"></div>
    `;

    const tagsEl = host.querySelector('.entity-picker-tags');
    const searchEl = host.querySelector('.entity-picker-search');
    const listEl = host.querySelector('.entity-picker-list');
    const countEl = host.querySelector('.entity-picker-count');
    const clearEl = host.querySelector('.entity-picker-clear');
    const hiddenEl = host.querySelector('.entity-picker-hidden');

    const render = () => {
        const query = (searchEl.value || '').trim().toLowerCase();

        tagsEl.innerHTML = selectedIds.length
            ? selectedIds.map((id) => {
                const option = normalizedOptions.find((item) => item.id === id);
                const label = option ? option.name : id;
                return `<span class="entity-picker-tag">${escapeHtml(label)}<button type="button" data-remove="${escapeHtml(id)}">×</button></span>`;
            }).join('')
            : '<span class="muted" style="font-size:.76rem;">Chưa chọn mục nào.</span>';

        hiddenEl.innerHTML = selectedIds.map((id) => `<input type="hidden" name="${escapeHtml(config.inputName)}" value="${escapeHtml(id)}">`).join('');

        const filtered = normalizedOptions.filter((option) => !query || option.search.includes(query));
        const limited = filtered.slice(0, 80);
        listEl.innerHTML = limited.length
            ? limited.map((option) => {
                const selected = selectedIds.includes(option.id);
                return `
                    <div class="entity-picker-item${selected ? ' is-selected' : ''}" data-option="${escapeHtml(option.id)}">
                        <div class="entity-picker-item-main">
                            <span class="entity-picker-item-name">${escapeHtml(option.name)}</span>
                            <span class="entity-picker-item-id">${escapeHtml(option.id)}</span>
                        </div>
                        <span class="entity-picker-check">${selected ? 'Đã chọn' : ''}</span>
                    </div>
                `;
            }).join('')
            : '<div class="entity-picker-empty">Không có mục nào khớp với bộ lọc hiện tại.</div>';

        countEl.textContent = `Đã chọn ${selectedIds.length}/${maxSelected} nguồn · ${normalizedOptions.length} lựa chọn`;
    };

    const toggleValue = (value) => {
        const index = selectedIds.indexOf(value);
        if (index >= 0) {
            selectedIds.splice(index, 1);
            render();
            return;
        }

        if (selectedIds.length >= maxSelected) {
            countEl.textContent = `Bạn chỉ nên chọn tối đa ${maxSelected} nguồn cho mỗi bên để tránh quá tải giao diện.`;
            return;
        }

        selectedIds.push(value);
        render();
    };

    tagsEl.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-remove]');
        if (!button) return;
        toggleValue(button.dataset.remove || '');
    });

    listEl.addEventListener('click', (event) => {
        const item = event.target.closest('.entity-picker-item[data-option]');
        if (!item) return;
        toggleValue(item.dataset.option || '');
    });

    searchEl.addEventListener('input', render);
    searchEl.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const firstOption = listEl.querySelector('.entity-picker-item[data-option]');
        if (firstOption) {
            toggleValue(firstOption.dataset.option || '');
        }
    });

    clearEl.addEventListener('click', () => {
        selectedIds.splice(0, selectedIds.length);
        render();
    });

    render();
}

(function initEntityPickers() {
    initEntityPicker('drug-picker-host', {
        options: pickerBootstrap.drugs,
        selected: pickerBootstrap.selectedDrugs,
        inputName: 'drug_input[]',
        maxSelected: 5,
    });
    initEntityPicker('disease-picker-host', {
        options: pickerBootstrap.diseases,
        selected: pickerBootstrap.selectedDiseases,
        inputName: 'disease_input[]',
        maxSelected: 5,
    });
})();

function createSmilesDrawer(width, height) {
    if (!window.SmilesDrawer) return null;
    try {
        return new SmilesDrawer.Drawer({
            width: width,
            height: height,
            themes: {
                dark: {
                    C: '#e2e8f0',
                    O: '#f87171',
                    N: '#60a5fa',
                    S: '#fcd34d',
                    P: '#a78bfa',
                    F: '#34d399',
                    CL: '#34d399',
                    BR: '#fb923c',
                    I: '#c084fc',
                    BACKGROUND: 'transparent',
                },
            },
        });
    } catch (err) {
        console.warn('SmilesDrawer.Drawer init failed, trying fallback:', err);
        return new SmilesDrawer.Drawer({ width: width, height: height });
    }
}

(function renderStaticMolecules() {
    if (!window.SmilesDrawer) {
        console.warn('SmilesDrawer library not loaded');
        return;
    }

    document.querySelectorAll('.molecule-canvas[data-smiles]').forEach((canvas) => {
        const smiles = canvas.dataset.smiles || '';
        if (!smiles) return;

        const w = canvas.width || 280;
        const h = canvas.height || 184;
        const drawer = createSmilesDrawer(w, h);
        if (!drawer) return;

        SmilesDrawer.parse(smiles, (tree) => {
            drawer.draw(tree, canvas.id, 'dark', false);
        }, (err) => {
            console.warn('SMILES parse failed for', canvas.id, ':', smiles, err);
        });
    });
})();

 (function initGraphTooltips() {
    document.querySelectorAll('.graph-2d-wrapper').forEach((wrapper, index) => {
        const svg = wrapper.querySelector('.prediction-network');
        const tooltip = wrapper.querySelector('.graph-tooltip');
        const canvas = wrapper.querySelector('.graph-tooltip-canvas');
        const body = wrapper.querySelector('.tooltip-body');

        if (!svg || !tooltip || !canvas || !body) return;
        if (!canvas.id) {
            canvas.id = `graph-tooltip-canvas-${index + 1}`;
        }

        const drawer = createSmilesDrawer(canvas.width || 220, canvas.height || 180);
        const hideTooltip = () => {
            tooltip.style.display = 'none';
            canvas.style.display = 'none';
        };

        svg.addEventListener('mouseover', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const node = target.closest('.graph-node');
            if (!node) return;

            const type = node.dataset.type || '';
            const label = node.dataset.label || '';
            const id = node.dataset.id || '';
            const smiles = node.dataset.smiles || '';
            const seqLen = parseInt(node.dataset.seqLen || '0', 10);
            const anchor = node.querySelector('circle, polygon, rect, text');
            if (!anchor) return;

            const bounds = anchor.getBoundingClientRect();
            tooltip.style.left = `${bounds.right + 14}px`;
            tooltip.style.top = `${Math.max(8, bounds.top - 20)}px`;
            tooltip.style.display = 'block';

            if (type === 'drug' && smiles && drawer) {
                canvas.style.display = 'block';
                SmilesDrawer.parse(smiles, (tree) => {
                    drawer.draw(tree, canvas.id, 'dark', false);
                }, () => {
                    canvas.style.display = 'none';
                });
                const smilesShort = smiles.length > 48 ? `${smiles.slice(0, 48)}…` : smiles;
                body.innerHTML = `<strong>${escapeHtml(label)}</strong><code>${escapeHtml(id)}</code><br><span style="color:#94a3b8;font-size:10px;">SMILES: ${escapeHtml(smilesShort)}</span>`;
            } else if (type === 'protein') {
                canvas.style.display = 'none';
                body.innerHTML = `<strong>${escapeHtml(label)}</strong><code>${escapeHtml(id)}</code><br>Độ dài chuỗi: <strong style="color:#fcd34d;">${seqLen || '?'} aa</strong>`;
            } else {
                canvas.style.display = 'none';
                body.innerHTML = `<strong>${escapeHtml(label)}</strong><code>${escapeHtml(id)}</code>`;
            }
        });

        svg.addEventListener('mouseleave', hideTooltip);
    });
})();

function open3DModal(trigger) {
    const modal = document.getElementById('modal-3d');
    if (!modal) return;
    const wrapper = trigger && trigger.closest ? trigger.closest('.graph-2d-wrapper') : document.querySelector('.graph-2d-wrapper');
    if (!wrapper) return;
    window._graph3dWrapper = wrapper;
    modal.style.display = 'flex';
    requestAnimationFrame(() => init3D(wrapper));
}

function close3DModal() {
    const modal = document.getElementById('modal-3d');
    if (modal) modal.style.display = 'none';
    if (window._graph3d && window._graph3d.pauseAnimation) {
        window._graph3d.pauseAnimation();
    }
}

function buildLayered3DNodes(graphData) {
    const nodes = Array.isArray(graphData.nodes) ? graphData.nodes : [];
    const sources = nodes.filter((node) => node && node.is_source);
    const proteins = nodes.filter((node) => node && node.type === 'protein');
    const targets = nodes.filter((node) => node && !node.is_source && node.type !== 'protein');

    const placeNode = (list, x, spreadY, spreadZ) => list.map((node, index) => ({
        ...node,
        fx: x,
        fy: (index - (list.length - 1) / 2) * spreadY,
        fz: ((index % 2 === 0 ? 1 : -1) * spreadZ) + ((index - (list.length - 1) / 2) * 5),
    }));

    return [
        ...placeNode(sources, -190, 52, 16),
        ...placeNode(proteins, -10, 42, 28),
        ...placeNode(targets, 210, 42, 36),
    ];
}

function init3D(wrapper) {
<?php if ($hasGraphResults): ?>
    if (!window.ForceGraph3D || !window.THREE) return;
    if (!wrapper) return;
    const graphData = JSON.parse(wrapper.dataset.graph || '{}');
    const container = document.getElementById('canvas-3d');
    if (!container) return;

    const graphPayload = { nodes: buildLayered3DNodes(graphData), links: graphData.links || [] };

    const colorMap = { drug: 0x2563eb, protein: 0xf59e0b, disease: 0xdc2626 };
    if (!window._graph3d) {
        window._graph3d = ForceGraph3D()(container)
            .backgroundColor('#050b16')
            .width(container.clientWidth || window.innerWidth)
            .height(container.clientHeight || (window.innerHeight - 60))
            .nodeOpacity(1)
            .linkOpacity(0.38)
            .linkWidth((link) => {
                const score = Number(link.score || 0.4);
                return (link.kind === 'prediction' ? 1.4 : 2.2) + score * 2.4;
            })
            .linkDirectionalParticles((link) => link.kind === 'prediction' ? 2 : 4)
            .linkDirectionalParticleWidth((link) => link.kind === 'prediction' ? 2 : 3.4)
            .linkColor((link) => {
                if (link.kind === 'source-protein') return '#f59e0b';
                if (link.kind === 'protein-target') return '#22c55e';
                return '#38bdf8';
            })
            .nodeThreeObject((node) => {
                const group = new THREE.Group();
                const size = node.type === 'protein' ? 5.4 : (node.is_source ? 9 : 7);
                const base = new THREE.Mesh(
                    new THREE.SphereGeometry(size, 28, 28),
                    new THREE.MeshStandardMaterial({
                        color: colorMap[node.type] || 0x94a3b8,
                        emissive: colorMap[node.type] || 0x94a3b8,
                        emissiveIntensity: node.is_source ? 0.45 : 0.22,
                        roughness: 0.25,
                        metalness: 0.35,
                        transparent: true,
                        opacity: 0.98,
                    })
                );
                group.add(base);

                if (node.type === 'drug') {
                    const ring = new THREE.Mesh(
                        new THREE.TorusGeometry(size + 1.6, 0.45, 14, 34),
                        new THREE.MeshBasicMaterial({ color: 0x93c5fd, transparent: true, opacity: 0.75 })
                    );
                    group.add(ring);
                } else if (node.type === 'disease') {
                    const mat = new THREE.MeshBasicMaterial({ color: 0xfda4af, transparent: true, opacity: 0.9 });
                    const hBar = new THREE.Mesh(new THREE.BoxGeometry(size * 1.4, 1.2, 1.2), mat);
                    const vBar = new THREE.Mesh(new THREE.BoxGeometry(1.2, size * 1.4, 1.2), mat);
                    group.add(hBar);
                    group.add(vBar);
                } else {
                    const ring = new THREE.Mesh(
                        new THREE.TorusGeometry(size + 1.1, 0.35, 12, 30),
                        new THREE.MeshBasicMaterial({ color: 0xfcd34d, transparent: true, opacity: 0.7 })
                    );
                    ring.rotation.x = Math.PI / 2;
                    group.add(ring);
                }

                if (window.SpriteText) {
                    const label = new SpriteText(`${node.label || node.actual_id || node.id}`);
                    label.color = '#f8fafc';
                    label.textHeight = node.type === 'protein' ? 4 : 5;
                    label.backgroundColor = 'rgba(5, 11, 22, 0.65)';
                    label.padding = 2;
                    label.borderRadius = 4;
                    label.position.set(0, size + 8, 0);
                    group.add(label);
                }
                return group;
            })
            .onNodeHover((node) => {
                container.style.cursor = node ? 'pointer' : 'default';
            });

        const scene = window._graph3d.scene();
        scene.add(new THREE.AmbientLight(0xffffff, 0.86));
        const keyLight = new THREE.DirectionalLight(0x93c5fd, 1.25);
        keyLight.position.set(180, 160, 140);
        scene.add(keyLight);
        const fillLight = new THREE.PointLight(0x22c55e, 0.8, 600);
        fillLight.position.set(-120, -60, 90);
        scene.add(fillLight);

        const starCount = 600;
        const starPositions = new Float32Array(starCount * 3);
        for (let i = 0; i < starCount; i += 1) {
            starPositions[i * 3] = (Math.random() - 0.5) * 900;
            starPositions[i * 3 + 1] = (Math.random() - 0.5) * 900;
            starPositions[i * 3 + 2] = (Math.random() - 0.5) * 900;
        }
        const starGeometry = new THREE.BufferGeometry();
        starGeometry.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
        const starMaterial = new THREE.PointsMaterial({ color: 0x94a3b8, size: 1.2, transparent: true, opacity: 0.7 });
        scene.add(new THREE.Points(starGeometry, starMaterial));

        if (!window._graph3dResizeBound) {
            window.addEventListener('resize', () => {
                if (!window._graph3d) return;
                window._graph3d.width(container.clientWidth || window.innerWidth);
                window._graph3d.height(container.clientHeight || (window.innerHeight - 60));
            });
            window._graph3dResizeBound = true;
        }
    }

    window._graph3d
        .width(container.clientWidth || window.innerWidth)
        .height(container.clientHeight || (window.innerHeight - 60))
        .graphData(graphPayload);

    if (window._graph3d && window._graph3d.resumeAnimation) {
        window._graph3d.resumeAnimation();
    }

    setTimeout(() => {
        if (window._graph3d && window._graph3d.zoomToFit) {
            window._graph3d.zoomToFit(800, 100);
        }
    }, 180);
<?php else: ?>
    void wrapper;
<?php endif; ?>
}
</script>
</body>
</html>
