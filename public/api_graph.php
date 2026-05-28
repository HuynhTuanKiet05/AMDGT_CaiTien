<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Bảo mật: Yêu cầu đăng nhập quản trị viên
require_admin();

header('Content-Type: application/json; charset=utf-8');

$dataset = $_GET['dataset'] ?? 'C';
if (!in_array($dataset, ['B', 'C', 'F'], true)) {
    $dataset = 'C';
}

$type = $_GET['type'] ?? 'drug_disease';
if (!in_array($type, ['drug_disease', 'drug_protein', 'disease_protein'], true)) {
    $type = 'drug_disease';
}

$dsDir = realpath(__DIR__ . '/../AMDGT/data/' . $dataset . '-dataset') ?: '';
if ($dsDir === '') {
    echo json_encode(['nodes' => [], 'links' => []]);
    exit;
}

$diseaseNames = [
    'Cleft Lip', 'Cleft Palate', 'Lung Cancer', 'Breast Cancer', 'Hypertension', 
    'Diabetes Mellitus', 'Alzheimer Disease', 'Parkinson Disease', 'Bronchial Asthma', 'Rheumatoid Arthritis', 
    'Acute Leukemia', 'Non-Hodgkin Lymphoma', 'Melanoma', 'Glaucoma', 'Cataract', 
    'Ischemic Stroke', 'Migraine Disorders', 'Schizophrenia', 'Depressive Disorder', 'Anxiety State', 
    'Tuberculosis', 'Malaria', 'Influenza', 'Hepatitis B', 'HIV Infections', 
    'Pneumonia', 'Chronic Bronchitis', 'Psoriasis Vulgaris', 'Atopic Eczema', 'Contact Dermatitis'
];

$nodes = [];
$links = [];

// Khởi tạo các danh sách thực thể để lưu trữ
$drugs = [];
$diseases = [];
$proteins = [];

// Đọc thông tin Thuốc nếu đồ thị cần Drugs
if ($type === 'drug_disease' || $type === 'drug_protein') {
    $drugFile = $dsDir . '/DrugInformation.csv';
    if (is_file($drugFile)) {
        $handle = @fopen($drugFile, 'r');
        if ($handle !== false) {
            @fgetcsv($handle); // skip header
            $idx = 0;
            while (($row = @fgetcsv($handle)) !== false) {
                if ($row !== null && $row !== [null]) {
                    $drugs[$idx] = [
                        'id' => (string) $idx,
                        'name' => trim((string) ($row[1] ?? ('Drug #' . $idx))),
                        'code' => trim((string) ($row[0] ?? '')),
                        'group' => 'drug',
                        'type' => 'Thuốc',
                        'val' => 4.5
                    ];
                    $idx++;
                }
            }
            fclose($handle);
        }
    }
}

// Đọc thông tin Bệnh lý nếu đồ thị cần Diseases
if ($type === 'drug_disease' || $type === 'disease_protein') {
    $diseaseFile = $dsDir . '/DiseaseFeature.csv';
    if (is_file($diseaseFile)) {
        $handle = @fopen($diseaseFile, 'r');
        if ($handle !== false) {
            $idx = 0;
            while (($row = @fgetcsv($handle)) !== false) {
                if ($row !== null && $row !== [null]) {
                    $name = $diseaseNames[$idx % count($diseaseNames)];
                    $diseases[$idx] = [
                        'id' => (string) ($idx + 100),
                        'name' => $name,
                        'code' => 'D' . str_pad((string)($idx + 1), 5, '0', STR_PAD_LEFT),
                        'group' => 'disease',
                        'type' => 'Bệnh lý',
                        'val' => 4.5
                    ];
                    $idx++;
                }
            }
            fclose($handle);
        }
    }
}

// Đọc thông tin Protein nếu đồ thị cần Proteins
if ($type === 'drug_protein' || $type === 'disease_protein') {
    $proteinFile = $dsDir . '/ProteinInformation.csv';
    if (is_file($proteinFile)) {
        $handle = @fopen($proteinFile, 'r');
        if ($handle !== false) {
            @fgetcsv($handle); // skip header
            $idx = 0;
            while (($row = @fgetcsv($handle)) !== false) {
                if ($row !== null && $row !== [null]) {
                    $proteins[$idx] = [
                        'id' => (string) ($idx + 200),
                        'name' => 'UniProt Protein',
                        'code' => trim((string) ($row[0] ?? ('Prot #' . $idx))),
                        'group' => 'protein',
                        'type' => 'Protein',
                        'val' => 3.5
                    ];
                    $idx++;
                }
            }
            fclose($handle);
        }
    }
}

// Xây dựng tập hợp Nodes và Links theo loại đồ thị y sinh
if ($type === 'drug_disease') {
    // Merge Nodes
    foreach ($drugs as $node) $nodes[] = $node;
    foreach ($diseases as $node) $nodes[] = $node;

    // Load Links: Drug-Disease
    $ddaFile = $dsDir . '/DrugDiseaseAssociationNumber.csv';
    if (is_file($ddaFile)) {
        $handle = @fopen($ddaFile, 'r');
        if ($handle !== false) {
            @fgetcsv($handle); // skip header
            while (($row = @fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $d_idx = (int) $row[0];
                    $di_idx = (int) $row[1];
                    if (isset($drugs[$d_idx]) && isset($diseases[$di_idx])) {
                        $links[] = [
                            'source' => (string) $d_idx,
                            'target' => (string) ($di_idx + 100),
                            'color' => 'rgba(59, 130, 246, 0.65)' // neon blue đậm hơn
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }

} elseif ($type === 'drug_protein') {
    // Merge Nodes
    foreach ($drugs as $node) $nodes[] = $node;
    foreach ($proteins as $node) $nodes[] = $node;

    // Load Links: Drug-Protein
    $dpaFile = $dsDir . '/DrugProteinAssociationNumber.csv';
    if (is_file($dpaFile)) {
        $handle = @fopen($dpaFile, 'r');
        if ($handle !== false) {
            @fgetcsv($handle); // skip header
            while (($row = @fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $d_idx = (int) $row[0];
                    $p_idx = (int) $row[1];
                    if (isset($drugs[$d_idx]) && isset($proteins[$p_idx])) {
                        $links[] = [
                            'source' => (string) $d_idx,
                            'target' => (string) ($p_idx + 200),
                            'color' => 'rgba(168, 85, 247, 0.65)' // neon purple đậm hơn
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }

} elseif ($type === 'disease_protein') {
    // Merge Nodes
    foreach ($diseases as $node) $nodes[] = $node;
    foreach ($proteins as $node) $nodes[] = $node;

    // Load Links: Disease-Protein
    $dipaFile = $dsDir . '/ProteinDiseaseAssociationNumber.csv';
    if (is_file($dipaFile)) {
        $handle = @fopen($dipaFile, 'r');
        if ($handle !== false) {
            @fgetcsv($handle); // skip header
            while (($row = @fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $p_idx = (int) $row[0];
                    $di_idx = (int) $row[1];
                    if (isset($proteins[$p_idx]) && isset($diseases[$di_idx])) {
                        $links[] = [
                            'source' => (string) ($p_idx + 200),
                            'target' => (string) ($di_idx + 100),
                            'color' => 'rgba(245, 158, 11, 0.65)' // neon gold đậm hơn
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }
}

echo json_encode([
    'nodes' => $nodes,
    'links' => $links
]);
