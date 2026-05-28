<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

/* ── Chọn dataset ── */
$selectedDataset = $_GET['dataset'] ?? 'C';
if (!in_array($selectedDataset, ['B', 'C', 'F'], true)) {
    $selectedDataset = 'C';
}

$dataDir = realpath(__DIR__ . '/../AMDGT/data/' . $selectedDataset . '-dataset') ?: '';

/* ── Đếm thực thể theo dataset ── */
function count_entities(string $file, bool $hasHeader = true): int
{
    if (!is_file($file)) return 0;
    $n = count_csv_lines($file);
    return max(0, $hasHeader ? $n - 1 : $n);
}

$drugFile    = $dataDir . '/DrugInformation.csv';
$diseaseFile = $dataDir . '/DiseaseFeature.csv';
$proteinFile = $dataDir . '/ProteinInformation.csv';

$stats = [
    'drugs'       => count_entities($drugFile),
    'diseases'    => count_entities($diseaseFile, false), // DiseaseFeature.csv không có header
    'proteins'    => count_entities($proteinFile),
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
// Initial draw of icons
if (window.lucide) {
    window.lucide.createIcons();
}
</script>
</body>
</html>
