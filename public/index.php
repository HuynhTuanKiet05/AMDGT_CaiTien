<?php
require_once __DIR__ . '/../app/services/PredictionService.php';
require_login();

$user = current_user();
$allowedDatasets = ['B-dataset', 'C-dataset', 'F-dataset'];
$dataset   = $_REQUEST['dataset'] ?? 'C-dataset';
$dataset   = in_array($dataset, $allowedDatasets, true) ? $dataset : 'C-dataset';
$topK         = max(1, min(20, (int) ($_POST['top_k'] ?? 10)));
$drugInput    = trim((string) ($_POST['drug_input'] ?? ''));
$diseaseInput = trim((string) ($_POST['disease_input'] ?? ''));
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

    return ['drugs' => $drugs, 'diseases' => $diseases];
}

function render_prediction_graph(array $graph): string
{
    $nodes = $graph['nodes'] ?? [];
    $links = $graph['links'] ?? [];

    $sourceNode   = null;
    $proteinNodes = [];
    $targetNodes  = [];
    $sourceType   = null;

    foreach ($nodes as $node) {
        $type = $node['type'] ?? '';
        if ($sourceNode === null && in_array($type, ['drug', 'disease'], true)) {
            $sourceNode = $node;
            $sourceType = $type;
        } elseif ($type === 'protein') {
            $proteinNodes[] = $node;
        } elseif (in_array($type, ['drug', 'disease'], true)) {
            $targetNodes[] = $node;
        }
    }

    if (!$sourceNode) {
        return '<p class="muted" style="text-align:center;padding:2rem;">Không có dữ liệu đồ thị.</p>';
    }

    $structureNodes = [];
    $seenStructureIds = [];
    foreach (array_merge([$sourceNode], $targetNodes) as $node) {
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

    $nProteins = max(count($proteinNodes), 1);
    $nTargets  = max(count($targetNodes), 1);
    $rowCount  = max($nProteins, $nTargets);
    $height    = max(380, 60 + $rowCount * 96);
    $width     = 1000;

    $col1X  = 110;
    $col2X  = 430;
    $col3X  = 860;
    $topPad = 56;
    $usable = $height - $topPad * 2;

    $positions = [];
    $positions[$sourceNode['id']] = [$col1X, (int) ($height / 2)];

    foreach ($proteinNodes as $i => $node) {
        $y = (int) ($topPad + ($i + 0.5) * $usable / count($proteinNodes));
        $positions[$node['id']] = [$col2X, $y];
    }
    foreach ($targetNodes as $i => $node) {
        $y = (int) ($topPad + ($i + 0.5) * $usable / count($targetNodes));
        $positions[$node['id']] = [$col3X, $y];
    }

    ob_start();
    ?>
    <div class="graph-2d-wrapper" id="graph-2d-wrapper" data-graph='<?= json_encode($graph, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>'>
        <div class="graph-toolbar">
            <span class="graph-legend">
                <span class="legend-item legend-drug">⬡ Thuốc</span>
                <span class="legend-item legend-protein">H Protein</span>
                <span class="legend-item legend-disease">✚ Bệnh</span>
            </span>
            <button class="btn btn-sm btn-ghost" onclick="open3DModal()" type="button">
                <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px">view_in_ar</span>
                Xem 3D
            </button>
        </div>
        <svg class="prediction-network" viewBox="0 0 <?= $width ?> <?= $height ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Sơ đồ liên kết phân tử">
            <defs>
                <filter id="glow-src" x="-60%" y="-60%" width="220%" height="220%">
                    <feGaussianBlur stdDeviation="5" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
                <marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">
                    <path d="M0,0 L10,5 L0,10 z" fill="#22c55e" fill-opacity="0.7"/>
                </marker>
            </defs>

            <rect x="24" y="48" width="210" height="<?= max(300, $height - 86) ?>" rx="18" class="graph-panel-bg"/>
            <rect x="318" y="48" width="226" height="<?= max(300, $height - 86) ?>" rx="18" class="graph-panel-bg"/>
            <rect x="650" y="48" width="326" height="<?= max(300, $height - 86) ?>" rx="18" class="graph-panel-bg"/>

            <!-- Column labels -->
            <text x="<?= $col1X ?>" y="30" class="graph-col-label" text-anchor="middle"><?= $sourceType === 'drug' ? 'Thuốc' : 'Bệnh' ?></text>
            <?php if (count($proteinNodes) > 0): ?>
                <text x="<?= $col2X ?>" y="30" class="graph-col-label" text-anchor="middle">Protein liên quan</text>
            <?php endif; ?>
            <text x="<?= $col3X ?>" y="30" class="graph-col-label" text-anchor="middle"><?= $sourceType === 'drug' ? 'Bệnh dự đoán' : 'Thuốc dự đoán' ?></text>

            <!-- Edges -->
            <?php foreach ($links as $link): ?>
                <?php
                $src = $positions[$link['source']] ?? null;
                $tgt = $positions[$link['target']] ?? null;
                if (!$src || !$tgt) continue;
                $score   = max(0.05, min(1.0, (float) ($link['score'] ?? 0.5)));
                $kind    = (string) ($link['kind'] ?? 'prediction');
                $opacity = 0.22 + $score * 0.52;
                $sw      = 1.2 + $score * 3.2;
                $stroke  = '#22c55e';
                $dash    = 'none';
                if ($kind === 'source-protein') {
                    $stroke = '#f59e0b';
                } elseif ($kind === 'protein-target') {
                    $stroke = '#22c55e';
                } else {
                    $stroke = '#38bdf8';
                    $dash = '7 7';
                    $opacity *= 0.7;
                    $sw = max(1.2, $sw - 0.8);
                }
                $cpX     = (int) (($src[0] + $tgt[0]) / 2);
                ?>
                <path d="M <?= $src[0] ?> <?= $src[1] ?> C <?= ($src[0] + $cpX) / 2 ?> <?= $src[1] ?>, <?= ($tgt[0] + $cpX) / 2 ?> <?= $tgt[1] ?>, <?= $tgt[0] ?> <?= $tgt[1] ?>"
                      fill="none" stroke="<?= $stroke ?>" stroke-width="<?= number_format($sw, 1) ?>" stroke-opacity="<?= number_format($opacity, 2) ?>"
                      stroke-dasharray="<?= $dash ?>"
                      class="graph-edge"/>
            <?php endforeach; ?>

            <!-- Source node -->
            <?php if (isset($positions[$sourceNode['id']])): ?>
                <?php [$x, $y] = $positions[$sourceNode['id']]; ?>
                <g class="graph-node graph-node-<?= e($sourceType ?? 'drug') ?> graph-node-source"
                   data-smiles="<?= e($sourceNode['smiles'] ?? '') ?>"
                   data-label="<?= e($sourceNode['label'] ?? '') ?>"
                   data-id="<?= e($sourceNode['actual_id'] ?? '') ?>"
                   data-type="<?= e($sourceType ?? '') ?>"
                   data-seq-len="0">
                    <?php if ($sourceType === 'drug'): ?>
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="#1a3a6b" fill-opacity="0.92" filter="url(#glow-src)"/>
                        <g transform="translate(<?= $x ?>,<?= $y ?>)">
                            <polygon points="0,-12 10.4,-6 10.4,6 0,12 -10.4,6 -10.4,-6" fill="none" stroke="#60a5fa" stroke-width="2"/>
                            <circle cx="0" cy="0" r="6" fill="none" stroke="#93c5fd" stroke-width="1.5"/>
                        </g>
                    <?php else: ?>
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24" fill="#6b1a1a" fill-opacity="0.92" filter="url(#glow-src)"/>
                        <g transform="translate(<?= $x ?>,<?= $y ?>)">
                            <rect x="-10" y="-3.5" width="20" height="7" rx="2" fill="#f87171"/>
                            <rect x="-3.5" y="-10" width="7" height="20" rx="2" fill="#f87171"/>
                        </g>
                    <?php endif; ?>
                    <text x="<?= $x ?>" y="<?= $y + 38 ?>" class="graph-node-label" text-anchor="middle"><?= e($sourceNode['label'] ?? '') ?></text>
                    <text x="<?= $x ?>" y="<?= $y + 51 ?>" class="graph-node-sublabel" text-anchor="middle"><?= e($sourceNode['actual_id'] ?? '') ?></text>
                </g>
            <?php endif; ?>

            <!-- Protein nodes -->
            <?php foreach ($proteinNodes as $node): ?>
                <?php [$x, $y] = $positions[$node['id']]; ?>
                <g class="graph-node graph-node-protein"
                   data-smiles=""
                   data-label="<?= e($node['label'] ?? '') ?>"
                   data-id="<?= e($node['actual_id'] ?? '') ?>"
                   data-type="protein"
                   data-seq-len="<?= (int) ($node['seq_len'] ?? 0) ?>">
                    <circle cx="<?= $x ?>" cy="<?= $y ?>" r="18" fill="#78350f" fill-opacity="0.85"/>
                    <g transform="translate(<?= $x ?>,<?= $y ?>)">
                        <line x1="-6" y1="-8" x2="-6" y2="8" stroke="#fcd34d" stroke-width="2.2" stroke-linecap="round"/>
                        <line x1="6" y1="-8" x2="6" y2="8" stroke="#fcd34d" stroke-width="2.2" stroke-linecap="round"/>
                        <line x1="-6" y1="0" x2="6" y2="0" stroke="#fcd34d" stroke-width="2" stroke-linecap="round"/>
                    </g>
                    <text x="<?= $x ?>" y="<?= $y + 30 ?>" class="graph-node-label" text-anchor="middle"><?= e($node['label'] ?? $node['actual_id'] ?? '') ?></text>
                    <text x="<?= $x ?>" y="<?= $y + 43 ?>" class="graph-node-sublabel" text-anchor="middle">support <?= e((string) ($node['support'] ?? 1)) ?></text>
                </g>
            <?php endforeach; ?>

            <!-- Target nodes -->
            <?php foreach ($targetNodes as $node): ?>
                <?php
                [$x, $y] = $positions[$node['id']];
                $nodeType = $node['type'] ?? 'disease';
                ?>
                <g class="graph-node graph-node-<?= e($nodeType) ?>"
                   data-smiles="<?= e($node['smiles'] ?? '') ?>"
                   data-label="<?= e($node['label'] ?? '') ?>"
                   data-id="<?= e($node['actual_id'] ?? '') ?>"
                   data-type="<?= e($nodeType) ?>"
                   data-seq-len="0">
                    <?php if ($nodeType === 'drug'): ?>
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="18" fill="#1a3a6b" fill-opacity="0.8"/>
                        <g transform="translate(<?= $x ?>,<?= $y ?>)">
                            <polygon points="0,-9 7.8,-4.5 7.8,4.5 0,9 -7.8,4.5 -7.8,-4.5" fill="none" stroke="#60a5fa" stroke-width="1.8"/>
                            <circle cx="0" cy="0" r="4.5" fill="none" stroke="#93c5fd" stroke-width="1.3"/>
                        </g>
                    <?php else: ?>
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="18" fill="#6b1a1a" fill-opacity="0.8"/>
                        <g transform="translate(<?= $x ?>,<?= $y ?>)">
                            <rect x="-8" y="-2.5" width="16" height="5" rx="1.5" fill="#f87171"/>
                            <rect x="-2.5" y="-8" width="5" height="16" rx="1.5" fill="#f87171"/>
                        </g>
                    <?php endif; ?>
                    <text x="<?= $x ?>" y="<?= $y + 30 ?>" class="graph-node-label" text-anchor="middle"><?= e($node['label'] ?? '') ?></text>
                    <text x="<?= $x ?>" y="<?= $y + 43 ?>" class="graph-node-sublabel" text-anchor="middle">score <?= e(number_format((float) ($node['score'] ?? 0), 4)) ?></text>
                </g>
            <?php endforeach; ?>
        </svg>
        <?php if (!empty($structureNodes)): ?>
            <div class="molecule-strip">
                <?php foreach ($structureNodes as $index => $node): ?>
                    <?php $canvasId = 'molecule-canvas-' . $index; ?>
                    <div class="molecule-card">
                        <div class="molecule-card-top">
                            <span class="graph-chip <?= !empty($node['is_source']) ? 'graph-chip-source' : 'graph-chip-target' ?>">
                                <?= !empty($node['is_source']) ? 'Source drug' : 'Predicted drug' ?>
                            </span>
                            <span class="molecule-card-meta"><?= e((string) ($node['actual_id'] ?? '')) ?></span>
                        </div>
                        <div class="molecule-card-title"><?= e((string) ($node['label'] ?? 'Drug')) ?></div>
                        <canvas id="<?= e($canvasId) ?>" class="molecule-canvas" width="180" height="118" data-smiles="<?= e((string) ($node['smiles'] ?? '')) ?>"></canvas>
                        <div class="molecule-card-meta" style="margin-top:8px;">
                            <?= !empty($node['score']) ? 'Score: ' . e(number_format((float) $node['score'], 4)) : 'Input molecule' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <!-- Hover tooltip -->
        <div id="graph-tooltip" class="graph-tooltip">
            <canvas id="smiles-canvas" width="200" height="180" style="display:none;"></canvas>
            <div id="tooltip-body" class="tooltip-body"></div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submitted)) {
        $error = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    } elseif (!$apiHealthy) {
        $error = 'Python API đang ngoại tuyến. Vui lòng khởi động FastAPI ở cổng 8000.';
    } elseif ($drugInput === '' && $diseaseInput === '') {
        $error = 'Vui lòng nhập tên thuốc hoặc tên bệnh.';
    } else {
        try {
            $resultData = PredictionService::callPythonApi($queryType, $inputText, $topK, $dataset);
            // Fallback: primary entity not found but secondary field is filled
            if (empty($resultData['matched_input']) && $fallbackType !== null && $fallbackText !== '') {
                $resultData = PredictionService::callPythonApi($fallbackType, $fallbackText, $topK, $dataset);
                $queryType  = $fallbackType;
                $inputText  = $fallbackText;
            }
            if (!empty($resultData['matched_input'])) {
                PredictionService::saveHistory((int) $user['id'], $queryType, $inputText, $topK, $resultData);
                flash('success', 'Dự đoán thành công.');
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
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
        .graph-node-sublabel { fill: #94a3b8; font-size: 9.5px; }
        .graph-edge { pointer-events: none; }
        .graph-panel-bg { fill: rgba(15,23,42,.55); stroke: rgba(148,163,184,.12); stroke-width: 1; }
        .molecule-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-top: 14px; }
        .molecule-card { background: rgba(8,15,29,.76); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 12px; min-height: 190px; }
        .molecule-card-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .molecule-card-title { color: #f8fafc; font-size: .84rem; font-weight: 600; line-height: 1.3; }
        .molecule-card-meta { color: #94a3b8; font-size: .72rem; }
        .molecule-card canvas { width: 100%; height: 118px; display: block; background: radial-gradient(circle at center, rgba(59,130,246,.08), rgba(15,23,42,0)); border-radius: 10px; }
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
        /* Predict form */
        .query-toggle { display: flex; gap: 0; border: 1px solid rgba(255,255,255,.15); border-radius: 8px; overflow: hidden; margin-bottom: 1rem; }
        .query-toggle label { flex: 1; text-align: center; padding: .55rem .75rem; font-size: .82rem; cursor: pointer; color: #94a3b8; transition: background .15s, color .15s; }
        .query-toggle input[type=radio]:checked + label { background: rgba(96,165,250,.2); color: #60a5fa; font-weight: 600; }
        .query-toggle input[type=radio] { display: none; }
        .matched-card { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.3); border-radius: 10px; margin-bottom: 1.5rem; }
        .matched-icon { font-size: 2rem; flex-shrink: 0; }
        .matched-details h4 { margin: 0 0 .2rem; font-size: 1rem; }
        .matched-details .smiles-preview { font-size: .72rem; color: #94a3b8; margin-top: .35rem; word-break: break-all; }
        /* Searchable picker */
        .ts-wrapper.single .ts-control,
        .ts-dropdown {
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.12);
            color: var(--text-main, #e2e8f0);
            border-radius: 10px;
            box-shadow: none;
        }
        .ts-wrapper.single .ts-control { min-height: 44px; padding: 8px 14px; }
        .ts-wrapper.single.focus .ts-control { border-color: rgba(96,165,250,.7); box-shadow: 0 0 0 3px rgba(96,165,250,.12); }
        .ts-control input { color: #e2e8f0 !important; }
        .ts-control .item { color: #f8fafc; }
        .ts-dropdown .option,
        .ts-dropdown .optgroup-header { color: #e2e8f0; }
        .ts-dropdown .active { background: rgba(96,165,250,.18); color: #f8fafc; }
        .ts-dropdown .create { color: #94a3b8; }
        .ts-dropdown [data-selectable] .highlight { background: rgba(250,204,21,.2); color: #fef08a; }
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
                <a class="side-nav-item" href="<?= $resultData ? '#result-section' : '#predict-form-panel' ?>">
                    <span class="material-symbols-outlined"><?= $resultData ? 'insights' : 'menu_book' ?></span>
                    <span><?= $resultData ? 'Kết quả' : 'Hướng dẫn' ?></span>
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
                        <p class="muted">Nhập tên một thuốc hoặc bệnh, mô hình HGT sẽ dự đoán các bệnh (hoặc thuốc) liên kết cao nhất, kèm đồ thị phân tử 2D &amp; 3D.</p>
                        <div class="hero-actions">
                            <a class="btn" href="#predict-form-panel">Bắt đầu dự đoán</a>
                            <?php if ($resultData): ?>
                                <a class="btn btn-ghost" href="#result-section">Xem kết quả hiện tại</a>
                            <?php endif; ?>
                        </div>
                        <div class="hero-bullets">
                            <div class="hero-bullet">Chọn hướng dự đoán: Thuốc → Bệnh hoặc Bệnh → Thuốc.</div>
                            <div class="hero-bullet">Đồ thị 2D hiển thị vòng benzene (thuốc), protein trung gian và bệnh.</div>
                            <div class="hero-bullet">Hover vào node để xem cấu trúc SMILES hoặc độ dài chuỗi protein.</div>
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
                    <p class="muted">Nhập tên thuốc hoặc bệnh để mô hình dự đoán Top-K liên kết tiềm năng.</p>
                </div>
                <form method="POST" action="" id="predict-form" onsubmit="document.getElementById('loader').style.display='flex'">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <p class="muted" style="font-size:.8rem;margin:0 0 .85rem;">
                        Điền <strong>một</strong> trong hai ô bên dưới — hướng dự đoán sẽ được tự động xác định.
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:.85rem;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="drug_input">⬡ Tên thuốc</label>
                            <div style="position:relative;">
                                <select class="form-control" id="drug_input" name="drug_input">
                                    <option value="">-- Chọn thuốc từ <?= e($dataset) ?> --</option>
                                    <?php foreach ($drugChoices as $option): ?>
                                        <?php $selectedDrug = $drugInput !== '' && (strcasecmp($drugInput, (string) $option['id']) === 0 || strcasecmp($drugInput, (string) $option['name']) === 0); ?>
                                        <option value="<?= e((string) $option['id']) ?>" <?= $selectedDrug ? 'selected' : '' ?>><?= e((string) $option['name']) ?> (<?= e((string) $option['id']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="disease_input">✚ Tên bệnh</label>
                            <div style="position:relative;">
                                <select class="form-control" id="disease_input" name="disease_input">
                                    <option value="">-- Chọn bệnh từ <?= e($dataset) ?> --</option>
                                    <?php foreach ($diseaseChoices as $option): ?>
                                        <?php $selectedDisease = $diseaseInput !== '' && (strcasecmp($diseaseInput, (string) $option['id']) === 0 || strcasecmp($diseaseInput, (string) $option['name']) === 0); ?>
                                        <option value="<?= e((string) $option['id']) ?>" <?= $selectedDisease ? 'selected' : '' ?>><?= e((string) $option['name']) ?> (<?= e((string) $option['id']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:.75rem;align-items:flex-end;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="top_k">Top-K</label>
                            <select class="form-control" name="top_k" id="top_k" style="width:90px;">
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
            <?php if ($resultData): ?>
            <div id="result-section" style="margin-top:2rem;">

                <?php if ($noteText): ?>
                    <div class="alert" style="background:rgba(96,165,250,.1);border-color:rgba(96,165,250,.3);margin-bottom:1rem;">
                        <?= e($noteText) ?>
                    </div>
                <?php endif; ?>

                <?php if ($matchedInput): ?>
                <div class="matched-card">
                    <div class="matched-icon"><?= ($matchedInput['type'] ?? '') === 'drug' ? '⬡' : '✚' ?></div>
                    <div class="matched-details">
                        <h4><?= e($matchedInput['name'] ?? $matchedInput['id'] ?? '') ?></h4>
                        <div class="muted" style="font-size:.82rem;">
                            ID: <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:4px;"><?= e($matchedInput['id'] ?? '') ?></code>
                            &nbsp;·&nbsp; Loại: <?= ($matchedInput['type'] ?? '') === 'drug' ? '<strong>Thuốc</strong>' : '<strong>Bệnh</strong>' ?>
                        </div>
                        <?php if (!empty($matchedInput['smiles'])): ?>
                        <div class="smiles-preview">SMILES: <?= e($matchedInput['smiles']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Top-K Table -->
                <div class="glass-card" style="margin-bottom:2rem;">
                    <div class="card-header">
                        <h3 class="card-title">Top-<?= count($results) ?> kết quả dự đoán</h3>
                    </div>
                    <div class="table-wrap">
                        <table class="table result-table">
                            <thead>
                                <tr>
                                    <th style="width:48px">#</th>
                                    <th>Tên</th>
                                    <th>ID</th>
                                    <th>Điểm dự đoán</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($results as $i => $row): ?>
                                <tr>
                                    <td><span class="rank-badge"><?= $i + 1 ?></span></td>
                                    <td><strong><?= e($row['name'] ?? $row['id'] ?? '') ?></strong></td>
                                    <td><code style="font-size:.8rem;color:#93c5fd;"><?= e($row['id'] ?? '') ?></code></td>
                                    <td>
                                        <div class="score-bar-wrap">
                                            <span style="min-width:52px;font-weight:600;color:#22c55e;"><?= format_score($row['score'] ?? 0) ?></span>
                                            <div class="score-bar" style="width:<?= (int) (((float)($row['score'] ?? 0)) * 120) ?>px"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2D Graph -->
                <?php if (!empty($graphData['nodes'])): ?>
                <div class="glass-card">
                    <div class="card-header">
                        <h3 class="card-title"><span class="material-symbols-outlined" style="vertical-align:-5px;font-size:18px;">hub</span> Đồ thị liên kết phân tử (2D)</h3>
                        <p class="muted" style="font-size:.82rem;">Hover vào node để xem cấu trúc hóa học (SMILES) hoặc thông tin protein.</p>
                    </div>
                    <?= render_prediction_graph($graphData) ?>
                </div>
                <?php endif; ?>

            </div>
            <?php else: ?>
            <!-- Quick-start guide -->
            <div class="glass-card" id="quick-start" style="margin-top:2rem;">
                <div class="card-header"><h3 class="card-title">Cách sử dụng</h3></div>
                <ol style="padding-left:1.5rem;line-height:2;color:#94a3b8;">
                    <li>Chọn hướng dự đoán: <strong>Thuốc → Bệnh</strong> hoặc <strong>Bệnh → Thuốc</strong>.</li>
                    <li>Nhập tên thuốc (VD: <em>Aspirin</em>) hoặc tên bệnh (VD: <em>Asthma</em>).</li>
                    <li>Chọn số kết quả Top-K và bộ dữ liệu.</li>
                    <li>Nhấn <strong>Chạy dự đoán</strong> — kết quả bảng và đồ thị phân tử 2D sẽ xuất hiện bên dưới.</li>
                    <li>Nhấn <strong>Xem 3D</strong> trong toolbar đồ thị để mở chế độ xem không gian 3 chiều.</li>
                </ol>
            </div>
            <?php endif; ?>

        </div><!-- end main-shell -->
    </div><!-- end app-shell -->
</div><!-- end container -->

<!-- SmilesDrawer -->
<script src="https://cdn.jsdelivr.net/npm/smiles-drawer@1.0.10/dist/smiles-drawer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<?php if ($resultData && !empty($graphData['nodes'])): ?>
<script src="https://unpkg.com/three@0.161.0/build/three.min.js"></script>
<script src="https://unpkg.com/three-spritetext@1.9.0/dist/three-spritetext.min.js"></script>
<script src="https://unpkg.com/3d-force-graph"></script>
<?php endif; ?>

<script>
const dsEl = document.getElementById('dataset');
if (dsEl) {
    dsEl.addEventListener('change', () => {
        window.location.href = `?dataset=${encodeURIComponent(dsEl.value)}`;
    });
}

(function initSearchablePickers() {
    if (!window.TomSelect) return;

    const commonOptions = {
        create: false,
        persist: false,
        allowEmptyOption: true,
        maxOptions: 5000,
        openOnFocus: true,
        closeAfterSelect: true,
        plugins: ['clear_button'],
        searchField: ['text', 'value'],
        sortField: [
            { field: '$score', direction: 'desc' },
            { field: 'text', direction: 'asc' },
        ],
    };

    ['drug_input', 'disease_input'].forEach((selectorId) => {
        const element = document.getElementById(selectorId);
        if (!element || element.tomselect) return;
        new TomSelect(element, commonOptions);
    });
})();

function createSmilesDrawer(width, height) {
    if (!window.SmilesDrawer) return null;
    return new SmilesDrawer.Drawer({
        width,
        height,
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
}

(function renderStaticMolecules() {
    if (!window.SmilesDrawer) return;

    document.querySelectorAll('.molecule-canvas[data-smiles]').forEach((canvas) => {
        const smiles = canvas.dataset.smiles || '';
        if (!smiles) return;

        const drawer = createSmilesDrawer(canvas.width || 180, canvas.height || 118);
        if (!drawer) return;

        SmilesDrawer.parse(smiles, (tree) => {
            drawer.draw(tree, canvas.id, 'dark', false);
        }, () => {});
    });
})();

(function initGraphTooltip() {
    const svg     = document.querySelector('.prediction-network');
    const tooltip = document.getElementById('graph-tooltip');
    const canvas  = document.getElementById('smiles-canvas');
    const body    = document.getElementById('tooltip-body');

    if (!svg || !tooltip) return;

    const sd = createSmilesDrawer(200, 180);

    svg.addEventListener('mouseover', function(e) {
        const node = e.target.closest('.graph-node');
        if (!node) return;

        const type   = node.dataset.type || '';
        const label  = node.dataset.label || '';
        const id     = node.dataset.id || '';
        const smiles = node.dataset.smiles || '';
        const seqLen = parseInt(node.dataset.seqLen || '0', 10);

        const rect = node.querySelector('circle, polygon, rect');
        if (!rect) return;
        const br = rect.getBoundingClientRect();
        tooltip.style.left = (br.right + 14) + 'px';
        tooltip.style.top  = Math.max(8, br.top + window.scrollY - 20) + 'px';
        tooltip.style.display = 'block';

        if ((type === 'drug') && smiles && sd) {
            canvas.style.display = 'block';
            SmilesDrawer.parse(smiles, function(tree) {
                sd.draw(tree, 'smiles-canvas', 'dark', false);
            }, function() { canvas.style.display = 'none'; });
            const smilesShort = smiles.length > 40 ? smiles.slice(0, 40) + '…' : smiles;
            body.innerHTML = `<strong>${label}</strong><code>${id}</code><br><span style="color:#94a3b8;font-size:10px;">SMILES: ${smilesShort}</span>`;
        } else if (type === 'protein') {
            canvas.style.display = 'none';
            body.innerHTML = `<strong>${label}</strong><code>${id}</code><br>Độ dài chuỗi: <strong style="color:#fcd34d;">${seqLen || '?'} aa</strong>`;
        } else {
            canvas.style.display = 'none';
            body.innerHTML = `<strong>${label}</strong><code>${id}</code>`;
        }
    });

    svg.addEventListener('mouseleave', function() {
        tooltip.style.display = 'none';
    });
})();

function open3DModal() {
    const modal = document.getElementById('modal-3d');
    if (!modal) return;
    modal.style.display = 'flex';
    if (!window._3dReady) {
        init3D();
        window._3dReady = true;
    } else if (window._graph3d) {
        setTimeout(() => {
            if (window._graph3d && window._graph3d.resumeAnimation) {
                window._graph3d.resumeAnimation();
            }
            if (window._graph3d && window._graph3d.zoomToFit) {
                window._graph3d.zoomToFit(600, 80);
            }
        }, 120);
    }
}
function close3DModal() {
    const modal = document.getElementById('modal-3d');
    if (modal) modal.style.display = 'none';
    if (window._graph3d && window._graph3d.pauseAnimation) {
        window._graph3d.pauseAnimation();
    }
}

<?php if ($resultData && !empty($graphData['nodes'])): ?>
function init3D() {
    if (!window.ForceGraph3D || !window.THREE) return;
    const wrapper = document.getElementById('graph-2d-wrapper');
    if (!wrapper) return;
    const graphData = JSON.parse(wrapper.dataset.graph || '{}');
    const container = document.getElementById('canvas-3d');
    if (!container) return;

    const nodes = (graphData.nodes || []).map((node, index) => {
        const cloned = { ...node };
        if (cloned.is_source) {
            cloned.fx = -140;
            cloned.fy = 0;
            cloned.fz = 0;
        } else if (cloned.type === 'protein') {
            cloned.x = -5;
            cloned.y = (index - 3) * 22;
            cloned.z = (index % 2 === 0 ? 1 : -1) * 18;
        } else {
            cloned.x = 150;
            cloned.y = (index - 3) * 24;
            cloned.z = (index % 2 === 0 ? 1 : -1) * 22;
        }
        return cloned;
    });

    const graphPayload = { nodes, links: graphData.links || [] };
    container.innerHTML = '';

    const colorMap = { drug: 0x2563eb, protein: 0xf59e0b, disease: 0xdc2626 };
    const Graph = ForceGraph3D()(container)
        .backgroundColor('#050b16')
        .width(container.clientWidth || window.innerWidth)
        .height(container.clientHeight || (window.innerHeight - 60))
        .graphData(graphPayload)
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
                    opacity: node.kind === 'prediction' ? 0.9 : 0.98,
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

            const label = new SpriteText(`${node.label || node.actual_id || node.id}`);
            label.color = '#f8fafc';
            label.textHeight = node.type === 'protein' ? 4 : 5;
            label.backgroundColor = 'rgba(5, 11, 22, 0.65)';
            label.padding = 2;
            label.borderRadius = 4;
            label.position.set(0, size + 8, 0);
            group.add(label);
            return group;
        })
        .onNodeHover((node) => {
            container.style.cursor = node ? 'pointer' : 'default';
        })
        .onEngineStop(() => {
            if (Graph.zoomToFit) {
                Graph.zoomToFit(800, 80);
            }
            if (Graph.pauseAnimation) {
                Graph.pauseAnimation();
            }
        });

    const scene = Graph.scene();
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

    window._graph3d = Graph;

    window.addEventListener('resize', () => {
        if (!window._graph3d) return;
        window._graph3d.width(container.clientWidth || window.innerWidth);
        window._graph3d.height(container.clientHeight || (window.innerHeight - 60));
    });
}
<?php else: ?>
function init3D() {}
<?php endif; ?>
</script>
</body>
</html>
