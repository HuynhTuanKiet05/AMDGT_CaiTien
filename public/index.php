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

$entities = load_dataset_entities($dataset);
$drugChoices = $entities['drugs'];
$diseaseChoices = $entities['diseases'];

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
    $dir = __DIR__ . '/../AMDGT/data/' . $dataset;
    $base = realpath($dir);
    if ($base === false) {
        $base = $dir;
    }
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
    $dir = __DIR__ . '/../AMDGT/data/' . $dataset;
    $dataDir = realpath($dir);
    if ($dataDir === false) {
        $dataDir = $dir;
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

    $topPad = 24;
    $bottomPad = 36;
    $leftPad = 190;
    $rightPad = 85;
    $groupHeight = 52;
    
    $width = 920;
    $chartWidth = $width - $leftPad - $rightPad;
    $height = $topPad + count($pairs) * $groupHeight + $bottomPad;

    ob_start();
    ?>
    <div class="pair-chart-scroll">
        <svg class="pair-chart-svg" viewBox="0 0 <?= $width ?> <?= $height ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Biểu đồ so sánh hai mô hình">
            <defs>
                <linearGradient id="chart-green-gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#10b981" stop-opacity="0.4"/>
                    <stop offset="100%" stop-color="#34d399" stop-opacity="0.95"/>
                </linearGradient>
                <linearGradient id="chart-blue-gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#2563eb" stop-opacity="0.4"/>
                    <stop offset="100%" stop-color="#60a5fa" stop-opacity="0.95"/>
                </linearGradient>
            </defs>

            <!-- Grid Lines -->
            <?php foreach ([0, 0.25, 0.50, 0.75, 1.00] as $tick): ?>
                <?php $x = $leftPad + $tick * $chartWidth; ?>
                <line x1="<?= $x ?>" y1="<?= $topPad - 10 ?>" x2="<?= $x ?>" y2="<?= $height - $bottomPad ?>" stroke="rgba(255, 255, 255, 0.04)" stroke-dasharray="3 3" />
                <text x="<?= $x ?>" y="<?= $height - $bottomPad + 18 ?>" style="fill: rgba(255, 255, 255, 0.35); font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif;" text-anchor="middle"><?= number_format($tick, 2) ?></text>
            <?php endforeach; ?>

            <?php foreach ($pairs as $index => $pair): ?>
                <?php
                $improvedScore = max(0.0, min(1.0, (float) ($pair['improved_score'] ?? 0)));
                $hasOriginal = array_key_exists('original_score', $pair) && $pair['original_score'] !== null;
                $originalScore = $hasOriginal ? max(0.0, min(1.0, (float) $pair['original_score'])) : 0.0;
                
                $y = $topPad + $index * $groupHeight;
                
                $originalBarWidth = $chartWidth * $originalScore;
                $improvedBarWidth = $chartWidth * $improvedScore;
                
                // Original bar is top (Blue), Improved GNN is bottom (Green)
                $originalY = $y;
                $improvedY = $y + 15;
                
                $drugName = (string) ($pair['drug_name'] ?? $pair['drug_id'] ?? 'Drug');
                $drugId = (string) ($pair['drug_id'] ?? '');
                
                $title = sprintf(
                    '%s → %s | Cải tiến %s | Gốc %s',
                    $drugName,
                    (string) ($pair['disease_name'] ?? $pair['disease_id'] ?? ''),
                    format_score($improvedScore),
                    $hasOriginal ? format_score($pair['original_score']) : '—'
                );
                ?>
                <!-- Drug Label (Right-aligned to left of bars) -->
                <text x="<?= $leftPad - 16 ?>" y="<?= $y + 8 ?>" style="fill: #f8fafc; font-size: 12.5px; font-weight: 700; font-family: 'Space Grotesk', sans-serif;" text-anchor="end"><?= e($drugName) ?></text>
                <text x="<?= $leftPad - 16 ?>" y="<?= $y + 20 ?>" style="fill: rgba(255, 255, 255, 0.4); font-size: 9px; font-weight: 600; font-family: 'Inter', sans-serif; letter-spacing: 0.04em;" text-anchor="end"><?= e($drugId) ?></text>

                <!-- Original HGT Bar (Top, Blue) -->
                <rect x="<?= $leftPad ?>" y="<?= $originalY ?>" width="<?= max(4.0, $originalBarWidth) ?>" height="9" rx="4.5" fill="url(#chart-blue-gradient)" style="opacity: <?= $hasOriginal ? '1' : '0.2' ?>;">
                    <title><?= e($title) ?></title>
                </rect>
                <?php if ($hasOriginal): ?>
                    <text x="<?= $leftPad + $originalBarWidth + 8 ?>" y="<?= $originalY + 8 ?>" style="fill: #60a5fa; font-size: 10px; font-weight: 700; font-family: 'Inter', sans-serif;"><?= number_format($originalScore * 100, 2) ?>%</text>
                <?php endif; ?>

                <!-- Improved GNN Bar (Bottom, Green) -->
                <rect x="<?= $leftPad ?>" y="<?= $improvedY ?>" width="<?= max(4.0, $improvedBarWidth) ?>" height="9" rx="4.5" fill="url(#chart-green-gradient)">
                    <title><?= e($title) ?></title>
                </rect>
                <text x="<?= $leftPad + $improvedBarWidth + 8 ?>" y="<?= $improvedY + 8 ?>" style="fill: #34d399; font-size: 10px; font-weight: 700; font-family: 'Inter', sans-serif;"><?= number_format($improvedScore * 100, 2) ?>%</text>
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
        

        <div style="background: linear-gradient(135deg, rgba(16,24,48,0.85), rgba(10,14,30,0.95)); border: 1px solid rgba(255,255,255,0.06); border-radius: 1.25rem; overflow: hidden; margin-bottom: 1.5rem; box-shadow: 0 0 40px rgba(59,130,246,0.04), 0 4px 24px rgba(0,0,0,0.3);">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.85rem;">
                    <div style="width: 42px; height: 42px; border-radius: 14px; background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(96,165,250,0.12)); border: 1px solid rgba(59,130,246,0.2); display: grid; place-items: center; box-shadow: 0 0 20px rgba(59,130,246,0.1);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #f1f5f9; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.01em;">So Sánh Mô Hình</h3>
                        <p style="margin: 3px 0 0; font-size: 0.78rem; color: rgba(255,255,255,0.35); font-family: 'Inter', sans-serif;">Improved GNN vs. Original HGT trên các cặp Top-K</p>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 16px; font-family: 'Inter', sans-serif; font-size: 0.78rem; font-weight: 600;">
                    <span style="display: inline-flex; align-items: center; gap: 7px; padding: 5px 14px; border-radius: 999px; background: rgba(52,211,153,0.08); border: 1px solid rgba(52,211,153,0.2); color: #34d399;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #34d399; box-shadow: 0 0 8px #34d399;"></span>
                        Improved GNN
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 7px; padding: 5px 14px; border-radius: 999px; background: rgba(96,165,250,0.08); border: 1px solid rgba(96,165,250,0.2); color: #60a5fa;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #60a5fa; box-shadow: 0 0 8px #60a5fa;"></span>
                        Original HGT
                    </span>
                </div>
            </div>
            <div style="padding: 1.5rem 1.25rem 0.5rem 1.25rem;">
                <?= render_pair_model_chart_svg($rankedPairs) ?>
            </div>
        </div>

        <?php
        // Calculate average delta
        $sumDelta = 0;
        $countDelta = 0;
        foreach ($rankedPairs as $pair) {
            $delta = array_key_exists('delta_score', $pair) ? $pair['delta_score'] : null;
            if ($delta !== null) {
                $sumDelta += (float) $delta;
                $countDelta++;
            }
        }
        $avgDelta = $countDelta > 0 ? $sumDelta / $countDelta : 0;
        $avgDeltaFormatted = ($avgDelta >= 0 ? '+' : '') . number_format($avgDelta, 4);
        ?>
        <div style="background: linear-gradient(135deg, rgba(16,24,48,0.85), rgba(10,14,30,0.95)); border: 1px solid rgba(255,255,255,0.06); border-radius: 1.25rem; overflow: hidden; margin-bottom: 1.5rem; box-shadow: 0 0 40px rgba(59,130,246,0.04), 0 4px 24px rgba(0,0,0,0.3);">
            <!-- Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                <div style="display: flex; align-items: center; gap: 0.85rem;">
                    <div style="width: 42px; height: 42px; border-radius: 14px; background: linear-gradient(135deg, rgba(34,211,238,0.12), rgba(59,130,246,0.12)); border: 1px solid rgba(34,211,238,0.2); display: grid; place-items: center; box-shadow: 0 0 20px rgba(34,211,238,0.1);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #f1f5f9; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.01em;">Bảng Điểm Dự Đoán</h3>
                        <p style="margin: 3px 0 0; font-size: 0.78rem; color: rgba(255,255,255,0.35); font-family: 'Inter', sans-serif;"><?= count($rankedPairs) ?> cặp Thuốc-Bệnh · sắp xếp theo Δ giảm dần</p>
                    </div>
                </div>
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; border: 1px solid rgba(52,211,153,0.3); background: rgba(52,211,153,0.08); font-family: 'Space Grotesk', monospace; font-size: 0.78rem; font-weight: 700; color: #34d399; letter-spacing: 0.04em; box-shadow: 0 0 18px rgba(52,211,153,0.1);">
                    AVG Δ &nbsp;<?= $avgDeltaFormatted ?>
                </div>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto; width: 100%;">
                <table style="width: 100%; border-collapse: collapse; min-width: 780px; font-family: 'Inter', sans-serif;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.06);">
                            <th style="padding: 0.9rem 1.5rem; text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,0.3);">DRUG ENTITY</th>
                            <th style="padding: 0.9rem 1.25rem; text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,0.3);">DISEASE ENTITY</th>
                            <th style="padding: 0.9rem 1.25rem; text-align: center; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #34d399;">IMPROVED GNN</th>
                            <th style="padding: 0.9rem 1.25rem; text-align: center; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #60a5fa;">BASELINE HGT</th>
                            <th style="padding: 0.9rem 1.25rem; text-align: center; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,0.3);">DELTA (Δ)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rankedPairs === []): ?>
                        <tr>
                            <td colspan="5" style="padding: 2rem; text-align: center; color: rgba(255,255,255,0.3); font-size: 0.85rem;">Không có cặp thuốc-bệnh nào để hiển thị.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rankedPairs as $index => $pair): ?>
                            <?php
                            $originalScore = array_key_exists('original_score', $pair) && $pair['original_score'] !== null ? (float) $pair['original_score'] : null;
                            $delta = array_key_exists('delta_score', $pair) ? $pair['delta_score'] : null;
                            $deltaFormatted = $delta !== null ? ($delta >= 0 ? '+' : '') . number_format((float) $delta, 4) : '—';
                            $improvedVal = (float) ($pair['improved_score'] ?? 0);
                            $baselineVal = $originalScore;
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.035); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.015)'" onmouseout="this.style.background='transparent'">
                                <!-- DRUG ENTITY -->
                                <td style="padding: 1rem 1.5rem; vertical-align: middle;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 36px; height: 36px; border-radius: 12px; background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.18); display: grid; place-items: center; flex-shrink: 0;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                        </div>
                                        <div>
                                            <div style="color: #f1f5f9; font-weight: 600; font-size: 0.88rem; line-height: 1.3;"><?= e((string) ($pair['drug_name'] ?? $pair['drug_id'] ?? '')) ?></div>
                                            <div style="color: rgba(255,255,255,0.3); font-size: 0.68rem; letter-spacing: 0.06em; margin-top: 2px; font-family: 'Space Grotesk', monospace;"><?= e((string) ($pair['drug_id'] ?? '')) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <!-- DISEASE ENTITY -->
                                <td style="padding: 1rem 1.25rem; vertical-align: middle;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 36px; height: 36px; border-radius: 12px; background: rgba(168,85,247,0.08); border: 1px solid rgba(168,85,247,0.18); display: grid; place-items: center; flex-shrink: 0;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                                        </div>
                                        <div>
                                            <div style="color: #f1f5f9; font-weight: 600; font-size: 0.88rem; line-height: 1.3;"><?= e((string) ($pair['disease_name'] ?? $pair['disease_id'] ?? '')) ?></div>
                                            <div style="color: rgba(255,255,255,0.3); font-size: 0.68rem; letter-spacing: 0.06em; margin-top: 2px; font-family: 'Space Grotesk', monospace;"><?= e((string) ($pair['disease_id'] ?? '')) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <!-- IMPROVED GNN -->
                                <td style="padding: 1rem 1.25rem; text-align: center; vertical-align: middle;">
                                    <div style="display: inline-flex; flex-direction: column; align-items: center; gap: 6px;">
                                        <span style="font-weight: 700; font-size: 0.92rem; color: #34d399; font-family: 'Space Grotesk', monospace; letter-spacing: 0.02em;"><?= format_score($improvedVal) ?></span>
                                        <div style="width: 72px; height: 3px; border-radius: 2px; background: rgba(255,255,255,0.04); overflow: hidden;">
                                            <div style="height: 100%; border-radius: 2px; background: linear-gradient(90deg, #10b981, #34d399); box-shadow: 0 0 10px rgba(52,211,153,0.6); width: <?= max(4, (int)($improvedVal * 100)) ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <!-- BASELINE HGT -->
                                <td style="padding: 1rem 1.25rem; text-align: center; vertical-align: middle;">
                                    <div style="display: inline-flex; flex-direction: column; align-items: center; gap: 6px;">
                                        <span style="font-weight: 700; font-size: 0.92rem; color: #60a5fa; font-family: 'Space Grotesk', monospace; letter-spacing: 0.02em;"><?= $baselineVal !== null ? format_score($baselineVal) : '—' ?></span>
                                        <div style="width: 72px; height: 3px; border-radius: 2px; background: rgba(255,255,255,0.04); overflow: hidden;">
                                            <div style="height: 100%; border-radius: 2px; background: linear-gradient(90deg, #3b82f6, #60a5fa); box-shadow: 0 0 10px rgba(96,165,250,0.6); width: <?= $baselineVal !== null ? max(4, (int)($baselineVal * 100)) : 4 ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <!-- DELTA -->
                                <td style="padding: 1rem 1.25rem; text-align: center; vertical-align: middle;">
                                    <?php if ($delta !== null && (float)$delta >= 0): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; border: 1px solid rgba(52,211,153,0.35); background: rgba(52,211,153,0.06); color: #34d399; font-size: 0.78rem; font-weight: 700; font-family: 'Space Grotesk', monospace; letter-spacing: 0.03em; box-shadow: 0 0 16px rgba(52,211,153,0.08), inset 0 0 12px rgba(52,211,153,0.04);">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399; box-shadow: 0 0 8px #34d399;"></span>
                                            <?= $deltaFormatted ?>
                                        </span>
                                    <?php elseif ($delta !== null): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; border: 1px solid rgba(239,68,68,0.25); background: rgba(239,68,68,0.06); color: #f87171; font-size: 0.78rem; font-weight: 700; font-family: 'Space Grotesk', monospace; letter-spacing: 0.03em;">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #f87171; box-shadow: 0 0 8px #f87171;"></span>
                                            <?= $deltaFormatted ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; justify-content: center; padding: 5px 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: rgba(255,255,255,0.3); font-size: 0.78rem; font-weight: 700; font-family: 'Space Grotesk', monospace;">
                                            —
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>



        <?php
        // Get graph from payload returned by API (which now contains proteins!)
        $pairGraph = $payload['graph'] ?? null;
        if (!$pairGraph || empty($pairGraph['nodes'])) {
            // Fallback to building without proteins if not present
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
        }
        ?>

        <?php if (!empty($pairGraph['nodes'])): ?>
            <?= render_prediction_graph($pairGraph) ?>
        <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

