<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

function benchmark_metric_value(array $row, string $column): ?float
{
    $value = trim((string) ($row[$column] ?? ''));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function format_benchmark_percent(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format($value * 100, 2);
}

function load_model_benchmark_datasets(): array
{
    $benchmarkDir = realpath(__DIR__ . '/../thong_so_chay_test_goc_va_cai_tien');
    if ($benchmarkDir === false) {
        return [];
    }

    $configs = [
        'B-dataset' => [
            'label' => 'B Dataset',
            'baseline_file' => '10_fold_results_B_Goc.csv',
            'improved_file' => '10_fold_results_vanilla_hgt_mva_mul_mlp_vector_B_caiTien.csv',
        ],
        'C-dataset' => [
            'label' => 'C Dataset',
            'baseline_file' => '10_fold_results_C_Goc.csv',
            'improved_file' => '10_fold_results_vanilla_hgt_mva_mul_mlp_vector_C_CaiTien.csv',
        ],
        'F-dataset' => [
            'label' => 'F Dataset',
            'baseline_file' => '10_fold_results_F_Goc.csv',
            'improved_file' => '10_fold_results_vanilla_hgt_mva_mul_mlp_vector_F_caiTien.csv',
        ],
    ];

    $datasets = [];
    foreach ($configs as $datasetKey => $config) {
        $baselineRows = load_csv_assoc($benchmarkDir . DIRECTORY_SEPARATOR . $config['baseline_file']);
        $improvedRows = load_csv_assoc($benchmarkDir . DIRECTORY_SEPARATOR . $config['improved_file']);
        $orderKeys = [];
        $orderLabels = [];
        $indexRows = static function (array $rows) use (&$orderKeys, &$orderLabels): array {
            $map = [];
            foreach ($rows as $row) {
                $label = trim((string) ($row['Fold'] ?? ''));
                if ($label === '') {
                    continue;
                }

                $normalized = strtolower($label);
                $map[$normalized] = $row;
                if (!isset($orderLabels[$normalized])) {
                    $orderLabels[$normalized] = $label;
                    $orderKeys[] = $normalized;
                }
            }

            return $map;
        };

        $baselineMap = $indexRows($baselineRows);
        $improvedMap = $indexRows($improvedRows);
        $rows = [];
        foreach ($orderKeys as $normalizedKey) {
            $label = $orderLabels[$normalizedKey] ?? $normalizedKey;
            $baselineRow = $baselineMap[$normalizedKey] ?? [];
            $improvedRow = $improvedMap[$normalizedKey] ?? [];
            $rows[] = [
                'fold' => $label,
                'is_summary' => in_array($normalizedKey, ['mean', 'std'], true),
                'baseline' => [
                    'auc' => benchmark_metric_value($baselineRow, 'AUC'),
                    'aupr' => benchmark_metric_value($baselineRow, 'AUPR'),
                    'f1' => benchmark_metric_value($baselineRow, 'F1-score'),
                ],
                'improved' => [
                    'auc' => benchmark_metric_value($improvedRow, 'AUC'),
                    'aupr' => benchmark_metric_value($improvedRow, 'AUPR'),
                    'f1' => benchmark_metric_value($improvedRow, 'F1-score'),
                ],
            ];
        }

        $datasets[$datasetKey] = [
            'label' => $config['label'],
            'rows' => $rows,
            'source_files' => [
                'baseline' => $config['baseline_file'],
                'improved' => $config['improved_file'],
            ],
        ];
    }

    return $datasets;
}

$benchmarkDatasets = load_model_benchmark_datasets();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>So sánh Model · AMNTDDA AI</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
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
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .benchmark-panel { display: none; }
        .benchmark-panel.is-active { display: block; }
    </style>
</head>
<body class="bg-[#0a0a0f] text-white">

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
            <a href="index.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="layout-dashboard" class="relative w-4 h-4"></i>
              <span class="relative">Tổng quan</span>
            </a>
            
            <a href="compare_models.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
              <span class="absolute inset-0 bg-gradient-to-r from-blue-500/25 via-purple-500/15 to-transparent"></span>
              <span class="absolute inset-y-1 right-0 w-[2px] rounded-l-full bg-gradient-to-b from-blue-400 to-purple-500 shadow-[0_0_12px_2px_rgba(96,165,250,0.7)]"></span>
              <span class="absolute inset-0 border border-white/10 rounded-xl"></span>
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
            <?php
            $activeDataset = (string) array_key_first($benchmarkDatasets);
            ?>

            <!-- Header section matching ModelCompare.tsx -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 lg:p-8 overflow-hidden">
                <div class="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-blue-500/15 blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-40 right-1/4 w-96 h-96 rounded-full bg-purple-500/15 blur-3xl pointer-events-none"></div>

                <div class="relative flex items-start justify-between gap-6 flex-wrap">
                  <div class="flex items-start gap-4 max-w-2xl">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400/30 to-orange-500/30 border border-amber-300/30 grid place-items-center text-amber-300 shadow-[0_0_24px_-4px_rgba(251,191,36,0.5)] shrink-0">
                      <i data-lucide="trophy" class="w-[22px] h-[22px]"></i>
                    </div>
                    <div class="flex flex-col gap-2">
                      <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
                        <i data-lucide="sparkles" class="text-blue-300 w-3 h-3"></i>
                        <span class="text-white/65" style="font-family: 'Inter', sans-serif; font-size: 10.5px; font-weight: 500; letter-spacing: 0.1em;">
                          BENCHMARK · 10-FOLD CV
                        </span>
                      </div>
                      <h1 class="text-white" style="font-family: 'Space Grotesk', sans-serif; font-size: 32px; font-weight: 700; letter-spacing: -0.02em; lineHeight: 1.1;">
                        So sánh kết quả test model
                      </h1>
                      <p class="text-white/60" style="font-family: 'Inter', sans-serif; font-size: 13.5px; line-height: 1.6;">
                        Đánh giá mô hình HGT cải tiến so với baseline trên 10-fold cross-validation. Các chỉ số AUC, AUPR và F1-score
                        được tính trung bình qua từng fold để đảm bảo độ tin cậy thống kê.
                      </p>
                    </div>
                  </div>

                  <div class="flex flex-col gap-2 min-w-[200px]" id="delta-container">
                    <!-- Deltas will be loaded dynamically by JS based on selected tab -->
                  </div>
                </div>

                <!-- Dataset Select Tabs -->
                <div class="relative mt-7 flex items-center gap-2 flex-wrap" role="tablist" aria-label="Chọn dataset benchmark">
                    <?php
                    foreach ($benchmarkDatasets as $datasetKey => $datasetInfo):
                        $isActive = $datasetKey === $activeDataset;
                        $panelId = 'benchmark-panel-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $datasetKey));
                        
                        // Extract mean statistics to render in tabs metadata dynamically
                        $meanBaseline = ['auc' => 0, 'aupr' => 0, 'f1' => 0];
                        $meanImproved = ['auc' => 0, 'aupr' => 0, 'f1' => 0];
                        foreach ($datasetInfo['rows'] as $r) {
                            if (!empty($r['is_summary']) && strtolower((string)$r['fold']) === 'mean') {
                                $meanBaseline = $r['baseline'] ?? $meanBaseline;
                                $meanImproved = $r['improved'] ?? $meanImproved;
                                break;
                            }
                        }
                        $dAuc = ($meanImproved['auc'] - $meanBaseline['auc']) * 100;
                        $dAupr = ($meanImproved['aupr'] - $meanBaseline['aupr']) * 100;
                        $dF1 = ($meanImproved['f1'] - $meanBaseline['f1']) * 100;
                    ?>
                        <button
                            type="button"
                            class="relative inline-flex items-center gap-2 px-4 py-2.5 rounded-full transition-all benchmark-tab<?= $isActive ? ' is-active' : '' ?>"
                            data-benchmark-tab="<?= e((string) $datasetKey) ?>"
                            data-delta-auc="<?= number_format($dAuc, 2) ?>"
                            data-delta-aupr="<?= number_format($dAupr, 2) ?>"
                            data-delta-f1="<?= number_format($dF1, 2) ?>"
                            aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                            aria-controls="<?= e($panelId) ?>"
                            style="font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 500;"
                        >
                            <span class="w-1.5 h-1.5 rounded-full tab-dot bg-white/30"></span>
                            <span><?= e((string) ($datasetInfo['label'] ?? $datasetKey)) ?> Dataset</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Table section dynamic panels -->
            <?php foreach ($benchmarkDatasets as $datasetKey => $datasetInfo): ?>
                <?php
                $rows = $datasetInfo['rows'] ?? [];
                $isActive = $datasetKey === $activeDataset;
                $panelId = 'benchmark-panel-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $datasetKey));
                ?>
                <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden benchmark-panel<?= $isActive ? ' is-active' : '' ?>" id="<?= e($panelId) ?>" data-benchmark-panel="<?= e((string) $datasetKey) ?>">
                    
                    <div class="flex items-center justify-between mb-4">
                      <div class="flex items-center gap-2">
                        <i data-lucide="trending-up" class="text-blue-300 w-4 h-4"></i>
                        <span class="text-white font-semibold text-sm" style="font-family: 'Space Grotesk', sans-serif;">
                          Bảng kết quả · <?= e((string) ($datasetInfo['label'] ?? $datasetKey)) ?>
                        </span>
                      </div>
                      <span class="text-white/40 font-mono text-[10.5px] tracking-wider">
                        10 FOLDS · 6 METRICS
                      </span>
                    </div>

                    <?php if ($rows === []): ?>
                        <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300 text-sm font-medium">Không đọc được file số liệu cho <?= e((string) ($datasetInfo['label'] ?? $datasetKey)) ?>.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto rounded-2xl border border-white/[0.06] bg-black/30">
                            <table class="w-full border-collapse font-mono text-[12.5px]">
                                <thead>
                                    <tr class="bg-white/[0.04] border-b border-white/[0.08]">
                                        <th rowspan="2" class="text-left px-5 py-3 text-white/70 font-semibold text-[11.5px] tracking-wider" style="font-family: 'Inter', sans-serif;">Fold</th>
                                        <th colspan="3" class="text-center px-4 py-2 border-l border-white/[0.06] font-semibold text-[11px] tracking-widest text-white/50" style="font-family: 'Inter', sans-serif;">
                                            <span class="inline-flex items-center gap-1.5">
                                              <span class="w-1.5 h-1.5 rounded-full bg-white/40"></span>
                                              BASELINE (Mô hình gốc)
                                            </span>
                                        </th>
                                        <th colspan="3" class="text-center px-4 py-2 border-l border-white/[0.06] font-semibold text-[11px] tracking-widest text-emerald-300" style="font-family: 'Inter', sans-serif;">
                                            <span class="inline-flex items-center gap-1.5">
                                              <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(74,222,128,0.8)]"></span>
                                              IMPROVED (HGT cải tiến)
                                            </span>
                                        </th>
                                    </tr>
                                    <tr class="bg-white/[0.02] border-b border-white/[0.08] text-[10.5px] font-medium tracking-wider" style="font-family: 'Inter', sans-serif;">
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-white/45">AUC</th>
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-white/45">AUPR</th>
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-white/45">F1</th>
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-emerald-300/80">AUC</th>
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-emerald-300/80">AUPR</th>
                                        <th class="text-right px-4 py-2 border-l border-white/[0.04] text-emerald-300/80">F1</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rowIndex = 0;
                                    foreach ($rows as $row): 
                                        $isSummary = !empty($row['is_summary']);
                                        $foldLabel = (string) ($row['fold'] ?? '');
                                        
                                        if ($isSummary):
                                            $dim = strtolower($foldLabel) === 'std';
                                            $prefixSymbol = $dim ? '±' : '';
                                    ?>
                                        <tr class="border-t border-amber-300/20" style="background: linear-gradient(90deg, rgba(251,191,36,0.06), rgba(251,146,60,0.04))">
                                            <td class="px-5 py-3 <?= $dim ? 'text-amber-200/60' : 'text-amber-200' ?> font-bold tracking-wider" style="font-family: 'Inter', sans-serif; font-size: 12.5px;">
                                                <span class="inline-flex items-center gap-2">
                                                  <span class="w-1 h-1 rounded-full bg-amber-300 shadow-[0_0_6px_rgba(251,191,36,0.9)]"></span>
                                                  <?= strtoupper($foldLabel) ?>
                                                </span>
                                            </td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-white/55' : 'text-white font-semibold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['baseline']['auc'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-white/55' : 'text-white font-semibold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['baseline']['aupr'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-white/55' : 'text-white font-semibold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['baseline']['f1'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-emerald-300/70' : 'text-emerald-300 font-bold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['improved']['auc'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-emerald-300/70' : 'text-emerald-300 font-bold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['improved']['aupr'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-3 tabular-nums border-l border-white/[0.04] <?= $dim ? 'text-emerald-300/70' : 'text-emerald-300 font-bold' ?>"><?= $prefixSymbol ?><?= e(format_benchmark_percent($row['improved']['f1'] ?? null)) ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition-colors">
                                            <td class="px-5 py-2.5 text-white/55 font-medium" style="font-family: 'Inter', sans-serif; font-size: 12.5px;">
                                                <span class="text-white/30 mr-2 font-mono"><?= str_pad((string)$rowIndex++, 2, '0', STR_PAD_LEFT) ?></span>
                                                <?= e($foldLabel) ?>
                                            </td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-white/75"><?= e(format_benchmark_percent($row['baseline']['auc'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-white/75"><?= e(format_benchmark_percent($row['baseline']['aupr'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-white/75"><?= e(format_benchmark_percent($row['baseline']['f1'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-emerald-300"><?= e(format_benchmark_percent($row['improved']['auc'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-emerald-300"><?= e(format_benchmark_percent($row['improved']['aupr'] ?? null)) ?></td>
                                            <td class="text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] text-emerald-300"><?= e(format_benchmark_percent($row['improved']['f1'] ?? null)) ?></td>
                                        </tr>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
                          <div class="flex items-center gap-2 text-white/35 text-xs" style="font-family: 'Inter', sans-serif;">
                            <i data-lucide="file-code-2" class="w-3.5 h-3.5"></i>
                            <span>Tệp dữ liệu gốc:</span>
                            <code class="px-1.5 py-0.5 rounded bg-white/[0.04] border border-white/[0.06] text-white/55 font-mono text-[10.5px]">
                              <?= e(basename((string) ($datasetInfo['source_files']['improved'] ?? ''))) ?>
                            </code>
                          </div>
                          <div class="flex items-center gap-3 text-white/40 text-[10.5px]" style="font-family: 'Inter', sans-serif;">
                            <span class="inline-flex items-center gap-1.5">
                              <span class="w-2 h-2 rounded-full bg-white/40 shadow-[0_0_6px_rgba(255,255,255,0.4)]"></span>
                              Baseline
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                              <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(74,222,128,0.8)]"></span>
                              Improved (HGT)
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                              <span class="w-2 h-2 rounded-full bg-amber-400 shadow-[0_0_6px_rgba(251,191,36,0.8)]"></span>
                              Mean / Std
                            </span>
                          </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </main>
    </div>
</div>

<script>
function activateBenchmarkTab(datasetKey) {
    document.querySelectorAll('[data-benchmark-tab]').forEach((button) => {
        const active = button.dataset.benchmarkTab === datasetKey;
        
        // Dynamic class updates for tabs
        if (active) {
            button.className = "relative inline-flex items-center gap-2 px-4 py-2.5 rounded-full transition-all bg-gradient-to-r from-blue-500/25 to-purple-500/25 border border-blue-400/40 text-white shadow-[0_0_24px_-4px_rgba(96,165,250,0.6)]";
            const dot = button.querySelector('.tab-dot');
            if (dot) dot.className = "w-1.5 h-1.5 rounded-full tab-dot bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.9)]";
            button.setAttribute('aria-selected', 'true');
            
            // Re-render delta cards at the top dynamically based on selected tab!
            const dAuc = button.dataset.deltaAuc;
            const dAupr = button.dataset.deltaAupr;
            const dF1 = button.dataset.deltaF1;
            
            const deltaContainer = document.getElementById('delta-container');
            if (deltaContainer) {
                deltaContainer.innerHTML = `
                  <div class="flex items-center justify-between gap-6 px-4 py-2.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 w-52">
                    <span class="text-emerald-200/70 text-xs font-semibold" style="font-family: 'Inter', sans-serif;">ΔAUC</span>
                    <span class="text-emerald-300 font-mono text-[14px] font-bold">+${dAuc}%</span>
                  </div>
                  <div class="flex items-center justify-between gap-6 px-4 py-2.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 w-52">
                    <span class="text-emerald-200/70 text-xs font-semibold" style="font-family: 'Inter', sans-serif;">ΔAUPR</span>
                    <span class="text-emerald-300 font-mono text-[14px] font-bold">+${dAupr}%</span>
                  </div>
                  <div class="flex items-center justify-between gap-6 px-4 py-2.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 w-52">
                    <span class="text-emerald-200/70 text-xs font-semibold" style="font-family: 'Inter', sans-serif;">ΔF1</span>
                    <span class="text-emerald-300 font-mono text-[14px] font-bold">+${dF1}%</span>
                  </div>
                `;
            }
        } else {
            button.className = "relative inline-flex items-center gap-2 px-4 py-2.5 rounded-full transition-all bg-white/[0.03] border border-white/[0.08] text-white/55 hover:text-white hover:bg-white/[0.06]";
            const dot = button.querySelector('.tab-dot');
            if (dot) dot.className = "w-1.5 h-1.5 rounded-full tab-dot bg-white/30";
            button.setAttribute('aria-selected', 'false');
        }
    });

    document.querySelectorAll('[data-benchmark-panel]').forEach((panel) => {
        panel.classList.toggle('is-active', panel.dataset.benchmarkPanel === datasetKey);
    });
}

(function initBenchmarkTabs() {
    const buttons = Array.from(document.querySelectorAll('[data-benchmark-tab]'));
    if (!buttons.length) return;

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            activateBenchmarkTab(button.dataset.benchmarkTab || '');
        });
    });

    const initialButton = buttons.find((button) => button.classList.contains('benchmark-tab')) || buttons[0];
    if (initialButton) {
        activateBenchmarkTab(initialButton.dataset.benchmarkTab || '');
    }
    
    // Draw initial icons
    if (window.lucide) {
        window.lucide.createIcons();
    }
})();
</script>
</body>
</html>
