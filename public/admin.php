<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

/* ── Chọn dataset ── */
$selectedDataset = $_GET['dataset'] ?? 'C';
if (!in_array($selectedDataset, ['B', 'C', 'F'], true)) {
    $selectedDataset = 'C';
}

$datasetsInfo = [];
foreach (['B', 'C', 'F'] as $ds) {
    $dsDir = realpath(__DIR__ . '/../AMDGT/data/' . $ds . '-dataset') ?: '';
    
    if ($dsDir !== '') {
        $drugs = count_csv_rows_robust($dsDir . '/DrugInformation.csv', true);
        $diseases = count_csv_rows_robust($dsDir . '/DiseaseFeature.csv', false); // DiseaseFeature.csv không có header
        $proteins = count_csv_rows_robust($dsDir . '/ProteinInformation.csv', true);
        
        $drugDisease = count_csv_rows_robust($dsDir . '/DrugDiseaseAssociationNumber.csv', true);
        $drugProtein = count_csv_rows_robust($dsDir . '/DrugProteinAssociationNumber.csv', true);
        $diseaseProtein = count_csv_rows_robust($dsDir . '/ProteinDiseaseAssociationNumber.csv', true);
    } else {
        $drugs = $diseases = $proteins = $drugDisease = $drugProtein = $diseaseProtein = 0;
    }
    
    $sparsity = ($drugs * $diseases) > 0 ? ($drugDisease / ($drugs * $diseases)) : 0;
    
    $datasetsInfo[$ds] = [
        'name' => $ds . '-dataset',
        'drugs' => $drugs,
        'diseases' => $diseases,
        'proteins' => $proteins,
        'drugDisease' => $drugDisease,
        'drugProtein' => $drugProtein,
        'diseaseProtein' => $diseaseProtein,
        'sparsity' => $sparsity
    ];
}

$stats = [
    'drugs'       => $datasetsInfo[$selectedDataset]['drugs'],
    'diseases'    => $datasetsInfo[$selectedDataset]['diseases'],
    'proteins'    => $datasetsInfo[$selectedDataset]['proteins'],
    'predictions' => (int) db()->query('SELECT COUNT(*) FROM prediction_requests')->fetchColumn(),
];

/* Đếm liên kết từ DB */
try {
    $stats['links'] = (int) db()->query('SELECT COUNT(*) FROM drug_disease_links')->fetchColumn();
} catch (Exception $e) {
    $stats['links'] = 0;
}