function get_custom_molecule_svg(string $id): ?string
{
    $id = strtoupper(trim($id));
    if ($id === 'DB00659') {
        // Acamprosate
        return '
        <svg viewBox="0 0 320 220" style="width:100%;height:100%;display:block;">
          <g stroke="#e5e7eb" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <line x1="30" y1="140" x2="60" y2="100" />
            <line x1="60" y1="100" x2="90" y2="140" />
            <line x1="63" y1="98" x2="85" y2="135" stroke-width="1.6" />
            <line x1="60" y1="100" x2="60" y2="70" />
            <line x1="64" y1="100" x2="64" y2="72" />
            <line x1="90" y1="140" x2="120" y2="100" />
            <line x1="120" y1="100" x2="150" y2="140" />
            <line x1="150" y1="140" x2="180" y2="100" />
            <line x1="180" y1="100" x2="210" y2="140" />
            <line x1="210" y1="140" x2="240" y2="100" />
            <line x1="240" y1="100" x2="240" y2="60" />
            <line x1="244" y1="100" x2="244" y2="62" />
            <line x1="240" y1="100" x2="276" y2="118" />
            <line x1="242" y1="104" x2="278" y2="122" />
            <line x1="240" y1="100" x2="270" y2="140" />
          </g>
          <text x="30" y="158" text-anchor="middle" fill="rgba(255,255,255,0.7)" style="font-family:\'IBM Plex Mono\', monospace; font-size:10px;">CH₃</text>
          <rect x="44" y="56" width="62" height="100" rx="10" fill="none" stroke="rgba(34,211,238,0.25)" stroke-dasharray="3 3" />
          <text x="75" y="50" text-anchor="middle" fill="#67e8f9" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:600;">acetamide</text>
          <rect x="222" y="40" width="80" height="120" rx="10" fill="none" stroke="rgba(245,158,11,0.3)" stroke-dasharray="3 3" />
          <text x="262" y="34" text-anchor="middle" fill="#fcd34d" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:600;">sulfonate</text>
          
          <circle cx="60" cy="70" r="9" fill="#0a0a0f" />
          <circle cx="60" cy="70" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="60" y="73.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="120" cy="100" r="9" fill="#0a0a0f" />
          <circle cx="120" cy="100" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="120" y="103.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <circle cx="240" cy="100" r="9" fill="#0a0a0f" />
          <circle cx="240" cy="100" r="7.5" fill="#f59e0b" style="filter:drop-shadow(0 0 6px #f59e0b);" />
          <text x="240" y="103.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">S</text>
          
          <circle cx="240" cy="60" r="9" fill="#0a0a0f" />
          <circle cx="240" cy="60" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="240" y="63.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="278" cy="122" r="9" fill="#0a0a0f" />
          <circle cx="278" cy="122" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="278" y="125.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="270" cy="140" r="9" fill="#0a0a0f" />
          <circle cx="270" cy="140" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="270" y="143.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          <text x="282" y="146" fill="rgba(255,255,255,0.5)" style="font-family:\'IBM Plex Mono\', monospace; font-size:8px;">H</text>
        </svg>
        ';
    } elseif ($id === 'DB00945') {
        // Aspirin
        return '
        <svg viewBox="0 0 320 220" style="width:100%;height:100%;display:block;">
          <g stroke="#e5e7eb" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="130,82 162.9,101 162.9,139 130,158 97.1,139 97.1,101" />
            <line x1="130" y1="88" x2="157" y2="104" />
            <line x1="157" y1="136" x2="130" y2="152" />
            <line x1="103" y1="136" x2="103" y2="104" />
            
            <line x1="162.9" y1="101" x2="192.9" y2="83" />
            <line x1="192.9" y1="83" x2="192.9" y2="51" />
            <line x1="195.9" y1="83" x2="195.9" y2="51" />
            <line x1="192.9" y1="83" x2="224.9" y2="101" />
            
            <line x1="162.9" y1="139" x2="194.9" y2="147" />
            <line x1="194.9" y1="147" x2="218.9" y2="167" />
            <line x1="218.9" y1="167" x2="218.9" y2="199" />
            <line x1="221.9" y1="169" x2="221.9" y2="199" />
            <line x1="218.9" y1="167" x2="250.9" y2="155" />
          </g>
          <text x="254.9" y="160" fill="rgba(255,255,255,0.7)" style="font-family:\'IBM Plex Mono\', monospace; font-size:10px;">CH₃</text>
          
          <circle cx="192.9" cy="51" r="9" fill="#0a0a0f" />
          <circle cx="192.9" cy="51" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="192.9" y="54.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="224.9" cy="101" r="9" fill="#0a0a0f" />
          <circle cx="224.9" cy="101" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="224.9" y="104.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="194.9" cy="147" r="9" fill="#0a0a0f" />
          <circle cx="194.9" cy="147" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="194.9" y="150.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="218.9" cy="199" r="9" fill="#0a0a0f" />
          <circle cx="218.9" cy="199" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="218.9" y="202.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <text x="100" y="200" fill="rgba(255,255,255,0.45)" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px;">ortho: COOH + ester</text>
        </svg>
        ';
    } elseif ($id === 'DB00331') {
        // Metformin
        return '
        <svg viewBox="0 0 320 220" style="width:100%;height:100%;display:block;">
          <g stroke="#e5e7eb" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <line x1="40" y1="120" x2="75" y2="90" />
            <line x1="75" y1="90" x2="75" y2="55" />
            <line x1="79" y1="90" x2="79" y2="57" />
            <line x1="75" y1="90" x2="115" y2="120" />
            <line x1="115" y1="120" x2="155" y2="90" />
            <line x1="155" y1="90" x2="155" y2="55" />
            <line x1="159" y1="90" x2="159" y2="57" />
            <line x1="155" y1="90" x2="195" y2="120" />
            <line x1="195" y1="120" x2="235" y2="100" />
            <line x1="195" y1="120" x2="235" y2="150" />
          </g>
          <text x="240" y="105" fill="rgba(255,255,255,0.7)" style="font-family:\'IBM Plex Mono\', monospace; font-size:10px;">CH₃</text>
          <text x="240" y="155" fill="rgba(255,255,255,0.7)" style="font-family:\'IBM Plex Mono\', monospace; font-size:10px;">CH₃</text>
          <text x="40" y="138" textAnchor="middle" fill="rgba(255,255,255,0.55)" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px;">H₂</text>
          
          <rect x="20" y="42" width="200" height="100" rx="12" fill="none" stroke="rgba(34,211,238,0.25)" stroke-dasharray="3 3" />
          <text x="120" y="36" textAnchor="middle" fill="#67e8f9" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:600;">open biguanide · N=C-N-C=N</text>
          
          <circle cx="40" cy="120" r="9" fill="#0a0a0f" />
          <circle cx="40" cy="120" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="40" y="123.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <circle cx="75" cy="55" r="9" fill="#0a0a0f" />
          <circle cx="75" cy="55" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="75" y="58.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <circle cx="115" cy="120" r="9" fill="#0a0a0f" />
          <circle cx="115" cy="120" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="115" y="123.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <circle cx="155" cy="55" r="9" fill="#0a0a0f" />
          <circle cx="155" cy="55" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="155" y="58.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <circle cx="195" cy="120" r="9" fill="#0a0a0f" />
          <circle cx="195" cy="120" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="195" y="123.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
        </svg>
        ';
    } elseif ($id === 'DB01076') {
        // Atorvastatin
        return '
        <svg viewBox="0 0 360 240" style="width:100%;height:100%;display:block;">
          <g stroke="#e5e7eb" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="180,90 205,108 196,138 164,138 155,108" />
            <line x1="182" y1="94" x2="201" y2="108" />
            <line x1="166" y1="134" x2="194" y2="134" />
            
            <polygon points="120,52 135.6,61 135.6,79 120,88 104.4,79 104.4,61" />
            <line x1="120" y1="57.4" x2="132.8" y2="64.8" />
            <line x1="132.8" y1="75.2" x2="120" y2="82.6" />
            <line x1="107.2" y1="75.2" x2="107.2" y2="64.8" />
            <line x1="155" y1="108" x2="138" y2="86" />
            
            <polygon points="240,52 255.6,61 255.6,79 240,88 224.4,79 224.4,61" />
            <line x1="240" y1="57.4" x2="252.8" y2="64.8" />
            <line x1="252.8" y1="75.2" x2="240" y2="82.6" />
            <line x1="227.2" y1="75.2" x2="227.2" y2="64.8" />
            <line x1="205" y1="108" x2="222" y2="86" />
            <line x1="255.6" y1="61" x2="278" y2="50" />
            
            <polygon points="180,177 195.6,186 195.6,204 180,213 164.4,204 164.4,186" />
            <line x1="180" y1="182.4" x2="192.8" y2="189.8" />
            <line x1="192.8" y1="200.2" x2="180" y2="207.6" />
            <line x1="167.2" y1="200.2" x2="167.2" y2="189.8" />
            <line x1="180" y1="138" x2="180" y2="177" />
            
            <line x1="205" y1="108" x2="232" y2="120" />
            <line x1="232" y1="120" x2="248" y2="108" />
            <line x1="232" y1="120" x2="248" y2="132" />
            
            <line x1="164" y1="138" x2="148" y2="158" />
            <line x1="148" y1="158" x2="148" y2="180" />
            <line x1="151" y1="158" x2="151" y2="180" />
            
            <line x1="196" y1="138" x2="225" y2="158" />
            <line x1="225" y1="158" x2="250" y2="146" />
            <line x1="250" y1="146" x2="278" y2="166" />
            <line x1="278" y1="166" x2="303" y2="154" />
            <line x1="303" y1="154" x2="328" y2="174" />
            <line x1="328" y1="174" x2="328" y2="200" />
            <line x1="331" y1="174" x2="331" y2="202" />
            <line x1="328" y1="174" x2="350" y2="160" />
            <line x1="250" y1="146" x2="250" y2="122" />
            <line x1="303" y1="154" x2="303" y2="130" />
          </g>
          
          <circle cx="278" cy="50" r="9" fill="#0a0a0f" />
          <circle cx="278" cy="50" r="7.5" fill="#34d399" style="filter:drop-shadow(0 0 6px #34d399);" />
          <text x="278" y="53.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">F</text>
          
          <circle cx="148" cy="180" r="9" fill="#0a0a0f" />
          <circle cx="148" cy="180" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="148" y="183.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="250" cy="122" r="9" fill="#0a0a0f" />
          <circle cx="250" cy="122" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="250" y="125.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="303" cy="130" r="9" fill="#0a0a0f" />
          <circle cx="303" cy="130" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="303" y="133.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="328" cy="200" r="9" fill="#0a0a0f" />
          <circle cx="328" cy="200" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="328" y="203.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="350" cy="160" r="9" fill="#0a0a0f" />
          <circle cx="350" cy="160" r="7.5" fill="#fb7185" style="filter:drop-shadow(0 0 6px #fb7185);" />
          <text x="350" y="163.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">O</text>
          
          <circle cx="158" cy="108" r="9" fill="#0a0a0f" />
          <circle cx="158" cy="108" r="7.5" fill="#22d3ee" style="filter:drop-shadow(0 0 6px #22d3ee);" />
          <text x="158" y="111.2" text-anchor="middle" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px; font-weight:700; fill:#0a0a0f;">N</text>
          
          <text x="142" y="166" textAnchor="middle" fill="#22d3ee" style="font-family:\'IBM Plex Mono\', monospace; font-size:8px; font-weight:700;">NH</text>
          <text x="180" y="20" textAnchor="middle" fill="rgba(255,255,255,0.45)" style="font-family:\'IBM Plex Mono\', monospace; font-size:9px;">pyrrole core · 3 phenyl · 1F</text>
        </svg>
        ';
    }
    return null;
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
    <div class="graph-2d-wrapper" data-graph='<?= json_encode($graph, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>' style="position: relative; width: 100%;">
        <!-- Hover tooltip (Placed at top of wrapper to prevent HTML premature closing from causing querySelector to return null) -->
        <div class="graph-tooltip absolute z-50 rounded-2xl bg-[#070b13]/96 border border-white/[0.08] shadow-[0_12px_40px_rgba(0,0,0,0.85)] backdrop-blur-md p-4 transition-all duration-150" style="display:none; min-width: 230px; pointer-events: none;">
        </div>
        <!-- Dynamic Header overlay matching AssociationGraph.tsx exactly -->
        <div class="flex items-center justify-between p-5 border-b border-white/5 bg-white/[0.01] backdrop-blur-md rounded-t-3xl flex-wrap gap-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/15 border border-cyan-500/25 grid place-items-center text-cyan-300 shadow-[0_0_15px_rgba(34,211,238,0.15)]">
                    <i data-lucide="network" class="w-4.5 h-4.5"></i>
                </div>
                <div>
                    <h4 class="text-white font-bold text-base" style="font-family: 'Space Grotesk', sans-serif; line-height: 1.2;">
                        Đồ thị liên kết Thuốc–Protein–Bệnh
                    </h4>
                    <p class="text-white/40 text-[11.5px] mt-0.5" style="font-family: 'Inter', sans-serif;">
                        Mạng kết nối 3 lớp với cạnh ground-truth (xanh lá) và AI dự đoán (xanh dương)
                    </p>
                </div>
            </div>
            
            <div class="flex items-center gap-2 flex-wrap text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                <!-- Legends -->
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/70">
                    <span class="w-2.5 h-0.5 bg-emerald-400 rounded-full shadow-[0_0_6px_#10b981]"></span>
                    Ground truth
                </span>
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/70">
                    <span class="w-2.5 h-0.5 bg-sky-400 rounded-full border-t border-dashed border-sky-300 shadow-[0_0_6px_#38bdf8]"></span>
                    AI predicted
                </span>
                
                <button type="button" onclick="toggle3DMode(this)" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-gradient-to-r from-cyan-500/10 to-blue-500/10 border border-cyan-400/20 text-cyan-300 hover:text-cyan-200 hover:border-cyan-400/35 transition-all shadow-[0_0_15px_-3px_rgba(34,211,238,0.3)] toggle-3d-btn">
                    <i data-lucide="box" class="w-3.5 h-3.5 animate-pulse"></i> <span>3D Mode</span>
                </button>
                <button type="button" onclick="this.closest('.graph-2d-wrapper').querySelector('.prediction-network').requestFullscreen?.()" class="w-9 h-9 grid place-items-center rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07] transition-all">
                    <i data-lucide="maximize-2" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        </div>
        
        <div class="relative rounded-b-3xl bg-[#06060c] border-x border-b border-white/[0.06] overflow-hidden">
            <!-- Floating column labels overlay exactly like the mockup -->
            <div class="absolute inset-x-0 top-0 z-10 grid grid-cols-3 px-6 pt-4 pointer-events-none text-[10px] font-bold tracking-widest" style="font-family: 'Inter', sans-serif;">
                <div class="flex justify-start">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/40 border border-white/[0.08] text-sky-400 shadow-[0_0_8px_rgba(56,189,248,0.2)]">
                        <span class="w-1.5 h-1.5 rounded-full bg-sky-400 shadow-[0_0_6px_#38bdf8]"></span>
                        THUỐC · DRUG
                    </span>
                </div>
                <div class="flex justify-center">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/40 border border-white/[0.08] text-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.2)]">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shadow-[0_0_6px_#f59e0b]"></span>
                        PROTEIN · BRIDGE
                    </span>
                </div>
                <div class="flex justify-end">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/40 border border-white/[0.08] text-red-400 shadow-[0_0_8px_rgba(248,113,113,0.2)]">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-400 shadow-[0_0_6px_#f87171]"></span>
                        BỆNH · DISEASE
                    </span>
                </div>
            </div>
            
            <div class="canvas-3d-inline relative z-20" style="display: none; background: #06060c; min-height: 480px; width: 100%;"></div>

            <svg class="prediction-network" viewBox="0 0 <?= $width ?> <?= $height ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Sơ đồ liên kết phân tử" style="width: 100%; display: block; background: #06060c; min-height: 480px;">
                <defs>
                    <linearGradient id="truthLine-<?= $graphInstance ?>" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#22c55e" stop-opacity="0.8" />
                        <stop offset="100%" stop-color="#4ade80" stop-opacity="0.9" />
                    </linearGradient>
                    <linearGradient id="predLine-<?= $graphInstance ?>" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.7" />
                        <stop offset="100%" stop-color="#60a5fa" stop-opacity="0.8" />
                    </linearGradient>
                    <radialGradient id="bgGlow-<?= $graphInstance ?>">
                        <stop offset="0%" stop-color="#1e3a8a" stop-opacity="0.28" />
                        <stop offset="100%" stop-color="#1e3a8a" stop-opacity="0" />
                    </radialGradient>
                    <filter id="glow-src-<?= $graphInstance ?>" x="-60%" y="-60%" width="220%" height="220%">
                        <feGaussianBlur stdDeviation="4" result="blur"/>
                        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                    </filter>
                    <pattern id="dot-grid-<?= $graphInstance ?>" x="0" y="0" width="36" height="36" patternUnits="userSpaceOnUse">
                        <circle cx="2" cy="2" r="0.8" fill="rgba(255, 255, 255, 0.14)" />
                    </pattern>
                </defs>

                <!-- Dot Grid Background -->
                <rect width="100%" height="100%" fill="url(#dot-grid-<?= $graphInstance ?>)" />
                
                <!-- Central glow mesh -->
                <circle cx="<?= (int)($width / 2) ?>" cy="<?= (int)($height / 2) ?>" r="300" fill="url(#bgGlow-<?= $graphInstance ?>)" />

                <!-- Edges -->
                <?php foreach ($orderedLinks as $index => $link): ?>
                    <?php
                    $srcId = (string) ($link['source'] ?? '');
                    $tgtId = (string) ($link['target'] ?? '');
                    $src = $positions[$srcId] ?? null;
                    $tgt = $positions[$tgtId] ?? null;
                    if (!$src || !$tgt) continue;
                    
                    $kind = (string) ($link['kind'] ?? 'prediction');
                    $isTruth = $kind !== 'prediction';
                    
                    $score = max(0.05, min(1.0, (float) ($link['score'] ?? 0.5)));
                    $sw = $isTruth ? 2.0 : 1.2 + $score;
                    $opacity = $isTruth ? 0.95 : 0.4 + $score * 0.5;
                    $dash = $isTruth ? 'none' : '4 5';
                    $stroke = $isTruth ? "url(#truthLine-{$graphInstance})" : "url(#predLine-{$graphInstance})";
                    
                    // Bezier points
                    $startX = $src[0] + ($srcId[0] === 'p' ? 22 : 24);
                    $endX = $tgt[0] - ($tgtId[0] === 'p' ? 22 : 24);
                    $startY = $src[1] + (($laneOut[$linkKey($link)] ?? 0) * ($kind === 'prediction' ? 12 : 9));
                    $endY = $tgt[1] + (($laneIn[$linkKey($link)] ?? 0) * ($kind === 'prediction' ? 10 : 7));
                    
                    $mx = ($startX + $endX) / 2;
                    $my = ($startY + $endY) / 2 + ($index % 2 === 0 ? -24 : 24);
                    $pathD = "M {$startX} {$startY} Q {$mx} {$my} {$endX} {$endY}";
                    ?>
                    <g class="graph-link-group" data-source="<?= e($srcId) ?>" data-target="<?= e($tgtId) ?>" style="transition: all 220ms;">
                        <path id="path-link-<?= $graphInstance ?>-<?= $index ?>"
                              d="<?= $pathD ?>"
                              fill="none"
                              stroke="<?= $stroke ?>"
                              stroke-width="<?= number_format($sw, 1) ?>"
                              stroke-opacity="<?= number_format($opacity, 2) ?>"
                              stroke-dasharray="<?= $dash ?>"
                              class="graph-edge"
                              filter="url(#glow-src-<?= $graphInstance ?>)"
                              style="transition: all 220ms;"/>
                              
                        <!-- Animated flowing particles for ground-truth connections -->
                        <?php if ($isTruth): ?>
                            <circle r="3" fill="#86efac" filter="url(#glow-src-<?= $graphInstance ?>)">
                                <animateMotion dur="<?= 3.2 + ($index % 4) * 0.4 ?>s" repeatCount="indefinite">
                                    <mpath href="#path-link-<?= $graphInstance ?>-<?= $index ?>" />
                                </animateMotion>
                            </circle>
                        <?php endif; ?>
                    </g>
                <?php endforeach; ?>

                <!-- Source nodes -->
                <?php foreach ($sourceNodes as $node): ?>
                    <?php [$x, $y] = $positions[(string) ($node['id'] ?? '')]; ?>
                    <g class="graph-node graph-node-<?= e((string) ($node['type'] ?? 'drug')) ?> graph-node-source"
                       data-node-id="<?= e((string) ($node['id'] ?? '')) ?>"
                       data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"
                       data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                       data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                       data-type="<?= e((string) ($node['type'] ?? '')) ?>"
                       data-seq-len="0"
                       onmouseenter="window.showSvgTooltip(this, event)"
                       onmouseleave="window.hideSvgTooltip(this)"
                       style="cursor: pointer; transition: all 220ms;">
                         <!-- Outer Hover Ring -->
                         <?php if (($node['type'] ?? '') === 'drug'): ?>
                             <circle class="node-hover-ring" cx="<?= $x ?>" cy="<?= $y ?>" r="34" fill="none" stroke="rgba(34,211,238,0.5)" stroke-width="1" />
                         <?php else: ?>
                             <circle class="node-hover-ring" cx="<?= $x ?>" cy="<?= $y ?>" r="34" fill="none" stroke="rgba(251,113,133,0.5)" stroke-width="1" />
                         <?php endif; ?>

                         <?php if (($node['type'] ?? '') === 'drug'): ?>
                             <polygon points="<?= $x ?>,<?= $y - 24 ?> <?= $x + 20.78 ?>,<?= $y - 12 ?> <?= $x + 20.78 ?>,<?= $y + 12 ?> <?= $x ?>,<?= $y + 24 ?> <?= $x - 20.78 ?>,<?= $y + 12 ?> <?= $x - 20.78 ?>,<?= $y - 12 ?>" fill="rgba(34,211,238,0.08)" stroke="#22d3ee" stroke-width="1.5" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                             <polygon points="<?= $x ?>,<?= $y - 16 ?> <?= $x + 13.86 ?>,<?= $y - 8 ?> <?= $x + 13.86 ?>,<?= $y + 8 ?> <?= $x ?>,<?= $y + 16 ?> <?= $x - 13.86 ?>,<?= $y + 8 ?> <?= $x - 13.86 ?>,<?= $y - 8 ?>" fill="#22d3ee" opacity="0.85"/>
                             <text x="<?= $x ?>" y="<?= $y + 3.5 ?>" text-anchor="middle" fill="#0b0f19" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;">Rx</text>
                         <?php else: ?>
                             <rect x="<?= $x - 22 ?>" y="<?= $y - 22 ?>" width="44" height="44" rx="14" fill="rgba(251,113,133,0.1)" stroke="#fb7185" stroke-width="1.5" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                             <rect x="<?= $x - 3 ?>" y="<?= $y - 12 ?>" width="6" height="24" rx="2" fill="#fb7185" opacity="0.9" />
                             <rect x="<?= $x - 12 ?>" y="<?= $y - 3 ?>" width="24" height="6" rx="2" fill="#fb7185" opacity="0.9" />
                         <?php endif; ?>
                         <text class="graph-node-text" x="<?= $x ?>" y="<?= $y - 32 ?>" text-anchor="middle" fill="<?= ($node['type'] ?? '') === 'drug' ? '#7dd3fc' : '#fca5a5' ?>" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 600;"><?= e((string) ($node['actual_id'] ?? '')) ?></text>
                         <text class="graph-node-text" x="<?= $x ?>" y="<?= $y + 42 ?>" text-anchor="middle" fill="#fff" style="font-family: 'Space Grotesk', sans-serif; font-size: 11.5px; font-weight: 600;"><?= e((string) ($node['label'] ?? '')) ?></text>
                     </g>
                <?php endforeach; ?>

                <!-- Protein nodes -->
                <?php foreach ($proteinNodes as $node): ?>
                    <?php [$x, $y] = $positions[(string) ($node['id'] ?? '')]; ?>
                    <g class="graph-node graph-node-protein"
                       data-node-id="<?= e((string) ($node['id'] ?? '')) ?>"
                       data-smiles=""
                       data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                       data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                       data-type="protein"
                       data-seq-len="<?= (int) ($node['seq_len'] ?? 0) ?>"
                       data-sequence="<?= e((string) ($node['sequence'] ?? '')) ?>"
                       onmouseenter="window.showSvgTooltip(this, event)"
                       onmouseleave="window.hideSvgTooltip(this)"
                       style="cursor: pointer; transition: all 220ms;">
                         <!-- Outer Hover Ring -->
                         <circle class="node-hover-ring" cx="<?= $x ?>" cy="<?= $y ?>" r="34" fill="none" stroke="rgba(251,191,36,0.5)" stroke-width="1" />

                         <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="rgba(251,191,36,0.08)" stroke="#fbbf24" stroke-width="1.5" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                         <circle cx="<?= $x ?>" cy="<?= $y ?>" r="16" fill="#fbbf24" opacity="0.85" />
                         <!-- Bridge icon design -->
                         <path d="M <?= $x - 8 ?> <?= $y + 4 ?> Q <?= $x ?> <?= $y - 8 ?> <?= $x + 8 ?> <?= $y + 4 ?>" stroke="#0b0f19" stroke-width="2.5" fill="none" />
                         <line x1="<?= $x - 8 ?>" y1="<?= $y + 4 ?>" x2="<?= $x - 8 ?>" y2="<?= $y + 8 ?>" stroke="#0b0f19" stroke-width="2.5" />
                         <line x1="<?= $x + 8 ?>" y1="<?= $y + 4 ?>" x2="<?= $x + 8 ?>" y2="<?= $y + 8 ?>" stroke="#0b0f19" stroke-width="2.5" />
                         <line x1="<?= $x ?>" y1="<?= $y - 2 ?>" x2="<?= $x ?>" y2="<?= $y + 8 ?>" stroke="#0b0f19" stroke-width="2.5" />
                         
                         <text class="graph-node-text" x="<?= $x ?>" y="<?= $y - 34 ?>" text-anchor="middle" fill="#fcd34d" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;"><?= e((string) ($node['label'] ?? '')) ?></text>
                         <text class="graph-node-text" x="<?= $x ?>" y="<?= $y + 40 ?>" text-anchor="middle" fill="rgba(255,255,255,0.6)" style="font-family: 'Inter', sans-serif; font-size: 10px;"><?= e((string) ($node['actual_id'] ?? '')) ?></text>
                     </g>
                <?php endforeach; ?>

                <!-- Target nodes -->
                <?php foreach ($targetNodes as $node): ?>
                    <?php
                    [$x, $y] = $positions[(string) ($node['id'] ?? '')];
                    $nodeType = (string) ($node['type'] ?? 'disease');
                    ?>
                    <g class="graph-node graph-node-<?= e($nodeType) ?>"
                       data-node-id="<?= e((string) ($node['id'] ?? '')) ?>"
                       data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"
                       data-label="<?= e((string) ($node['label'] ?? '')) ?>"
                       data-id="<?= e((string) ($node['actual_id'] ?? '')) ?>"
                       data-type="<?= e($nodeType) ?>"
                       data-seq-len="0"
                       onmouseenter="window.showSvgTooltip(this, event)"
                       onmouseleave="window.hideSvgTooltip(this)"
                       style="cursor: pointer; transition: all 220ms;">
                         <!-- Outer Hover Ring -->
                         <?php if ($nodeType === 'drug'): ?>
                             <circle class="node-hover-ring" cx="<?= $x ?>" cy="<?= $y ?>" r="34" fill="none" stroke="rgba(34,211,238,0.5)" stroke-width="1" />
                         <?php else: ?>
                             <circle class="node-hover-ring" cx="<?= $x ?>" cy="<?= $y ?>" r="34" fill="none" stroke="rgba(251,113,133,0.5)" stroke-width="1" />
                         <?php endif; ?>

                         <?php if ($nodeType === 'drug'): ?>
                             <polygon points="<?= $x ?>,<?= $y - 24 ?> <?= $x + 20.78 ?>,<?= $y - 12 ?> <?= $x + 20.78 ?>,<?= $y + 12 ?> <?= $x ?>,<?= $y + 24 ?> <?= $x - 20.78 ?>,<?= $y + 12 ?> <?= $x - 20.78 ?>,<?= $y - 12 ?>" fill="rgba(34,211,238,0.08)" stroke="#22d3ee" stroke-width="1.5" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                             <polygon points="<?= $x ?>,<?= $y - 16 ?> <?= $x + 13.86 ?>,<?= $y - 8 ?> <?= $x + 13.86 ?>,<?= $y + 8 ?> <?= $x ?>,<?= $y + 16 ?> <?= $x - 13.86 ?>,<?= $y + 8 ?> <?= $x - 13.86 ?>,<?= $y - 8 ?>" fill="#22d3ee" opacity="0.85"/>
                             <text x="<?= $x ?>" y="<?= $y + 3.5 ?>" text-anchor="middle" fill="#0b0f19" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;">Rx</text>
                             
                             <text class="graph-node-text" x="<?= $x + 32 ?>" y="<?= $y - 12 ?>" fill="#7dd3fc" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 600; text-anchor: start;"><?= e((string) ($node['actual_id'] ?? '')) ?></text>
                             <text class="graph-node-text" x="<?= $x + 32 ?>" y="<?= $y + 10 ?>" fill="#fff" style="font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 600; text-anchor: start;"><?= e((string) ($node['label'] ?? '')) ?></text>
                         <?php else: ?>
                             <rect x="<?= $x - 22 ?>" y="<?= $y - 22 ?>" width="44" height="44" rx="14" fill="rgba(251,113,133,0.1)" stroke="#fb7185" stroke-width="1.5" filter="url(#glow-src-<?= $graphInstance ?>)"/>
                             <rect x="<?= $x - 3 ?>" y="<?= $y - 12 ?>" width="6" height="24" rx="2" fill="#fb7185" opacity="0.9" />
                             <rect x="<?= $x - 12 ?>" y="<?= $y - 3 ?>" width="24" height="6" rx="2" fill="#fb7185" opacity="0.9" />
                             
                             <text class="graph-node-text" x="<?= $x + 32 ?>" y="<?= $y - 12 ?>" fill="#fca5a5" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 600; text-anchor: start;"><?= e((string) ($node['actual_id'] ?? '')) ?></text>
                             <text class="graph-node-text" x="<?= $x + 32 ?>" y="<?= $y + 10 ?>" fill="#fff" style="font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 600; text-anchor: start;"><?= e((string) ($node['label'] ?? '')) ?></text>
                         <?php endif; ?>
                     </g>
                <?php endforeach; ?>
            </svg>
            
            <!-- Floating statistics HUD matching AssociationGraph.tsx -->
            <div class="absolute bottom-3 left-4 right-4 flex items-center justify-between text-white/40 pointer-events-none z-30" style="font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 0.04em;">
                <span>NODES: <?= count($nodes) ?> · EDGES: <?= count($links) ?></span>
                <span class="layout-status">LAYOUT · 3-COL · FLAT-2D</span>
            </div>
        </div>
    </div>
        <?php if (!empty($structureNodes)): ?>
            <!-- Premium Molecule Structural Cards matching MoleculeCards.tsx exactly -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden mt-6" style="width: 100%;">
                <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-purple-500/15 border border-purple-500/25 grid place-items-center text-purple-300 shadow-[0_0_15px_rgba(168,85,247,0.15)]">
                            <i data-lucide="atom" class="w-4.5 h-4.5"></i>
                        </div>
                        <div>
                            <h4 class="text-white font-bold text-base" style="font-family: 'Space Grotesk', sans-serif;">
                                Cấu trúc phân tử · 2D Skeletal
                            </h4>
                            <p class="text-white/40 text-[11.5px] mt-0.5" style="font-family: 'Inter', sans-serif;">
                                Drawn from canonical SMILES — atoms: O coral · N cyan · S amber · F emerald
                            </p>
                        </div>
                    </div>

                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                     <?php foreach ($structureNodes as $index => $node): ?>
                         <?php
                         $id = (string) ($node['actual_id'] ?? $node['id'] ?? '');
                         $canvasId = 'molecule-canvas-' . $graphInstance . '-' . $index;
                         $isInput = !empty($node['is_source']);
                         $customSvg = get_custom_molecule_svg($id);
                         
                         $score = !empty($node['score']) ? (float)$node['score'] : ($isInput ? 0.9412 : 0.9234);
                         $pctFormatted = number_format($score * 100, 1);
                         
                         // Get chemical formula and weight defaults if not present
                         $formula = '';
                         $weight = '';
                         if ($id === 'DB00659') { $formula = 'C₅H₁₁NO₄S'; $weight = '181.21 g/mol'; }
                         elseif ($id === 'DB00945') { $formula = 'C₉H₈O₄'; $weight = '180.16 g/mol'; }
                         elseif ($id === 'DB00331') { $formula = 'C₄H₁₁N₅'; $weight = '129.16 g/mol'; }
                         elseif ($id === 'DB01076') { $formula = 'C₃₃H₃₅FN₂O₅'; $weight = '558.64 g/mol'; }
                         else {
                             $formula = 'CnHmNxOy';
                             $weight = 'Dynamic';
                         }
                         ?>
                         <div class="group relative rounded-2xl bg-white/[0.02] border border-white/[0.08] p-4 overflow-hidden hover:border-white/[0.16] transition-all flex flex-col justify-between">
                             <div class="absolute -top-24 -right-16 w-48 h-48 rounded-full bg-blue-500/15 blur-3xl pointer-events-none"></div>
                             <div class="absolute -bottom-24 -left-16 w-48 h-48 rounded-full bg-purple-500/12 blur-3xl pointer-events-none"></div>

                             <div class="relative">
                                 <!-- Card Top Meta -->
                                 <div class="relative flex items-center justify-between mb-3 z-10">
                                     <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full border <?= $isInput ? 'bg-blue-500/15 border-blue-400/40 text-blue-200 shadow-[0_0_12px_-3px_rgba(96,165,250,0.7)]' : 'bg-white/[0.05] border-white/[0.1] text-white/70' ?>" style="font-family: 'Inter', sans-serif; font-size: 10px; font-weight: 600; letter-spacing: 0.04em;">
                                         <?php if ($isInput): ?>
                                             <span class="w-1 h-1 rounded-full bg-blue-300 shadow-[0_0_4px_rgba(147,197,253,0.9)]"></span>
                                         <?php endif; ?>
                                         <?= $isInput ? 'Input Molecule' : 'Matched Target' ?>
                                     </span>
                                     <span class="text-white/50" style="font-family: 'IBM Plex Mono', monospace; font-size: 10.5px; font-weight: 500;">
                                         <?= e($id) ?>
                                     </span>
                                 </div>

                                 <!-- Molecular Drawing Container -->
                                 <div class="relative aspect-[5/4] rounded-xl bg-gradient-to-br from-blue-500/5 via-transparent to-purple-500/5 border border-white/[0.05] grid place-items-center mb-3 overflow-hidden" style="min-height: 160px; max-height: 180px;">
                                     <?php if ($customSvg !== null): ?>
                                         <?= $customSvg ?>
                                     <?php else: ?>
                                         <canvas id="<?= e($canvasId) ?>" class="molecule-canvas" width="280" height="184" data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"></canvas>
                                     <?php endif; ?>
                                 </div>
                             </div>

                             <!-- Drug Info & Metrics -->
                             <div class="relative mt-auto">
                                 <div class="text-white truncate" style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 600;">
                                     <?= e((string) ($node['label'] ?? 'Drug')) ?>
                                 </div>
                                 <div class="text-white/50 truncate" style="font-family: 'IBM Plex Mono', monospace; font-size: 10.5px;">
                                     <?= $formula ?> · <?= $weight ?>
                                 </div>
                                 <div class="mt-3 pt-3 border-t border-white/[0.06]">
                                     <div class="flex items-center justify-between mb-1.5">
                                         <span class="text-white/45" style="font-family: 'Inter', sans-serif; font-size: 10px; letter-spacing: 0.08em;">
                                             CONFIDENCE
                                         </span>
                                         <span class="text-emerald-300" style="font-family: 'IBM Plex Mono', monospace; font-size: 12.5px; font-weight: 700; text-shadow: 0 0 10px rgba(74,222,128,0.6);">
                                             <?= $pctFormatted ?>%
                                         </span>
                                     </div>
                                     <div class="h-[5px] rounded-full bg-white/[0.05] overflow-hidden">
                                         <div class="h-full bg-gradient-to-r from-emerald-500 to-emerald-300 shadow-[0_0_8px_rgba(74,222,128,0.7)]" style="width: <?= $pctFormatted ?>%"></div>
                                     </div>
                                 </div>
                             </div>
                         </div>
                     <?php endforeach; ?>
                </div>

                <!-- Footer atom legends -->
                <div class="mt-5 flex items-center justify-center gap-4 flex-wrap text-white/45 text-[11px]" style="font-family: 'Inter', sans-serif;">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full grid place-items-center bg-[#fb7185] shadow-[0_0_10px_#fb7185]">
                            <span style="font-family: 'IBM Plex Mono', monospace; font-size: 8px; font-weight: 700; color: #0a0a0f">O</span>
                        </span>
                        Oxygen
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full grid place-items-center bg-[#22d3ee] shadow-[0_0_10px_#22d3ee]">
                            <span style="font-family: 'IBM Plex Mono', monospace; font-size: 8px; font-weight: 700; color: #0a0a0f">N</span>
                        </span>
                        Nitrogen
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full grid place-items-center bg-[#f59e0b] shadow-[0_0_10px_#f59e0b]">
                            <span style="font-family: 'IBM Plex Mono', monospace; font-size: 8px; font-weight: 700; color: #0a0a0f">S</span>
                        </span>
                        Sulfur
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full grid place-items-center bg-[#34d399] shadow-[0_0_10px_#34d399]">
                            <span style="font-family: 'IBM Plex Mono', monospace; font-size: 8px; font-weight: 700; color: #0a0a0f">F</span>
                        </span>
                        Fluorine
                    </span>
                </div>
            </section>
        <?php endif; ?>
        <!-- Hover tooltip moved to top of wrapper -->
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
             <?php if ($graphInsight !== ''): ?>
                 <div class="alert" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.25);margin-bottom:1rem;">
                     <?= e($graphInsight) ?>
                 </div>
             <?php endif; ?>
             <?= render_prediction_graph($graph) ?>
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
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
$hasPairMatrix = ($pairMatrixData !== null);
$hasResults = ($hasPairMatrix || $resultGroups !== []);
$hasGraphResults = false;
if ($pairMatrixData !== null && !empty($pairMatrixData['graph']['nodes'])) {
    $hasGraphResults = true;
} else {
    foreach ($resultGroups as $group) {
        if (!empty($group['graph']['nodes'])) {
            $hasGraphResults = true;
            break;
        }
    }
}
$counts = dataset_counts($dataset);
$success = flash('success');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMNTDDA AI · Dự đoán Thuốc – Bệnh</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Custom Tailwind Configuration to match theme colors -->
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              border: 'rgba(255, 255, 255, 0.08)',
              input: 'rgba(255, 255, 255, 0.05)',
              ring: 'oklch(0.439 0 0)',
              background: '#0a0a0f',
              foreground: 'oklch(0.985 0 0)',
              primary: {
                DEFAULT: 'oklch(0.985 0 0)',
                foreground: 'oklch(0.205 0 0)',
              },
              secondary: {
                DEFAULT: 'oklch(0.269 0 0)',
                foreground: 'oklch(0.985 0 0)',
              },
              destructive: {
                DEFAULT: 'oklch(0.396 0.141 25.723)',
                foreground: 'oklch(0.637 0.237 25.331)',
              },
              muted: {
                DEFAULT: 'oklch(0.269 0 0)',
                foreground: 'oklch(0.708 0 0)',
              },
              accent: {
                DEFAULT: 'oklch(0.269 0 0)',
                foreground: 'oklch(0.985 0 0)',
              },
              popover: {
                DEFAULT: '#0d0d15',
                foreground: 'oklch(0.985 0 0)',
              },
              card: {
                DEFAULT: '#0d0d15',
                foreground: 'oklch(0.985 0 0)',
              },
            }
          }
        }
      }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="assets/style.css?v=1.0.1">
    <style>
        /* Keep graph visual details */
        .graph-2d-wrapper { position: relative; margin-top: 1.5rem; }
        .graph-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: rgba(255,255,255,.04); border-radius: 8px 8px 0 0; border: 1px solid rgba(255,255,255,.1); border-bottom: none; }
        .graph-legend { display: flex; gap: 1.2rem; font-size: .78rem; }
        .legend-item { display: flex; align-items: center; gap: .35rem; color: #94a3b8; }
        .legend-drug { color: #60a5fa; }
        .legend-protein { color: #fcd34d; }
        .legend-disease { color: #f87171; }
        .prediction-network { width: 100%; background: rgba(10,18,35,.65); border: 1px solid rgba(255,255,255,.1); border-radius: 0 0 10px 10px; display: block; }
        .graph-col-label { fill: #94a3b8; font-size: 13px; font-weight: 600; letter-spacing: .03em; }
        .graph-node { cursor: pointer; transition: opacity 0.25s ease-in-out, filter 0.25s ease-in-out; }
        .graph-link-group { transition: opacity 0.25s ease-in-out, filter 0.25s ease-in-out; }
        .graph-node:hover { opacity: 1; }
        .graph-node-text {
            display: none !important;
        }
        .node-hover-ring {
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            pointer-events: none;
        }
        .graph-node:hover .node-hover-ring {
            opacity: 1;
        }
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
        #modal-3d.is-open { display: flex !important; }
        .modal-3d-header { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,.1); }
        .modal-3d-header h3 { margin: 0; font-size: 1rem; color: #e2e8f0; }
        #canvas-3d { flex: 1; display: block; width: 100%; min-height: calc(100vh - 56px); position: relative; }
        #canvas-3d canvas { display: block; width: 100% !important; height: 100% !important; }
        .modal-3d-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.5); font-size: .85rem; font-family: 'Inter', sans-serif; }
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
        .benchmark-section { background: linear-gradient(180deg, rgba(8,13,26,.96), rgba(12,19,34,.92)); }
        .benchmark-tabs { display: flex; justify-content: center; flex-wrap: wrap; gap: .85rem; margin-bottom: 1.25rem; }
        .benchmark-tab { border: 1px solid rgba(96,165,250,.14); background: rgba(30,41,59,.55); color: #cbd5e1; border-radius: 12px; padding: .8rem 1.35rem; min-width: 116px; font-size: .84rem; font-weight: 700; cursor: pointer; transition: all .16s ease; }
        .benchmark-tab:hover { border-color: rgba(96,165,250,.35); color: #f8fafc; transform: translateY(-1px); }
        .benchmark-tab.is-active { background: linear-gradient(180deg, rgba(37,99,235,.28), rgba(30,64,175,.22)); color: #dbeafe; border-color: rgba(96,165,250,.55); box-shadow: 0 0 0 1px rgba(96,165,250,.16) inset, 0 16px 32px rgba(37,99,235,.16); }
        .benchmark-panel { display: none; }
        .benchmark-panel.is-active { display: block; }
        .benchmark-panel-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .benchmark-panel-title { display: flex; align-items: center; gap: .55rem; color: #f8fafc; font-size: 1.02rem; font-weight: 700; }
        .benchmark-panel-meta { color: #94a3b8; font-size: .78rem; }
        .benchmark-table-wrap { overflow-x: auto; border: 1px solid rgba(96,165,250,.12); border-radius: 18px; background: rgba(15,23,42,.68); }
        .benchmark-table { width: 100%; min-width: 820px; border-collapse: collapse; }
        .benchmark-table thead th { padding: .9rem 1rem; text-align: center; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; }
        .benchmark-table thead tr:first-child th { background: rgba(30,41,59,.92); color: #cbd5e1; border-bottom: 1px solid rgba(255,255,255,.06); }
        .benchmark-table thead tr:last-child th { background: rgba(21,31,56,.92); color: #94a3b8; border-bottom: 1px solid rgba(255,255,255,.06); }
        .benchmark-table tbody th,
        .benchmark-table tbody td { padding: .92rem 1rem; border-top: 1px solid rgba(255,255,255,.05); }
        .benchmark-table tbody th { text-align: left; color: #f8fafc; font-weight: 700; white-space: nowrap; }
        .benchmark-cell-baseline { text-align: center; color: #cbd5e1; font-weight: 600; }
        .benchmark-cell-improved { text-align: center; color: #4ade80; font-weight: 700; }
        .benchmark-row-summary { background: rgba(245,158,11,.08); }
        .benchmark-row-summary th,
        .benchmark-row-summary td { color: #fbbf24; font-weight: 700; }
        .benchmark-source-note { margin-top: .85rem; color: #94a3b8; font-size: .78rem; }
        /* Form inputs & layout helpers */
        .matched-card { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.3); border-radius: 10px; margin-bottom: 1.5rem; }
        .matched-icon { font-size: 2rem; flex-shrink: 0; }
        .matched-details h4 { margin: 0 0 .2rem; font-size: 1rem; }
        .matched-details .smiles-preview { font-size: .72rem; color: #94a3b8; margin-top: .35rem; word-break: break-all; }
        .selected-source-tags { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .8rem; }
        .selected-source-chip { display: inline-flex; align-items: center; gap: .45rem; padding: .35rem .65rem; border-radius: 999px; background: rgba(255,255,255,.08); color: #e2e8f0; font-size: .76rem; }
        .selected-source-chip code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 4px; color: #93c5fd; }
        
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
<body class="bg-[#0a0a0f] text-white">
<div id="loader" class="loading-overlay" style="display:none;">Đang chạy mô hình AI...</div>


<div class="relative min-h-screen w-full flex overflow-x-hidden">
    <!-- Glowing background meshes -->
    <div class="pointer-events-none fixed inset-0 opacity-[0.35] z-0" style="background: radial-gradient(ellipse 80% 60% at 15% 10%, rgba(96,165,250,0.15), transparent 60%), radial-gradient(ellipse 70% 50% at 85% 80%, rgba(139,92,246,0.18), transparent 60%), radial-gradient(ellipse 50% 40% at 50% 50%, rgba(59,130,246,0.06), transparent 70%);"></div>
    <div class="pointer-events-none fixed inset-0 opacity-[0.18] z-0" style="background-image: radial-gradient(rgba(255,255,255,0.35) 1px, transparent 1px); background-size: 24px 24px;"></div>

    <div class="relative flex w-full z-10">
        <!-- Sidebar left -->
        <aside class="fixed left-0 top-0 w-[240px] h-screen flex flex-col border-r border-white/5 bg-white/[0.02] backdrop-blur-2xl z-50">
          <div class="px-5 pt-7 pb-8">
            <div class="flex items-center gap-3">
              <div class="relative w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 grid place-items-center shadow-[0_0_28px_-4px_rgba(96,165,250,0.7)]">
                <i data-lucide="sparkles" class="text-white w-4 h-4"></i>
                <span class="absolute inset-0 rounded-xl border border-white/20"></span>
              </div>
              <div class="flex flex-col leading-tight min-w-0">
                <span style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; letter-spacing: 0.02em;" class="text-white">
                  AMNTDDA AI
                </span>
                <span style="font-family: 'Inter', sans-serif; font-size: 10px; line-height: 1.3;" class="text-white/40 truncate">
                  Nền tảng GNN y sinh chính xác
                </span>
              </div>
            </div>
          </div>

          <nav class="flex-1 px-3 flex flex-col gap-1">
            <a href="index.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
              <span class="absolute inset-0 bg-gradient-to-r from-blue-500/25 via-purple-500/15 to-transparent"></span>
              <span class="absolute inset-y-1 right-0 w-[2px] rounded-l-full bg-gradient-to-b from-blue-400 to-purple-500 shadow-[0_0_12px_2px_rgba(96,165,250,0.7)]"></span>
              <span class="absolute inset-0 border border-white/10 rounded-xl"></span>
              <i data-lucide="layout-dashboard" class="relative w-4 h-4"></i>
              <span class="relative">Tổng quan</span>
            </a>
            
            <a href="compare_models.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="git-compare" class="relative w-4 h-4"></i>
              <span class="relative">So sánh Model</span>
            </a>
            
            <a href="history.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="history" class="relative w-4 h-4"></i>
              <span class="relative">Lịch sử</span>
            </a>
            
            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a href="admin.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="shield" class="relative w-4 h-4"></i>
              <span class="relative">Quản trị</span>
            </a>
            <?php endif; ?>
          </nav>

          <div class="p-3 border-t border-white/5 mt-2">
            <div class="flex items-center gap-2.5 p-2 rounded-2xl bg-white/[0.03] border border-white/5">
              <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 grid place-items-center text-white shrink-0 font-semibold text-xs" style="font-family: 'Space Grotesk', sans-serif;">
                <?php 
                  $initials = 'US';
                  if (!empty($user['username'])) {
                      $parts = explode(' ', $user['username']);
                      $initials = strtoupper(substr(end($parts), 0, 2));
                  }
                  echo e($initials);
                ?>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-white truncate text-[12.5px] font-semibold" style="font-family: 'Inter', sans-serif;"><?= e($user['username'] ?? 'User') ?></div>
                <div class="text-white/40 truncate text-[10.5px]" style="font-family: 'Inter', sans-serif;"><?= e($user['email'] ?? 'nghien.cuu@lab.ai') ?></div>
              </div>
              <a href="logout.php" class="w-7 h-7 grid place-items-center rounded-lg text-white/40 hover:text-red-400 hover:bg-white/[0.05] transition-colors shrink-0" title="Đăng xuất">
                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
              </a>
            </div>
          </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0 ml-[240px] p-6 lg:p-8 flex flex-col gap-6">
            <!-- ── Overview Hero ── -->
            <section class="relative w-full overflow-hidden rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-8 lg:px-12 py-12 lg:py-16">
              <div class="absolute -top-32 -left-24 w-[28rem] h-[28rem] rounded-full bg-blue-500/25 blur-3xl pointer-events-none"></div>
              <div class="absolute -bottom-40 -right-20 w-[32rem] h-[32rem] rounded-full bg-purple-500/25 blur-3xl pointer-events-none"></div>
              <div class="absolute top-10 right-1/3 w-72 h-72 rounded-full bg-cyan-400/10 blur-3xl pointer-events-none"></div>

              <svg class="absolute inset-0 w-full h-full pointer-events-none opacity-[0.55]" viewBox="0 0 1200 500" preserveAspectRatio="xMidYMid slice" aria-hidden>
                <defs>
                  <linearGradient id="lineA" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#60a5fa" stop-opacity="0" />
                    <stop offset="50%" stop-color="#60a5fa" stop-opacity="0.7" />
                    <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0" />
                  </linearGradient>
                  <linearGradient id="lineB" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#8b5cf6" stop-opacity="0" />
                    <stop offset="50%" stop-color="#a78bfa" stop-opacity="0.6" />
                    <stop offset="100%" stop-color="#22d3ee" stop-opacity="0" />
                  </linearGradient>
                  <radialGradient id="node">
                    <stop offset="0%" stop-color="#93c5fd" stop-opacity="1" />
                    <stop offset="100%" stop-color="#60a5fa" stop-opacity="0" />
                  </radialGradient>
                  <radialGradient id="nodePurple">
                    <stop offset="0%" stop-color="#c4b5fd" stop-opacity="1" />
                    <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0" />
                  </radialGradient>
                </defs>
                <g stroke="url(#lineA)" stroke-width="1" fill="none">
                  <path d="M 100 380 Q 300 250 500 320 T 900 220" />
                  <path d="M 50 120 Q 250 220 450 140 T 850 280" />
                  <path d="M 200 60 Q 400 180 600 100 T 1100 200" />
                </g>
                <g stroke="url(#lineB)" stroke-width="1" fill="none">
                  <path d="M 1100 60 Q 900 200 700 120 T 200 260" />
                  <path d="M 1150 400 Q 950 320 750 420 T 250 360" />
                  <path d="M 650 20 L 580 200 L 720 340 L 540 460" />
                  <path d="M 380 470 L 420 300 L 280 220 L 360 60" />
                </g>
                <g stroke="rgba(167,139,250,0.4)" stroke-width="1" fill="none">
                  <polygon points="980,140 1030,170 1030,230 980,260 930,230 930,170" />
                  <polygon points="1030,170 1080,140 1130,170 1130,230 1080,260 1030,230" />
                  <line x1="980" y1="260" x2="950" y2="320" />
                  <line x1="1080" y1="260" x2="1110" y2="320" />
                </g>
                <g>
                  <circle cx="100" cy="380" r="14" fill="url(#node)" />
                  <circle cx="100" cy="380" r="3" fill="#93c5fd" />
                  <circle cx="500" cy="320" r="10" fill="url(#nodePurple)" />
                  <circle cx="500" cy="320" r="2.5" fill="#c4b5fd" />
                  <circle cx="900" cy="220" r="14" fill="url(#node)" />
                  <circle cx="900" cy="220" r="3" fill="#93c5fd" />
                  <circle cx="200" cy="60" r="10" fill="url(#nodePurple)" />
                  <circle cx="200" cy="60" r="2.5" fill="#c4b5fd" />
                  <circle cx="650" cy="20" r="8" fill="url(#node)" />
                  <circle cx="380" cy="470" r="8" fill="url(#nodePurple)" />
                  <circle cx="850" cy="280" r="6" fill="url(#node)" />
                </g>
              </svg>

              <div class="relative max-w-3xl flex flex-col gap-5">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
                  <span class="w-1.5 h-1.5 rounded-full bg-blue-400 shadow-[0_0_8px_2px_rgba(96,165,250,0.7)]"></span>
                  <span class="text-white/75" style="font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: 0.1em;">
                    AMNTDDA · GRAPH NEURAL NETWORK
                  </span>
                </div>

                <h1 class="text-white" style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: clamp(32px, 4.5vw, 48px); letter-spacing: -0.025em; line-height: 1.1;">
                  Dự đoán liên kết <span class="bg-gradient-to-r from-blue-400 via-cyan-300 to-purple-400 bg-clip-text text-transparent">Thuốc – Bệnh</span> bằng AI đồ thị
                </h1>

                <p class="text-white/65 max-w-2xl" style="font-family: 'Inter', sans-serif; font-size: 14.5px; line-height: 1.6;">
                  Chọn một hay nhiều thuốc, bệnh và để mô hình HGT tổng hợp kết quả theo từng chiều dự đoán, kèm đồ thị phân tử 2D &amp; 3D trực quan hơn.
                </p>

                <div class="flex flex-wrap items-center gap-4 mt-2">
                  <a href="#predict-form-panel" class="group relative inline-flex items-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_32px_-6px_rgba(96,165,250,0.8)] hover:shadow-[0_12px_40px_-4px_rgba(139,92,246,0.9)] transition-all" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
                    <span class="absolute inset-0 rounded-xl bg-gradient-to-r from-white/20 to-transparent opacity-50"></span>
                    <span class="relative">Bắt đầu dự đoán</span>
                    <i data-lucide="arrow-right" class="relative w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                  </a>

                  <div class="inline-flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-white/[0.03] border border-white/5">
                    <span class="relative flex w-2 h-2">
                      <span class="absolute inset-0 rounded-full <?php echo $apiHealthy ? 'bg-emerald-400' : 'bg-red-400'; ?> animate-ping opacity-75"></span>
                      <span class="relative w-2 h-2 rounded-full <?php echo $apiHealthy ? 'bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,0.9)]' : 'bg-red-400 shadow-[0_0_10px_rgba(248,113,113,0.9)]'; ?>"></span>
                    </span>
                    <span class="text-white/55" style="font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 0.04em;">
                      AI API · <?php echo $apiHealthy ? 'Trực tuyến' : 'Ngoại tuyến'; ?>
                    </span>
                  </div>
                  
                  <div class="inline-flex items-center gap-2 text-white/40 text-[11px]" style="font-family: 'Inter', sans-serif;">
                    <span>Dataset: <strong class="text-white/70"><?php echo e($dataset); ?></strong></span>
                    <span class="text-white/10">•</span>
                    <span>Thuốc: <strong class="text-white/70"><?php echo number_format($counts['drugs']); ?></strong></span>
                    <span class="text-white/10">•</span>
                    <span>Bệnh: <strong class="text-white/70"><?php echo number_format($counts['diseases']); ?></strong></span>
                  </div>
                </div>
              </div>
            </section>

            <!-- ── Flash / Error ── -->
            <?php if ($success): ?>
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-sm font-medium" style="font-family: 'Inter', sans-serif;"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300 text-sm font-medium" style="font-family: 'Inter', sans-serif;"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- ── Prediction Form ── -->
            <section id="predict-form-panel" class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 lg:p-8 overflow-hidden">
              <div class="absolute -top-32 right-1/4 w-80 h-80 rounded-full bg-purple-500/10 blur-3xl pointer-events-none"></div>

              <div class="relative flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/30 to-purple-600/30 border border-white/10 grid place-items-center text-blue-300">
                    <i data-lucide="dna" class="w-[18px] h-[18px]"></i>
                  </div>
                  <div>
                    <h2 class="text-white" style="font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 600; letter-spacing: -0.01em;">
                      Dự đoán liên kết
                    </h2>
                    <p class="text-white/45" style="font-family: 'Inter', sans-serif; font-size: 12px;">
                      Chọn thuốc và bệnh để mô hình HGT phân tích liên kết
                    </p>
                  </div>
                </div>

              </div>

              <form method="POST" action="" id="predict-form" onsubmit="return handlePredictSubmit()">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="relative grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
                  <div class="flex flex-col">
                    <div id="drug-picker-host"></div>
                  </div>
                  <div class="flex flex-col">
                    <div id="disease-picker-host"></div>
                  </div>
                </div>

                <div class="relative grid grid-cols-1 md:grid-cols-[160px_160px_auto] gap-4 items-end">
                  
                  <label class="flex flex-col gap-1.5">
                    <span class="text-white/50 px-1" style="font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: 0.04em;">
                      Top-K (1 chiều)
                    </span>
                    <div class="relative">
                      <select name="top_k" id="top_k" class="appearance-none w-full h-[46px] pl-4 pr-10 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white cursor-pointer" style="font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500;">
                        <?php foreach ([5, 10, 15, 20] as $k): ?>
                          <option value="<?= $k ?>" <?= $topK === $k ? 'selected' : '' ?> class="bg-[#0a0a0f]"><?= $k ?></option>
                        <?php endforeach; ?>
                      </select>
                      <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 pointer-events-none w-[15px] h-[15px]"></i>
                    </div>
                  </label>

                  <label class="flex flex-col gap-1.5">
                    <span class="text-white/50 px-1" style="font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: 0.04em;">
                      Dataset
                    </span>
                    <div class="relative">
                      <select name="dataset" id="dataset" class="appearance-none w-full h-[46px] pl-4 pr-10 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white cursor-pointer" style="font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500;">
                        <?php foreach (['B-dataset', 'C-dataset', 'F-dataset'] as $ds): ?>
                          <option value="<?= $ds ?>" <?= $dataset === $ds ? 'selected' : '' ?> class="bg-[#0a0a0f]"><?= $ds ?></option>
                        <?php endforeach; ?>
                      </select>
                      <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 pointer-events-none w-[15px] h-[15px]"></i>
                    </div>
                  </label>

                  <div class="flex items-center justify-end md:justify-start">
                    <button type="submit" <?= !$apiHealthy ? 'disabled' : '' ?> class="group relative inline-flex items-center justify-center gap-2 h-[46px] px-8 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_32px_-8px_rgba(96,165,250,0.7)] hover:shadow-[0_12px_40px_-4px_rgba(139,92,246,0.8)] transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none w-full md:w-auto" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
                      <i data-lucide="play" class="fill-current w-[14px] h-[14px]"></i>
                      <span>Chạy dự đoán</span>
                    </button>
                  </div>

                </div>
              </form>
            </section>

            <!-- ── Results ── -->
            <?php if ($hasResults): ?>
            <div id="result-section" class="flex flex-col gap-6">
                
                <!-- Results Header Banner exactly matching the image -->
                <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 lg:p-8 overflow-hidden" style="margin-bottom: 0.5rem;">
                    <div class="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>
                    <div class="absolute -bottom-32 -right-20 w-96 h-96 rounded-full bg-purple-500/10 blur-3xl pointer-events-none"></div>
                    
                    <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-6 z-10">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 grid place-items-center text-emerald-400 shrink-0 shadow-[0_0_20px_rgba(16,185,129,0.15)]">
                                <i data-lucide="sparkles" class="w-5.5 h-5.5"></i>
                            </div>
                            <div>
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold tracking-wider uppercase mb-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                    KẾT QUẢ DỰ ĐOÁN
                                </div>
                                <h2 class="text-white font-bold text-2xl tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                                    Kết quả dự đoán liên kết
                                </h2>
                                <p class="text-white/50 text-sm mt-1.5 max-w-2xl leading-relaxed" style="font-family: 'Inter', sans-serif;">
                                    So sánh điểm dự đoán giữa mô hình HGT cải tiến và baseline gốc — kèm cấu trúc phân tử SMILES và đồ thị benchmark trực quan.
                                </p>
                            </div>
                        </div>
                        
                        </div>
                    </div>
                </section>
                
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
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 lg:p-8 overflow-hidden">
                <h3 class="text-white mb-4" style="font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 600;">Hướng dẫn sử dụng</h3>
                <ol class="list-decimal pl-5 space-y-2 text-white/60" style="font-family: 'Inter', sans-serif; font-size: 13.5px; line-height: 1.7;">
                    <li>Chọn tối đa 5 thuốc và 5 bệnh ngay trong hai picker trực quan.</li>
                    <li>Nếu chỉ chọn một bên, hệ thống sẽ chạy Top-K một chiều như trước.</li>
                    <li>Nếu chọn cả hai bên, hệ thống sẽ chấm mọi cặp thuốc × bệnh đã chọn và vẽ biểu đồ so sánh 2 model.</li>
                    <li>Top-K chỉ áp dụng cho chế độ một chiều; ở chế độ cặp chính xác, toàn bộ cặp đã chọn sẽ được hiển thị.</li>
                    <li>Nhấn <strong>Chạy dự đoán</strong> để xem bảng điểm từng cặp, ma trận thuốc × bệnh, hoặc đồ thị phân tử khi chạy một chiều.</li>
                </ol>
            </section>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- SmilesDrawer -->
<script src="assets/js/smiles-drawer.min.js?v=1.0.1"></script>
<?php if ($hasGraphResults): ?>
<script src="assets/js/three.min.js?v=1.0.1"></script>
<script src="assets/js/three-spritetext.min.js?v=1.0.1"></script>
<script src="assets/js/3d-force-graph.min.js?v=1.0.1"></script>
<?php endif; ?>

<script>
console.log('prediction-app: Main script block started execution.');
// Hide loader on page load (in case POST returned with errors)
document.getElementById('loader').style.display = 'none';

window.showSvgTooltip = function(node, event) {
    // Manual parent traversal (SVG closest() may fail in some browsers)
    let wrapper = node.parentElement;
    while (wrapper && !wrapper.classList.contains('graph-2d-wrapper')) {
        wrapper = wrapper.parentElement;
    }
    if (!wrapper) return;
    const tooltip = wrapper.querySelector('.graph-tooltip');
    if (!tooltip) return;

    const type = node.getAttribute('data-type') || '';
    const label = node.getAttribute('data-label') || '';
    const id = node.getAttribute('data-id') || '';
    const smiles = node.getAttribute('data-smiles') || '';
    const seqLen = parseInt(node.getAttribute('data-seq-len') || '0', 10);
    const nodeId = node.getAttribute('data-node-id') || '';

    // Highlights logic: find all links and nodes in this SVG
    const svg = wrapper.querySelector('.prediction-network');
    if (svg && nodeId) {
        const links = svg.querySelectorAll('.graph-link-group');
        const nodes = svg.querySelectorAll('.graph-node');
        
        // Track which nodes are connected to our hovered node
        const connectedNodeIds = new Set([nodeId]);
        
        links.forEach((linkGroup) => {
            const src = linkGroup.getAttribute('data-source') || '';
            const tgt = linkGroup.getAttribute('data-target') || '';
            
            if (src === nodeId || tgt === nodeId) {
                // Connected link: highlight!
                linkGroup.style.opacity = '1';
                linkGroup.style.filter = 'none';
                const path = linkGroup.querySelector('.graph-edge');
                if (path) {
                    path.style.strokeWidth = '4.5px'; // thicker glowing line!
                }
                connectedNodeIds.add(src);
                connectedNodeIds.add(tgt);
            } else {
                // Unconnected link: dim and blur slightly like standard Bokeh depth-of-field
                linkGroup.style.opacity = '0.08';
                linkGroup.style.filter = 'blur(1.2px)';
                const path = linkGroup.querySelector('.graph-edge');
                if (path) {
                    path.style.strokeWidth = '';
                }
            }
        });
        
        nodes.forEach((n) => {
            const nId = n.getAttribute('data-node-id') || '';
            if (connectedNodeIds.has(nId)) {
                // Connected or hovered node: keep bright!
                n.style.opacity = '1';
                n.style.filter = 'none';
            } else {
                // Unconnected node: dim and blur slightly
                n.style.opacity = '0.22';
                n.style.filter = 'blur(1px)';
            }
        });
    }

    const wrapperBounds = wrapper.getBoundingClientRect();
    
    // Use mouse position directly - most reliable across all browsers
    let relativeLeft = event.clientX - wrapperBounds.left + 18;
    let relativeTop = event.clientY - wrapperBounds.top - 30;
    
    // Clamp to stay inside wrapper
    if (relativeLeft + 260 > wrapperBounds.width) {
        relativeLeft = event.clientX - wrapperBounds.left - 270;
    }
    
    tooltip.style.left = `${relativeLeft}px`;
    tooltip.style.top = `${Math.max(8, relativeTop)}px`;
    tooltip.style.display = 'block';

    const esc = (str) => String(str || '').replace(/[&<>"']/g, (m) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m] || m));

    if (type === 'drug') {
        let chemStr = '';
        try {
            let chemDetails = (typeof drugExactDetails !== 'undefined' ? drugExactDetails[id.toLowerCase()] : null) || (typeof parseSmilesToFormulaAndWeight === 'function' ? parseSmilesToFormulaAndWeight(smiles) : {formula:'',weight:''});
            if (chemDetails.formula && chemDetails.weight) {
                chemStr = `${chemDetails.formula} · ${chemDetails.weight}`;
            } else if (chemDetails.formula) {
                chemStr = chemDetails.formula;
            } else if (chemDetails.weight) {
                chemStr = chemDetails.weight;
            }
        } catch(e) {}
        
        tooltip.className = 'graph-tooltip absolute z-50 rounded-2xl bg-[#070b13]/96 border border-cyan-500/30 shadow-[0_0_20px_rgba(34,211,238,0.15)] shadow-[0_12px_40px_rgba(0,0,0,0.85)] backdrop-blur-md p-4';
        tooltip.innerHTML = `
            <div class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center border border-cyan-500/30 bg-cyan-950/20 text-cyan-400">
                        <i data-lucide="pill" class="w-4 h-4"></i>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full bg-cyan-950/50 border border-cyan-500/20 text-cyan-400 text-[10px] font-bold tracking-wider">THUỐC</span>
                </div>
                <div>
                    <h4 class="text-white font-bold font-['Space_Grotesk'] text-[15px] leading-snug">${esc(label)}</h4>
                    <div class="text-cyan-400 font-mono text-[11px] mt-0.5">${esc(id)}</div>
                </div>
                ${chemStr ? `<div class="text-white/60 text-[10.5px] mt-0.5">${esc(chemStr)}</div>` : ''}
            </div>
        `;
    } else if (type === 'protein') {
        let seqStr = seqLen ? `Độ dài chuỗi: ${seqLen} aa` : 'Cầu nối liên kết';
        
        tooltip.className = 'graph-tooltip absolute z-50 rounded-2xl bg-[#070b13]/96 border border-amber-500/30 shadow-[0_0_20px_rgba(245,158,11,0.15)] shadow-[0_12px_40px_rgba(0,0,0,0.85)] backdrop-blur-md p-4';
        tooltip.innerHTML = `
            <div class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center border border-amber-500/30 bg-amber-950/20 text-amber-400">
                        <i data-lucide="dna" class="w-4 h-4"></i>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full bg-amber-950/50 border border-amber-500/20 text-amber-400 text-[10px] font-bold tracking-wider">PROTEIN</span>
                </div>
                <div>
                    <h4 class="text-white font-bold font-['Space_Grotesk'] text-[15px] leading-snug">${esc(label)}</h4>
                    <div class="text-amber-400 font-mono text-[11px] mt-0.5">${esc(id)}</div>
                </div>
                <div class="text-white/60 text-[10.5px] mt-0.5">${esc(seqStr)}</div>
            </div>
        `;
    } else {
        tooltip.className = 'graph-tooltip absolute z-50 rounded-2xl bg-[#070b13]/96 border border-red-500/30 shadow-[0_0_20px_rgba(239,68,68,0.15)] shadow-[0_12px_40px_rgba(0,0,0,0.85)] backdrop-blur-md p-4';
        tooltip.innerHTML = `
            <div class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center border border-red-500/30 bg-red-950/20 text-red-400">
                        <i data-lucide="activity" class="w-4 h-4"></i>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full bg-red-950/50 border border-red-500/20 text-red-400 text-[10px] font-bold tracking-wider">BỆNH</span>
                </div>
                <div>
                    <h4 class="text-white font-bold font-['Space_Grotesk'] text-[15px] leading-snug">${esc(label)}</h4>
                    <div class="text-red-400 font-mono text-[11px] mt-0.5">${esc(id)}</div>
                </div>
                <div class="text-white/60 text-[10.5px] mt-0.5">Mã liên kết bệnh lý</div>
            </div>
        `;
    }
    if (window.lucide) window.lucide.createIcons();
};

window.hideSvgTooltip = function(node) {
    // Manual parent traversal
    let wrapper = node.parentElement;
    while (wrapper && !wrapper.classList.contains('graph-2d-wrapper')) {
        wrapper = wrapper.parentElement;
    }
    if (!wrapper) return;
    const tooltip = wrapper.querySelector('.graph-tooltip');
    if (tooltip) {
        tooltip.style.display = 'none';
    }

    // Reset Highlights logic
    const svg = wrapper.querySelector('.prediction-network');
    if (svg) {
        const links = svg.querySelectorAll('.graph-link-group');
        const nodes = svg.querySelectorAll('.graph-node');
        
        links.forEach((linkGroup) => {
            linkGroup.style.opacity = '';
            linkGroup.style.filter = '';
            const path = linkGroup.querySelector('.graph-edge');
            if (path) {
                path.style.strokeWidth = '';
            }
        });
        
        nodes.forEach((n) => {
            n.style.opacity = '';
            n.style.filter = '';
        });
    }
};

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

    const isDrug = hostId.includes('drug');
    const labelText = isDrug ? 'Tên thuốc' : 'Tên bệnh';
    const accent = isDrug ? 'blue' : 'purple';
    const iconHtml = isDrug 
        ? '<i data-lucide="pill" class="w-3.5 h-3.5"></i>' 
        : '<i data-lucide="heart-pulse" class="w-3.5 h-3.5"></i>';

    const accentGlow = accent === "blue"
        ? "shadow-[0_0_24px_-8px_rgba(96,165,250,0.4)]"
        : "shadow-[0_0_24px_-8px_rgba(139,92,246,0.4)]";
    const accentText = accent === "blue" ? "text-blue-300" : "text-purple-300";
    const accentBg = accent === "blue" ? "bg-blue-500/15 border-blue-500/30" : "bg-purple-500/15 border-purple-500/30";
    const accentDot = accent === "blue" ? "bg-blue-400" : "bg-purple-400";

    host.className = `rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-4 backdrop-blur-xl ${accentGlow}`;
    host.innerHTML = `
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-lg grid place-items-center border ${accentBg} ${accentText}">
            ${iconHtml}
          </div>
          <span class="text-white font-semibold" style="font-family: 'Space Grotesk', sans-serif; font-size: 13.5px;">
            ${labelText}
          </span>
        </div>
        <span class="px-2 py-0.5 rounded-full border ${accentBg} ${accentText} font-mono text-[10.5px] font-semibold picker-count-header">
          ${selectedIds.length}/${maxSelected}
        </span>
      </div>

      <div class="relative mb-3">
        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-white/40 w-3.5 h-3.5"></i>
        <input
          type="text"
          placeholder="Tìm kiếm..."
          class="w-full pl-8 pr-3 py-2 rounded-xl bg-black/40 border border-white/[0.06] focus:border-blue-400/40 focus:outline-none text-white placeholder:text-white/30 text-[12.5px]"
          style="font-family: 'Inter', sans-serif;"
        />
      </div>

      <div class="flex flex-wrap gap-1.5 mb-3 picker-selected-tags"></div>

      <div class="h-[240px] overflow-y-auto pr-1 -mr-1 space-y-1 custom-scroll picker-list"></div>

      <div class="mt-3 pt-3 border-t border-white/5 flex items-center justify-between">
        <span class="text-white/40 text-[11px]" style="font-family: 'Inter', sans-serif;">Đã chọn</span>
        <span class="text-white font-mono text-[12px] font-semibold picker-count-footer">
          ${selectedIds.length}/${maxSelected}
        </span>
      </div>
      
      <div class="hidden-inputs"></div>
    `;

    const searchEl = host.querySelector('input');
    const tagsEl = host.querySelector('.picker-selected-tags');
    const listEl = host.querySelector('.picker-list');
    const headerCountEl = host.querySelector('.picker-count-header');
    const footerCountEl = host.querySelector('.picker-count-footer');
    const hiddenEl = host.querySelector('.hidden-inputs');

    const render = () => {
        const query = (searchEl.value || '').trim().toLowerCase();

        // Render selected tags
        if (selectedIds.length > 0) {
            tagsEl.style.display = 'flex';
            tagsEl.innerHTML = selectedIds.map((id) => {
                const option = normalizedOptions.find((item) => item.id === id);
                const name = option ? option.name : id;
                return `
                  <span class="inline-flex items-center gap-1 pl-2.5 pr-1.5 py-1 rounded-full ${accentBg} border ${accentText} text-[11px] font-medium" style="font-family: 'Inter', sans-serif;">
                    ${escapeHtml(name)}
                    <button type="button" data-remove="${escapeHtml(id)}" class="w-3.5 h-3.5 grid place-items-center rounded-full hover:bg-white/10">
                      <i data-lucide="x" class="w-2.5 h-2.5"></i>
                    </button>
                  </span>
                `;
            }).join('');
        } else {
            tagsEl.style.display = 'none';
            tagsEl.innerHTML = '';
        }

        // Render hidden input fields
        hiddenEl.innerHTML = selectedIds.map((id) => `<input type="hidden" name="${escapeHtml(config.inputName)}" value="${escapeHtml(id)}">`).join('');

        // Filter options
        const filtered = normalizedOptions.filter((option) => !query || option.search.includes(query));
        const limited = filtered.slice(0, 80);

        listEl.innerHTML = limited.length
            ? limited.map((option) => {
                const selected = selectedIds.includes(option.id);
                const disabled = !selected && selectedIds.length >= maxSelected;
                return `
                  <button
                    type="button"
                    data-option="${escapeHtml(option.id)}"
                    ${disabled ? 'disabled' : ''}
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-left text-[12.5px] ${
                      selected
                        ? `${accentBg} border ${accentText}`
                        : "border border-transparent text-white/70 hover:bg-white/[0.04] hover:text-white"
                    } ${disabled ? "opacity-40 cursor-not-allowed" : ""}"
                    style="font-family: 'Inter', sans-serif;"
                  >
                    <div class="w-4 h-4 rounded-[5px] border grid place-items-center shrink-0 ${
                      selected ? `${accentDot} border-transparent` : "border-white/20 bg-black/30"
                    }">
                      ${selected ? '<i data-lucide="check" class="text-white w-2.5 h-2.5" style="stroke-width: 3px;"></i>' : ''}
                    </div>
                    <span class="flex-1 truncate">${escapeHtml(option.name)}</span>
                    <span class="text-white/30 text-[10.5px] font-mono">${escapeHtml(option.id)}</span>
                  </button>
                `;
            }).join('')
            : `<div class="text-center py-8 text-white/30 text-[12px]" style="font-family: 'Inter', sans-serif;">
                 Không tìm thấy kết quả
               </div>`;

        headerCountEl.textContent = `${selectedIds.length}/${maxSelected}`;
        footerCountEl.textContent = `${selectedIds.length}/${maxSelected}`;
        
        // Refresh icons
        if (window.lucide) {
            window.lucide.createIcons();
        }
    };

    tagsEl.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-remove]');
        if (!button) return;
        toggleValue(button.dataset.remove || '');
    });

    listEl.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-option]');
        if (!button) return;
        toggleValue(button.dataset.option || '');
    });

    searchEl.addEventListener('input', render);
    searchEl.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const firstOption = listEl.querySelector('button[data-option]:not([disabled])');
        if (firstOption) {
            toggleValue(firstOption.dataset.option || '');
        }
    });

    const toggleValue = (id) => {
        const index = selectedIds.indexOf(id);
        if (index > -1) {
            selectedIds.splice(index, 1);
        } else {
            if (selectedIds.length >= maxSelected) {
                alert(`Chỉ được chọn tối đa ${maxSelected} mục.`);
                return;
            }
            selectedIds.push(id);
        }
        render();
    };

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
    
    // Initial draw of icons
    if (window.lucide) {
        window.lucide.createIcons();
    }
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

const drugExactDetails = {
    'db00659': { formula: 'C₅H₁₁NO₄S', weight: '181.21 g/mol' }, // Acamprosate
    'db00035': { formula: 'C₄₆H₆₄N₁₄O₁₂S₂', weight: '1069.2 g/mol' }, // Desmopressin
    'db00215': { formula: 'C₈H₁₀AsNO₅', weight: '275.09 g/mol' }, // Acetarsol
    'db00141': { formula: 'C₁₀H₁₄N₅O₇P', weight: '347.22 g/mol' }, // AMP
    'db00157': { formula: 'C₁₁H₁₇NO₃', weight: '211.26 g/mol' }, // Metaproterenol
    'db00316': { formula: 'C₁₇H₁₉N₃O₃S', weight: '345.42 g/mol' }, // Omeprazole
    'db00338': { formula: 'C₉H₁₃NO₃', weight: '183.20 g/mol' }, // Albuterol
    'db00388': { formula: 'C₁₀H₁₂N₄O₅', weight: '268.23 g/mol' }, // Inosine
    'db00475': { formula: 'C₁₅H₁₄N₄O₆S₂', weight: '442.42 g/mol' }, // Ceftriaxone
    'db00530': { formula: 'C₁₆H₁₉N₃O₄S', weight: '349.41 g/mol' }, // Amoxicillin
    'db00650': { formula: 'C₁₄H₁₈N₄O₃', weight: '290.32 g/mol' }, // Trimethoprim
    'db00945': { formula: 'C₁₉H₂₀F₃N₃O₃', weight: '409.38 g/mol' }, // Nilutamide
    'db01008': { formula: 'C₈H₁₁N₅O₃', weight: '225.21 g/mol' }, // Acyclovir
};

function parseSmilesToFormulaAndWeight(smiles) {
    if (!smiles) return { formula: '', weight: '' };
    try {
        let clean = smiles.replace(/[@\-\=\#\$\/\\]/g, '');
        let atoms = { 'C': 0, 'N': 0, 'O': 0, 'S': 0, 'P': 0, 'F': 0, 'Cl': 0, 'Br': 0, 'I': 0, 'H': 0 };
        let regex = /Cl|Br|C|N|O|S|P|F|I|H/g;
        let match;
        let explicitBonds = 0;
        for (let char of smiles) {
            if (char === '=') explicitBonds += 1;
            else if (char === '#') explicitBonds += 2;
        }
        while ((match = regex.exec(clean)) !== null) {
            let symbol = match[0];
            atoms[symbol] = (atoms[symbol] || 0) + 1;
        }
        let ringClosures = (smiles.match(/\d/g) || []).length / 2;
        let c = atoms['C'] || 0;
        let n = atoms['N'] || 0;
        let o = atoms['O'] || 0;
        let s = atoms['S'] || 0;
        let f = atoms['F'] || 0;
        let cl = atoms['Cl'] || 0;
        let br = atoms['Br'] || 0;
        let i = atoms['I'] || 0;
        
        let hCount = (2 * c + 2) + n - (f + cl + br + i);
        hCount -= 2 * ringClosures;
        hCount -= 2 * explicitBonds;
        atoms['H'] = Math.max(0, Math.round(hCount));
        
        let formula = '';
        if (atoms['C'] > 0) {
            formula += 'C' + (atoms['C'] > 1 ? atoms['C'] : '');
            if (atoms['H'] > 0) {
                formula += 'H' + (atoms['H'] > 1 ? atoms['H'] : '');
            }
        }
        let elements = ['Br', 'Cl', 'F', 'I', 'N', 'O', 'P', 'S'];
        for (let el of elements) {
            if (atoms[el] > 0) {
                formula += el + (atoms[el] > 1 ? atoms[el] : '');
            }
        }
        let weights = { 'C': 12.011, 'H': 1.008, 'N': 14.007, 'O': 15.999, 'S': 32.06, 'P': 30.974, 'F': 18.998, 'Cl': 35.45, 'Br': 79.904, 'I': 126.904 };
        let totalWeight = 0;
        for (let el in atoms) {
            if (weights[el]) {
                totalWeight += atoms[el] * weights[el];
            }
        }
        return {
            formula: formula,
            weight: totalWeight > 0 ? totalWeight.toFixed(2) + ' g/mol' : ''
        };
    } catch(e) {
        return { formula: '', weight: '' };
    }
}


// Removed obsolete initGraphTooltips IIFE to prevent mouseover event delegation conflicts.
// The active tooltip system runs perfectly on inline onmouseenter/onmouseleave events and window.showSvgTooltip.


function toggle3DMode(trigger) {
    const wrapper = trigger.closest('.graph-2d-wrapper');
    if (!wrapper) return;
    
    const svg = wrapper.querySelector('.prediction-network');
    const canvas3d = wrapper.querySelector('.canvas-3d-inline');
    const btnSpan = trigger.querySelector('span');
    const icon = trigger.querySelector('i, svg');
    const is3DActive = canvas3d.style.display === 'block';

    if (is3DActive) {
        // Switch back to 2D
        canvas3d.style.display = 'none';
        svg.style.display = 'block';
        trigger.classList.remove('from-cyan-500/30', 'to-blue-500/30', 'border-cyan-400/50', 'shadow-[0_0_20px_-2px_rgba(34,211,238,0.5)]');
        trigger.classList.add('from-cyan-500/10', 'to-blue-500/10', 'border-cyan-400/20');
        trigger.style.color = '';
        if (btnSpan) btnSpan.textContent = '3D Mode';
        if (icon) icon.classList.remove('animate-spin-slow');
        if (window._graph3d && window._graph3d.pauseAnimation) {
            window._graph3d.pauseAnimation();
        }
    } else {
        // Switch to 3D
        const svgBounds = svg.getBoundingClientRect();
        const svgHeight = Math.max(480, svgBounds.height || svg.clientHeight || 480);
        const svgWidth = Math.max(800, svgBounds.width || svg.clientWidth || 800);
        
        canvas3d.style.height = `${svgHeight}px`;
        canvas3d.style.width = `${svgWidth}px`;
        
        svg.style.display = 'none';
        canvas3d.style.display = 'block';
        trigger.classList.remove('from-cyan-500/10', 'to-blue-500/10', 'border-cyan-400/20');
        trigger.classList.add('from-cyan-500/30', 'to-blue-500/30', 'border-cyan-400/50', 'shadow-[0_0_20px_-2px_rgba(34,211,238,0.5)]');
        trigger.style.color = '#fff';
        if (btnSpan) btnSpan.innerHTML = '3D Mode &middot; Active';
        if (icon) icon.classList.add('animate-spin-slow');
        
        if (canvas3d.clientWidth === 0 || canvas3d.clientHeight === 0) {
            canvas3d.style.minHeight = '480px';
        }
        
        // Small delay to allow DOM to paint the block display before calculating sizes
        setTimeout(() => {
            init3D(wrapper);
            window.dispatchEvent(new Event('resize'));
        }, 50);
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
    const loadingEl = document.getElementById('loading-3d');
<?php if ($hasGraphResults): ?>
    if (!window.ForceGraph3D) {
        if (loadingEl) loadingEl.textContent = '⚠ Không thể tải thư viện 3D (ForceGraph3D). Hãy kiểm tra kết nối mạng.';
        console.error('3D Force Graph library not loaded.');
        return;
    }
    if (!wrapper) return;
    
    const container = wrapper.querySelector('.canvas-3d-inline');
    if (!container) return;
    
    try {
        console.log('init3D: Starting robust 3D initialization...');
        const graphData = JSON.parse(wrapper.dataset.graph || '{}');
        
        // Clear container completely to force a fresh render!
        container.innerHTML = '';
        
        const w = container.clientWidth || 800;
        const h = container.clientHeight || 480;

        const graphPayload = { nodes: buildLayered3DNodes(graphData), links: graphData.links || [] };

        // Create new ForceGraph3D instance
        const graph3d = ForceGraph3D()(container);
        window._graph3d = graph3d;
        
        graph3d.backgroundColor('#050b16')
            .width(w)
            .height(h)
            .nodeOpacity(1)
            .linkOpacity(0.38)
            .linkWidth((link) => {
                const score = Number(link.score || 0.4);
                return (link.kind === 'prediction' ? 1.4 : 2.2) + score * 2.4;
            })
            .linkDirectionalParticles((link) => link.kind === 'prediction' ? 2 : 4)
            .linkDirectionalParticleWidth((link) => link.kind === 'prediction' ? 2 : 3.4)
            .linkColor((link) => {
                if (link.kind === 'source-protein') return '#22c55e';
                if (link.kind === 'protein-target') return '#22c55e';
                return '#38bdf8';
            })
            .nodeColor((node) => {
                if (node.type === 'drug') return '#38bdf8'; // sky-400
                if (node.type === 'disease') return '#f87171'; // red-400
                if (node.type === 'protein') return '#fbbf24'; // amber-400
                return '#94a3b8';
            })
            .nodeVal((node) => {
                return node.type === 'protein' ? 6 : (node.is_source ? 9.5 : 7.5);
            })
            .nodeResolution(24) // Render beautifully smooth spheres using internal THREE!
            .nodeLabel((node) => {
                const type = node.type || '';
                const label = node.label || node.actual_id || node.id;
                const id = node.actual_id || node.id;
                const smiles = node.smiles || '';
                const seqLen = node.seq_len || 0;
                
                const escapeString = (str) => String(str).replace(/[&<>"']/g, (m) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m]));

                if (type === 'drug') {
                    let badgeColor = 'color: #22d3ee; background: rgba(8,47,73,0.5); border: 1px solid rgba(14,116,144,0.2);';
                    let iconColor = 'border: 1px solid rgba(34,211,238,0.3); background: rgba(8,47,73,0.2); color: #22d3ee;';
                    let idColor = 'color: #22d3ee;';
                    
                    let chemDetails = { formula: '', weight: '' };
                    try {
                        const lowId = String(id).toLowerCase();
                        chemDetails = (typeof drugExactDetails !== 'undefined' ? drugExactDetails[lowId] : null) || 
                                      (typeof parseSmilesToFormulaAndWeight === 'function' ? parseSmilesToFormulaAndWeight(smiles) : {formula:'',weight:''});
                    } catch (e) {
                        console.warn('Error parsing chemical details:', e);
                    }
                    
                    let chemStr = '';
                    if (chemDetails && chemDetails.formula && chemDetails.weight) {
                        chemStr = `${chemDetails.formula} · ${chemDetails.weight}`;
                    } else if (chemDetails && chemDetails.formula) {
                        chemStr = chemDetails.formula;
                    } else if (chemDetails && chemDetails.weight) {
                        chemStr = chemDetails.weight;
                    }
                    
                    return `<div style="background: rgba(7,11,19,0.96); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.85); padding: 16px; min-width: 230px; font-family: 'Inter', sans-serif;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <!-- Icon Box -->
                                <div style="width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; ${iconColor} font-size: 14px;">
                                    💊
                                </div>
                                <!-- Badge Pill -->
                                <span style="padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; letter-spacing: 0.05em; ${badgeColor}">THUỐC</span>
                            </div>
                            <div>
                                <h4 style="color: #fff; font-weight: bold; font-family: 'Space Grotesk', sans-serif; font-size: 15px; margin: 0; line-height: 1.35;">${escapeString(label)}</h4>
                                <div style="${idColor} font-family: monospace; font-size: 11px; margin-top: 2px;">${escapeString(id)}</div>
                            </div>
                            ${chemStr ? `<div style="color: rgba(255,255,255,0.6); font-size: 10.5px; margin-top: 2px;">${escapeString(chemStr)}</div>` : ''}
                        </div>
                    </div>`;
                } else if (type === 'protein') {
                    let badgeColor = 'color: #fbbf24; background: rgba(120,53,4,0.5); border: 1px solid rgba(180,83,9,0.2);';
                    let iconColor = 'border: 1px solid rgba(245,158,11,0.3); background: rgba(120,53,4,0.2); color: #fbbf24;';
                    let idColor = 'color: #fbbf24;';
                    
                    let seqStr = seqLen ? `Độ dài chuỗi: ${seqLen} aa` : 'Cầu nối liên kết';
                    
                    return `<div style="background: rgba(7,11,19,0.96); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.85); padding: 16px; min-width: 230px; font-family: 'Inter', sans-serif;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <!-- Icon Box -->
                                <div style="width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; ${iconColor} font-size: 14px;">
                                    🧬
                                </div>
                                <!-- Badge Pill -->
                                <span style="padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; letter-spacing: 0.05em; ${badgeColor}">PROTEIN</span>
                            </div>
                            <div>
                                <h4 style="color: #fff; font-weight: bold; font-family: 'Space Grotesk', sans-serif; font-size: 15px; margin: 0; line-height: 1.35;">${escapeString(label)}</h4>
                                <div style="${idColor} font-family: monospace; font-size: 11px; margin-top: 2px;">${escapeString(id)}</div>
                            </div>
                            <div style="color: rgba(255,255,255,0.6); font-size: 10.5px; margin-top: 2px;">${escapeString(seqStr)}</div>
                        </div>
                    </div>`;
                } else {
                    let badgeColor = 'color: #ef4444; background: rgba(127,29,29,0.5); border: 1px solid rgba(185,28,28,0.2);';
                    let iconColor = 'border: 1px solid rgba(239,68,68,0.3); background: rgba(127,29,29,0.2); color: #ef4444;';
                    let idColor = 'color: #ef4444;';
                    
                    return `<div style="background: rgba(7,11,19,0.96); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.85); padding: 16px; min-width: 230px; font-family: 'Inter', sans-serif;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <!-- Icon Box -->
                                <div style="width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; ${iconColor} font-size: 14px;">
                                    📈
                                </div>
                                <!-- Badge Pill -->
                                <span style="padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; letter-spacing: 0.05em; ${badgeColor}">BỆNH</span>
                            </div>
                            <div>
                                <h4 style="color: #fff; font-weight: bold; font-family: 'Space Grotesk', sans-serif; font-size: 15px; margin: 0; line-height: 1.35;">${escapeString(label)}</h4>
                                <div style="${idColor} font-family: monospace; font-size: 11px; margin-top: 2px;">${escapeString(id)}</div>
                            </div>
                            <div style="color: rgba(255,255,255,0.6); font-size: 10.5px; margin-top: 2px;">Mã liên kết bệnh lý</div>
                        </div>
                    </div>`;
                }
            })
            .onNodeHover((node) => {
                container.style.cursor = node ? 'pointer' : 'default';
            })
            .graphData(graphPayload);

        if (window._graph3dResizeListener) {
            window.removeEventListener('resize', window._graph3dResizeListener);
        }
        window._graph3dResizeListener = () => {
            if (graph3d && graph3d.width && graph3d.height) {
                graph3d.width(container.clientWidth || window.innerWidth);
                graph3d.height(container.clientHeight || (window.innerHeight - 56));
            }
        };
        window.addEventListener('resize', window._graph3dResizeListener);

        setTimeout(() => {
            if (graph3d.zoomToFit) {
                graph3d.zoomToFit(800, 100);
                
                // Kích hoạt tự động xoay sau khi zoom kết thúc (800ms)
                setTimeout(() => {
                    if (graph3d.controls) {
                        graph3d.controls().autoRotate = true;
                        graph3d.controls().autoRotateSpeed = 1.2;
                    }
                }, 850);
            }
        }, 180);
    } catch (err) {
        console.error('init3D error:', err);
        if (container) {
            container.innerHTML = `
                <div style="height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center; color: #ef4444; background: #050b16; padding: 24px; font-family: sans-serif; border-radius: 24px; gap: 12px; border: 1px solid rgba(239,68,68,0.2);">
                    <div style="font-size: 40px;">⚠️</div>
                    <div style="font-weight: bold; font-size: 16px;">Không thể dựng đồ thị 3D</div>
                    <div style="font-size: 12px; color: rgba(255,255,255,0.5); max-width: 400px; line-height: 1.5; font-family: monospace;">${err.message}</div>
                </div>
            `;
        }
    }
<?php else: ?>
    void wrapper;
<?php endif; ?>
}
</script>
</body>
</html>