$recent = db()->query('SELECT * FROM prediction_requests ORDER BY created_at DESC LIMIT 8')->fetchAll();

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Khu vực quản trị · AMNTDDA AI</title>
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
    <script src="https://unpkg.com/3d-force-graph"></script>
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
            
            <a href="compare_models.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="git-compare" class="relative w-4 h-4"></i>
              <span class="relative">So sánh Model</span>
            </a>
            
            <a href="history.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white/55 hover:text-white hover:bg-white/[0.04]" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500;">
              <i data-lucide="history" class="relative w-4 h-4"></i>
              <span class="relative">Lịch sử</span>
            </a>
            
            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a href="admin.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
              <span class="absolute inset-0 bg-gradient-to-r from-blue-500/25 via-purple-500/15 to-transparent"></span>
              <span class="absolute inset-y-1 right-0 w-[2px] rounded-l-full bg-gradient-to-b from-blue-400 to-purple-500 shadow-[0_0_12px_2px_rgba(96,165,250,0.7)]"></span>
              <span class="absolute inset-0 border border-white/10 rounded-xl"></span>
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
            
            <!-- Admin Banner Page Header matching AdminPage.tsx -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden flex justify-between items-center flex-wrap gap-4">
                <div class="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-purple-500/10 blur-3xl pointer-events-none"></div>
                
                <div class="relative max-w-xl">
                    <div class="flex items-center gap-3 mb-2">
                      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500/30 to-blue-600/30 border border-purple-400/20 grid place-items-center text-purple-300">
                        <i data-lucide="shield" class="w-[20px] h-[20px]"></i>
                      </div>
                      <h2 class="text-white font-semibold text-xl" style="font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.01em;">
                        Khu vực quản trị
                      </h2>
                    </div>
                    <p class="text-white/50 text-sm" style="font-family: 'Inter', sans-serif;">
                        Quản lý dữ liệu bệnh học, protein và kiểm tra hoạt động hệ thống AMNTDDA AI.
                    </p>
                </div>

                <!-- Admin operations -->
                <div class="relative flex items-center gap-2 flex-wrap">
                    <a href="admin_drugs.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/[0.03] border border-white/[0.08] text-white/70 hover:text-white hover:bg-white/[0.06] hover:border-white/20 transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="pill" class="w-3.5 h-3.5"></i>
                        <span>Quản lý Thuốc</span>
                    </a>
                    <a href="admin_diseases.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/[0.03] border border-white/[0.08] text-white/70 hover:text-white hover:bg-white/[0.06] hover:border-white/20 transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="heart-pulse" class="w-3.5 h-3.5"></i>
                        <span>Quản lý Bệnh</span>
                    </a>
                    <a href="admin_links.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/[0.03] border border-white/[0.08] text-white/70 hover:text-white hover:bg-white/[0.06] hover:border-white/20 transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="link" class="w-3.5 h-3.5"></i>
                        <span>Quản lý Liên kết</span>
                    </a>
                </div>
            </section>

            <!-- Dataset Selection selector -->
            <section class="relative rounded-2xl bg-white/[0.02] border border-white/5 p-4 flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-2">
                    <i data-lucide="database" class="text-blue-300 w-4 h-4"></i>
                    <label for="dataset-select" class="text-white/55 text-xs font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Dataset hiện tại:</label>
                </div>
                <div class="relative w-44">
                    <select id="dataset-select" onchange="window.location.href='admin.php?dataset='+this.value" class="appearance-none w-full h-[38px] pl-4 pr-10 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white cursor-pointer text-xs font-medium" style="font-family: 'Inter', sans-serif;">
                        <option value="B" <?= $selectedDataset === 'B' ? 'selected' : '' ?> class="bg-[#0a0a0f]">B-Dataset</option>
                        <option value="C" <?= $selectedDataset === 'C' ? 'selected' : '' ?> class="bg-[#0a0a0f]">C-Dataset</option>
                        <option value="F" <?= $selectedDataset === 'F' ? 'selected' : '' ?> class="bg-[#0a0a0f]">F-Dataset</option>
                    </select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 pointer-events-none w-3.5 h-3.5"></i>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-300 text-[11px] font-semibold tracking-wider font-mono">
                    <i data-lucide="check-circle" class="w-3 h-3"></i>
                    VIEWING: <?= e($selectedDataset) ?>-Dataset
                </span>
            </section>

            <!-- Stats dashboard grids -->
            <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- Stat Card 1 -->
                <div class="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-5 shadow-[0_0_24px_-8px_rgba(59,130,246,0.3)] overflow-hidden">
                    <div class="absolute top-0 right-0 w-20 h-20 bg-blue-500/10 rounded-bl-[80px]"></div>
                    <div class="flex items-center gap-2 mb-3 text-white/50 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="pill" class="text-blue-300 w-3.5 h-3.5"></i>
                        <span>Tổng số thuốc</span>
                    </div>
                    <h2 class="text-white font-bold text-3xl font-mono" style="font-family: 'IBM Plex Mono', monospace;"><?= number_format($stats['drugs']) ?></h2>
                </div>

                <!-- Stat Card 2 -->
                <div class="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-5 shadow-[0_0_24px_-8px_rgba(248,113,113,0.3)] overflow-hidden">
                    <div class="absolute top-0 right-0 w-20 h-20 bg-red-500/10 rounded-bl-[80px]"></div>
                    <div class="flex items-center gap-2 mb-3 text-white/50 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="heart-pulse" class="text-red-300 w-3.5 h-3.5"></i>
                        <span>Tổng số bệnh</span>
                    </div>
                    <h2 class="text-white font-bold text-3xl font-mono" style="font-family: 'IBM Plex Mono', monospace;"><?= number_format($stats['diseases']) ?></h2>
                </div>

                <!-- Stat Card 3 -->
                <div class="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-5 shadow-[0_0_24px_-8px_rgba(251,191,36,0.3)] overflow-hidden">
                    <div class="absolute top-0 right-0 w-20 h-20 bg-amber-500/10 rounded-bl-[80px]"></div>
                    <div class="flex items-center gap-2 mb-3 text-white/50 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="dna" class="text-amber-300 w-3.5 h-3.5"></i>
                        <span>Tổng số protein</span>
                    </div>
                    <h2 class="text-white font-bold text-3xl font-mono" style="font-family: 'IBM Plex Mono', monospace;"><?= number_format($stats['proteins']) ?></h2>
                </div>

                <!-- Stat Card 4 -->
                <div class="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-5 shadow-[0_0_24px_-8px_rgba(74,222,128,0.3)] overflow-hidden">
                    <div class="absolute top-0 right-0 w-20 h-20 bg-emerald-500/10 rounded-bl-[80px]"></div>
                    <div class="flex items-center gap-2 mb-3 text-white/50 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">
                        <i data-lucide="activity" class="text-emerald-300 w-3.5 h-3.5"></i>
                        <span>Lượt chẩn đoán</span>
                    </div>
                    <h2 class="text-white font-bold text-3xl font-mono" style="font-family: 'IBM Plex Mono', monospace;"><?= number_format($stats['predictions']) ?></h2>
                </div>

            </section>

            <!-- Benchmark Dataset Summary Table matching Table 1 in paper -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
                <div class="absolute -top-32 -right-20 w-96 h-96 rounded-full bg-blue-500/5 blur-3xl pointer-events-none"></div>
                <div class="mb-5 flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <i data-lucide="table" class="text-blue-300 w-4 h-4"></i>
                            <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Bảng tóm tắt các bộ dữ liệu Benchmark (Table 1. Summary of Benchmark Datasets)</h3>
                        </div>
                        <p class="text-white/40 text-xs" style="font-family: 'Inter', sans-serif;">Số liệu thực tế được tính toán động (real-time) từ các tệp tin dataset của hệ thống y sinh.</p>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-white/[0.05] bg-black/25">
                    <table class="w-full border-collapse text-[12.5px] text-left" style="font-family: 'Inter', sans-serif;">
                        <thead>
                            <tr class="bg-white/[0.04] border-b border-white/[0.08] text-white/60 font-semibold text-[11px] uppercase tracking-wider">
                                <th class="px-4 py-3.5">Bộ dữ liệu</th>
                                <th class="px-4 py-3.5 text-center">Thuốc (Drugs)</th>
                                <th class="px-4 py-3.5 text-center">Bệnh (Diseases)</th>
                                <th class="px-4 py-3.5 text-center">Protein (Proteins)</th>
                                <th class="px-4 py-3.5 text-center text-blue-300">Liên kết Thuốc-Bệnh</th>
                                <th class="px-4 py-3.5 text-center text-purple-300">Liên kết Thuốc-Protein</th>
                                <th class="px-4 py-3.5 text-center text-amber-300">Liên kết Bệnh-Protein</th>
                                <th class="px-4 py-3.5 text-right text-emerald-400">Độ thưa thớt (Sparsity)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['B', 'C', 'F'] as $ds): 
                                $info = $datasetsInfo[$ds];
                                $isCurrent = ($selectedDataset === $ds);
                            ?>
                                <tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition-all <?= $isCurrent ? 'bg-blue-500/5 font-semibold text-blue-100 border-l-4 border-l-blue-500' : '' ?>">
                                    <td class="px-4 py-4 flex items-center gap-2">
                                        <i data-lucide="database" class="w-3.5 h-3.5 <?= $isCurrent ? 'text-blue-400' : 'text-white/40' ?>"></i>
                                        <span><?= e($info['name']) ?></span>
                                        <?php if ($isCurrent): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full bg-blue-500/20 text-blue-300 text-[9px] uppercase tracking-wider font-bold">Đang xem</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-center font-mono"><?= number_format($info['drugs']) ?></td>
                                    <td class="px-4 py-4 text-center font-mono"><?= number_format($info['diseases']) ?></td>
                                    <td class="px-4 py-4 text-center font-mono"><?= number_format($info['proteins']) ?></td>
                                    <td class="px-4 py-4 text-center font-mono text-blue-300"><?= number_format($info['drugDisease']) ?></td>
                                    <td class="px-4 py-4 text-center font-mono text-purple-300"><?= number_format($info['drugProtein']) ?></td>
                                    <td class="px-4 py-4 text-center font-mono text-amber-300"><?= number_format($info['diseaseProtein']) ?></td>
                                    <td class="px-4 py-4 text-right font-mono text-emerald-400"><?= number_format($info['sparsity'], 4) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 3D GNN Network Graph Visualizations (6 graphs in total: 3 datasets x 2 graph types) -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
                <div class="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-purple-500/5 blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-32 -right-20 w-96 h-96 rounded-full bg-blue-500/5 blur-3xl pointer-events-none"></div>

                <div class="mb-6 flex justify-between items-center flex-wrap gap-4 border-b border-white/5 pb-5">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500/25 to-purple-500/25 border border-blue-400/20 grid place-items-center text-blue-300">
                                <i data-lucide="network" class="w-4 h-4"></i>
                            </div>
                            <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Trực quan hóa Đồ thị mạng lưới 3D (3D GNN Topology Visualization)</h3>
                        </div>
                        <p class="text-white/40 text-xs" style="font-family: 'Inter', sans-serif;">Mô phỏng cấu trúc không gian 3 chiều của các mạng lưới sinh học phục vụ huấn luyện GNN.</p>
                    </div>

                    <!-- Dataset & Graph Type selectors -->
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Dataset Selector -->
                        <div class="flex rounded-xl bg-black/40 border border-white/[0.08] p-1">
                            <button onclick="changeVizDataset('B')" id="viz-ds-btn-B" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all">B-Dataset</button>
                            <button onclick="changeVizDataset('C')" id="viz-ds-btn-C" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all">C-Dataset</button>
                            <button onclick="changeVizDataset('F')" id="viz-ds-btn-F" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all">F-Dataset</button>
                        </div>

                        <!-- Graph Type Selector -->
                        <div class="flex rounded-xl bg-black/40 border border-white/[0.08] p-1">
                            <button onclick="changeVizType('drug_disease')" id="viz-type-btn-drug_disease" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5">
                                <i data-lucide="share-2" class="w-3 h-3"></i>
                                <span>Mạng Thuốc - Bệnh</span>
                            </button>
                            <button onclick="changeVizType('drug_protein')" id="viz-type-btn-drug_protein" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5">
                                <i data-lucide="pill" class="w-3 h-3"></i>
                                <span>Mạng Thuốc - Protein</span>
                            </button>
                            <button onclick="changeVizType('disease_protein')" id="viz-type-btn-disease_protein" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5">
                                <i data-lucide="heart-pulse" class="w-3 h-3"></i>
                                <span>Mạng Bệnh - Protein</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 3D Graph Container & Legend -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 items-stretch">
                    <!-- Left: Interactive WebGL Canvas -->
                    <div class="lg:col-span-3 rounded-2xl border border-white/5 bg-black/35 relative h-[520px] overflow-hidden flex flex-col justify-center items-center shadow-inner">
                        <!-- WebGL Node Container -->
                        <div id="3d-graph" class="w-full h-full cursor-grab active:cursor-grabbing"></div>

                        <!-- Info overlays -->
                        <div class="absolute bottom-4 left-4 bg-black/85 border border-white/10 rounded-xl px-3.5 py-2.5 backdrop-blur-md flex flex-col gap-1 pointer-events-none">
                            <span class="text-white/40 text-[9px] uppercase tracking-widest font-bold">Trạng thái mô phỏng</span>
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span id="viz-status-label" class="text-white text-xs font-semibold font-mono">LOADING...</span>
                            </div>
                        </div>

                    </div>

                    <!-- Right: Legend and details -->
                    <div class="rounded-2xl border border-white/5 bg-white/[0.02] p-5 flex flex-col justify-between">
                        <div class="flex flex-col gap-5">
                            <div>
                                <span class="text-white/40 text-[10px] font-semibold uppercase tracking-wider block mb-2" style="font-family: 'Inter', sans-serif;">Chú giải Đồ thị (Legend)</span>
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <span class="w-3.5 h-3.5 rounded-full bg-blue-500 shadow-[0_0_12px_rgba(59,130,246,0.6)]"></span>
                                        <div class="flex flex-col">
                                            <span class="text-white font-semibold text-xs">Thuốc (Drugs)</span>
                                            <span class="text-white/40 text-[10px]">Neon Blue · Nút mạng lưới</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="w-3.5 h-3.5 rounded-full bg-red-500 shadow-[0_0_12px_rgba(239,68,68,0.6)]"></span>
                                        <div class="flex flex-col">
                                            <span class="text-white font-semibold text-xs">Bệnh lý (Diseases)</span>
                                            <span class="text-white/40 text-[10px]">Neon Red/Pink · Nút mạng lưới</span>
                                        </div>
                                    </div>
                                    <div id="legend-protein-row" class="flex items-center gap-3">
                                        <span class="w-3.5 h-3.5 rounded-full bg-amber-500 shadow-[0_0_12px_rgba(245,158,11,0.6)]"></span>
                                        <div class="flex flex-col">
                                            <span class="text-white font-semibold text-xs">Protein (Proteins)</span>
                                            <span class="text-white/40 text-[10px]">Neon Gold · Nút mạng lưới</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-white/5 pt-4">
                                <span class="text-white/40 text-[10px] font-semibold uppercase tracking-wider block mb-2.5" style="font-family: 'Inter', sans-serif;">Tham số Bộ dữ liệu mô phỏng</span>
                                <div class="bg-black/20 rounded-xl border border-white/5 p-3.5 flex flex-col gap-2.5 font-mono text-[11px]">
                                    <div id="stat-viz-drugs-row" class="flex justify-between">
                                        <span class="text-white/45">Thuốc (Drugs):</span>
                                        <span id="stat-viz-drugs" class="text-blue-300 font-bold">--</span>
                                    </div>
                                    <div id="stat-viz-diseases-row" class="flex justify-between">
                                        <span class="text-white/45">Bệnh (Diseases):</span>
                                        <span id="stat-viz-diseases" class="text-red-300 font-bold">--</span>
                                    </div>
                                    <div id="stat-viz-proteins-row" class="flex justify-between">
                                        <span class="text-white/45">Protein (Proteins):</span>
                                        <span id="stat-viz-proteins" class="text-amber-300 font-bold">--</span>
                                    </div>
                                    <div class="border-t border-white/5 my-1"></div>
                                    <div id="stat-viz-dd-row" class="flex justify-between">
                                        <span class="text-blue-300/70">Liên kết Thuốc-Bệnh:</span>
                                        <span id="stat-ds-dd" class="text-blue-300 font-bold">--</span>
                                    </div>
                                    <div id="stat-viz-dp-row" class="flex justify-between">
                                        <span class="text-purple-300/70">Liên kết Thuốc-Prot:</span>
                                        <span id="stat-ds-dp" class="text-purple-300 font-bold">--</span>
                                    </div>
                                    <div id="stat-viz-dep-row" class="flex justify-between">
                                        <span class="text-amber-300/70">Liên kết Bệnh-Prot:</span>
                                        <span id="stat-ds-dep" class="text-amber-300 font-bold">--</span>
                                    </div>
                                    <div class="flex justify-between border-t border-white/5 pt-2 mt-1">
                                        <span class="text-emerald-400/80">Độ thưa (Sparsity):</span>
                                        <span id="stat-ds-sparsity" class="text-emerald-400 font-bold">--</span>
                                    </div>
                                    <div class="flex justify-between text-[10px] opacity-40 italic mt-0.5 border-t border-white/5 pt-1.5">
                                        <span>Đang vẽ (Links vẽ):</span>
                                        <span id="stat-viz-links" class="font-bold">--</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Instructions / Tips -->
                        <div class="bg-blue-500/5 border border-blue-500/10 rounded-xl p-3.5 mt-4 text-[11px] text-blue-300/80 leading-relaxed" style="font-family: 'Inter', sans-serif;">
                            <i data-lucide="help-circle" class="w-3.5 h-3.5 inline mr-1 -translate-y-[1px]"></i>
                            <span>Bạn có thể sử dụng chuột trái để xoay không gian 3D, lăn nút giữa chuột để zoom mạng lưới và di chuột lên nút thực thể để xem chi tiết tên y sinh!</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Bottom content grids -->


            <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Diagnostics history card (2 cols on large screen) -->
                <div class="lg:col-span-2 rounded-[24px] bg-white/[0.03] border border-white/[0.06] p-6 backdrop-blur-2xl">
                    <div class="mb-4">
                        <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Lượt chẩn đoán gần đây</h3>
                        <p class="text-white/40 text-xs" style="font-family: 'Inter', sans-serif;">Theo dõi các chẩn đoán y sinh vừa được hệ thống xử lý.</p>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-white/[0.05] bg-black/20">
                        <table class="w-full border-collapse text-[12.5px] text-left" style="font-family: 'Inter', sans-serif;">
                            <thead>
                                <tr class="bg-white/[0.04] border-b border-white/[0.08] text-white/60 font-semibold text-[11px] uppercase tracking-wider">
                                    <th class="px-4 py-3">ID</th>
                                    <th class="px-4 py-3">Kiểu</th>
                                    <th class="px-4 py-3">Nội dung truy vấn</th>
                                    <th class="px-4 py-3">Kết quả</th>
                                    <th class="px-4 py-3 text-right">Thời gian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $row): ?>
                                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition-all">
                                        <td class="px-4 py-3 text-white/35 font-mono">#<?= $row['id'] ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white/[0.06] border border-white/5 text-white/70 text-[10px] font-semibold">
                                                <?= e((string) $row['query_type']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-white/80 max-w-xs truncate"><?= e((string) $row['input_text']) ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-300 text-[10px] font-semibold">
                                                Top-<?= $row['top_k'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right text-white/35 font-mono"><?= date('H:i d/m', strtotime((string) $row['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Database systems status card (1 col) -->
                <div class="rounded-[24px] bg-white/[0.03] border border-white/[0.06] p-6 backdrop-blur-2xl flex flex-col justify-between">
                    <div class="mb-4">
                        <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Hệ thống dữ liệu</h3>
                        <p class="text-white/40 text-xs" style="font-family: 'Inter', sans-serif;">Kiểm tra tổng quan dung lượng đồ thị liên kết.</p>
                    </div>

                    <!-- Glow card showing links -->
                    <div class="rounded-2xl border border-white/[0.05] p-5 bg-gradient-to-br from-blue-500/5 to-purple-500/5 relative overflow-hidden my-4 shadow-[0_0_24px_-8px_rgba(96,165,250,0.15)]">
                        <div class="absolute -right-6 -bottom-6 text-white/[0.02]">
                            <i data-lucide="link" class="w-32 h-32"></i>
                        </div>
                        <span class="text-white/40 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">
                            Tổng liên kết hiện tại
                        </span>
                        <h2 class="text-white font-bold text-4xl font-mono mt-3" style="font-family: 'IBM Plex Mono', monospace;">
                            <?= number_format($stats['links']) ?>
                        </h2>
                        <p class="text-white/45 text-xs mt-3 leading-relaxed" style="font-family: 'Inter', sans-serif;">
                            Số lượng tương tác Thuốc – Bệnh được kiểm chứng trong bộ cơ sở dữ liệu y sinh.
                        </p>
                    </div>

                    <a href="admin_links.php" class="relative group inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_6px_24px_-8px_rgba(96,165,250,0.5)] hover:shadow-[0_10px_32px_-4px_rgba(139,92,246,0.6)] transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                        <span class="absolute inset-0 rounded-xl bg-gradient-to-r from-white/10 to-transparent opacity-40"></span>
                        <span class="relative">Quản lý liên kết ngay</span>
                        <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                    </a>
                </div>

            </section>
        </main>
    </div>
</div>

<script>
const datasetsMetadata = <?= json_encode($datasetsInfo) ?>;
let currentVizDataset = '<?= $selectedDataset ?>';
let currentVizType = 'drug_disease';
let myGraph = null;


// Hàm khởi tạo và tải đồ thị 3D WebGL
function init3dGraph() {
    const statusLabel = document.getElementById('viz-status-label');
    statusLabel.textContent = 'CONNECTING API...';
    
    // Cập nhật ngay lập tức các thông số bộ dữ liệu đang chọn
    const metadata = datasetsMetadata[currentVizDataset];
    if (metadata) {
        document.getElementById('stat-ds-dd').textContent = Number(metadata.drugDisease).toLocaleString('vi-VN');
        document.getElementById('stat-ds-dp').textContent = Number(metadata.drugProtein).toLocaleString('vi-VN');
        document.getElementById('stat-ds-dep').textContent = Number(metadata.diseaseProtein).toLocaleString('vi-VN');
        document.getElementById('stat-ds-sparsity').textContent = Number(metadata.sparsity).toLocaleString('vi-VN', {
            minimumFractionDigits: 4,
            maximumFractionDigits: 4
        });
    }

    // Cập nhật trạng thái các nút Dataset
    ['B', 'C', 'F'].forEach(ds => {
        const btn = document.getElementById(`viz-ds-btn-${ds}`);
        if (btn) {
            if (ds === currentVizDataset) {
                btn.className = 'px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-blue-500 text-white shadow-md transition-all';
            } else {
                btn.className = 'px-3.5 py-1.5 rounded-lg text-xs font-semibold text-white/50 hover:text-white transition-all';
            }
        }
    });

    // Cập nhật trạng thái các nút Graph Type
    ['drug_disease', 'drug_protein', 'disease_protein'].forEach(type => {
        const btn = document.getElementById(`viz-type-btn-${type}`);
        if (btn) {
            if (type === currentVizType) {
                btn.className = 'px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-purple-500 text-white shadow-md transition-all flex items-center gap-1.5';
            } else {
                btn.className = 'px-3.5 py-1.5 rounded-lg text-xs font-semibold text-white/50 hover:text-white transition-all flex items-center gap-1.5';
            }
        }
    });

    // Ẩn/Hiện chú giải và số liệu tương ứng với từng loại đồ thị
    const blueSpan = document.querySelector('span.bg-blue-500');
    const redSpan = document.querySelector('span.bg-red-500');
    const drugLegend = blueSpan ? blueSpan.closest('.flex.items-center.gap-3') : null;
    const diseaseLegend = redSpan ? redSpan.closest('.flex.items-center.gap-3') : null;
    const proteinLegend = document.getElementById('legend-protein-row');
    
    const drugStatRow = document.getElementById('stat-viz-drugs') ? document.getElementById('stat-viz-drugs').closest('.flex.justify-between') : null;
    const diseaseStatRow = document.getElementById('stat-viz-diseases') ? document.getElementById('stat-viz-diseases').closest('.flex.justify-between') : null;
    const proteinStatRow = document.getElementById('stat-viz-proteins-row');

    const ddLinkRow = document.getElementById('stat-viz-dd-row');
    const dpLinkRow = document.getElementById('stat-viz-dp-row');
    const depLinkRow = document.getElementById('stat-viz-dep-row');

    if (currentVizType === 'drug_disease') {
        if (drugLegend) drugLegend.style.display = 'flex';
        if (diseaseLegend) diseaseLegend.style.display = 'flex';
        if (proteinLegend) proteinLegend.style.display = 'none';
        
        if (drugStatRow) drugStatRow.style.display = 'flex';
        if (diseaseStatRow) diseaseStatRow.style.display = 'flex';
        if (proteinStatRow) proteinStatRow.style.display = 'none';
        
        if (ddLinkRow) ddLinkRow.style.display = 'flex';
        if (dpLinkRow) dpLinkRow.style.display = 'none';
        if (depLinkRow) depLinkRow.style.display = 'none';
    } else if (currentVizType === 'drug_protein') {
        if (drugLegend) drugLegend.style.display = 'flex';
        if (diseaseLegend) diseaseLegend.style.display = 'none';
        if (proteinLegend) proteinLegend.style.display = 'flex';
        
        if (drugStatRow) drugStatRow.style.display = 'flex';
        if (diseaseStatRow) diseaseStatRow.style.display = 'none';
        if (proteinStatRow) proteinStatRow.style.display = 'flex';
        
        if (ddLinkRow) ddLinkRow.style.display = 'none';
        if (dpLinkRow) dpLinkRow.style.display = 'flex';
        if (depLinkRow) depLinkRow.style.display = 'none';
    } else { // disease_protein
        if (drugLegend) drugLegend.style.display = 'none';
        if (diseaseLegend) diseaseLegend.style.display = 'flex';
        if (proteinLegend) proteinLegend.style.display = 'flex';
        
        if (drugStatRow) drugStatRow.style.display = 'none';
        if (diseaseStatRow) diseaseStatRow.style.display = 'flex';
        if (proteinStatRow) proteinStatRow.style.display = 'flex';
        
        if (ddLinkRow) ddLinkRow.style.display = 'none';
        if (dpLinkRow) dpLinkRow.style.display = 'none';
        if (depLinkRow) depLinkRow.style.display = 'flex';
    }

    // Gọi API động lấy dữ liệu
    fetch(`api_graph.php?dataset=${currentVizDataset}&type=${currentVizType}`)
        .then(res => res.json())
        .then(data => {
            statusLabel.textContent = 'RENDERING WebGL...';
            
            // Cập nhật động số thực thể thực tế từ dữ liệu tải về
            const drugsCount = data.nodes.filter(n => n.group === 'drug').length;
            const diseasesCount = data.nodes.filter(n => n.group === 'disease').length;
            const proteinsCount = data.nodes.filter(n => n.group === 'protein').length;

            if (document.getElementById('stat-viz-drugs')) {
                document.getElementById('stat-viz-drugs').textContent = drugsCount.toLocaleString('vi-VN');
            }
            if (document.getElementById('stat-viz-diseases')) {
                document.getElementById('stat-viz-diseases').textContent = diseasesCount.toLocaleString('vi-VN');
            }
            if (document.getElementById('stat-viz-proteins')) {
                document.getElementById('stat-viz-proteins').textContent = proteinsCount.toLocaleString('vi-VN');
            }
            document.getElementById('stat-viz-links').textContent = data.links.length.toLocaleString('vi-VN');

            const graphContainer = document.getElementById('3d-graph');
            
            // Xóa canvas cũ nếu có để tránh tràn bộ nhớ
            graphContainer.innerHTML = '';

            // Tỷ lệ động link width & opacity dựa trên số lượng liên kết để hiển thị thanh thoát, rực rỡ
            const numLinks = data.links.length;
            let finalLinkWidth = 1.8;
            let finalLinkOpacity = 0.65;

            if (numLinks > 20000) {
                finalLinkWidth = 0.5;
                finalLinkOpacity = 0.15;
            } else if (numLinks > 5000) {
                finalLinkWidth = 0.8;
                finalLinkOpacity = 0.32;
            } else if (numLinks > 1000) {
                finalLinkWidth = 1.25;
                finalLinkOpacity = 0.48;
            }

            myGraph = ForceGraph3D()(graphContainer)
                .graphData(data)
                .backgroundColor('rgba(0,0,0,0)') // trong suốt để tiệp với nền mờ kính
                .showNavInfo(false)
                .nodeColor(node => {
                    if (node.group === 'drug') return '#3b82f6'; // Neon Blue
                    if (node.group === 'disease') return '#ef4444'; // Neon Red
                    return '#f59e0b'; // Neon Gold (Protein)
                })
                .linkColor(link => link.color || 'rgba(255,255,255,0.08)')
                .nodeLabel(node => `
                    <div class="bg-black/95 px-3 py-2 rounded-xl border border-white/10 shadow-2xl backdrop-blur-md flex flex-col gap-0.5" style="font-family: 'Inter', sans-serif; font-size: 11px;">
                        <span class="text-white/40 text-[9px] uppercase tracking-widest font-bold text-left">${node.type}</span>
                        <span class="text-white font-bold text-left">${node.name}</span>
                        <span class="text-white/50 font-mono text-[10px] text-left">Mã: ${node.code}</span>
                    </div>
                `)
                .nodeVal(node => node.val)
                .nodeOpacity(0.95)
                .linkWidth(finalLinkWidth)
                .linkOpacity(finalLinkOpacity)
                .width(graphContainer.clientWidth)
                .height(graphContainer.clientHeight);

            // Tối ưu hóa hiệu năng hội tụ cho mạng đồ thị lớn
            const numNodes = data.nodes.length;
            if (numNodes > 800) {
                myGraph.cooldownTicks(90); // Giới hạn số tick layout để giải phóng CPU sớm
            }


            statusLabel.textContent = `${currentVizDataset}-DATASET: ACTIVE`;
        })
        .catch(err => {
            console.error(err);
            statusLabel.textContent = 'API ERROR';
        });
}

function changeVizDataset(ds) {
    currentVizDataset = ds;
    init3dGraph();
}

function changeVizType(type) {
    currentVizType = type;
    init3dGraph();
}


// Lắng nghe sự kiện resize màn hình để cập nhật kích thước đồ thị
window.addEventListener('resize', () => {
    if (myGraph) {
        const container = document.getElementById('3d-graph');
        myGraph.width(container.clientWidth).height(container.clientHeight);
    }
});

// Chạy khởi tạo đồ thị lần đầu và vẽ icon Lucide
document.addEventListener('DOMContentLoaded', () => {
    init3dGraph();
    
    if (window.lucide) {
        window.lucide.createIcons();
    }
});
</script>
</body>
</html>
