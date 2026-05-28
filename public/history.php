<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$user = current_user();
$stmt = db()->prepare('SELECT pr.*, u.username FROM prediction_requests pr INNER JOIN users u ON u.id = pr.user_id WHERE pr.user_id = :user_id ORDER BY pr.created_at DESC');
$stmt->execute(['user_id' => $user['id']]);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch sử tra cứu · AMNTDDA AI</title>
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
                DEFAULT: oklch => 'oklch(0.269 0 0)',
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
            
            <a href="history.php" class="relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden text-white" style="font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;">
              <span class="absolute inset-0 bg-gradient-to-r from-blue-500/25 via-purple-500/15 to-transparent"></span>
              <span class="absolute inset-y-1 right-0 w-[2px] rounded-l-full bg-gradient-to-b from-blue-400 to-purple-500 shadow-[0_0_12px_2px_rgba(96,165,250,0.7)]"></span>
              <span class="absolute inset-0 border border-white/10 rounded-xl"></span>
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
        <main class="flex-1 min-w-0 ml-[240px] p-6 lg:p-8 flex flex-col gap-6 font-sans">
            
            <!-- Page Header -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden flex justify-between items-center flex-wrap gap-4">
                <div>
                    <h2 class="text-white font-semibold text-xl flex items-center gap-2.5" style="font-family: 'Space Grotesk', sans-serif;">
                        <i data-lucide="history" class="text-blue-400 w-5.5 h-5.5"></i>
                        <span>Lịch sử tra cứu</span>
                    </h2>
                    <p class="text-white/50 text-sm mt-1" style="font-family: 'Inter', sans-serif;">
                        Lưu trữ toàn bộ các phiên chẩn đoán y sinh đã thực hiện trên nền tảng.
                    </p>
                </div>
            </section>

            <!-- Logs / History Table -->
            <div class="rounded-[24px] bg-white/[0.03] border border-white/[0.06] p-6 backdrop-blur-2xl">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Nhật ký chẩn đoán</h3>
                        <p class="text-white/40 text-xs mt-0.5" style="font-family: 'Inter', sans-serif;">Toàn bộ lịch sử các yêu cầu dự đoán được lưu trữ cho tài khoản này.</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-300 text-[11px] font-semibold font-mono tracking-wider">
                        <?= count($rows) ?> bản ghi
                    </span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-white/[0.05] bg-black/20 overflow-y-auto max-h-[620px] custom-scroll">
                    <table class="w-full border-collapse text-[12.5px] text-left" style="font-family: 'Inter', sans-serif;">
                        <thead>
                            <tr class="bg-white/[0.04] border-b border-white/[0.08] text-white/60 font-semibold text-[11px] uppercase tracking-wider sticky top-0 z-10 backdrop-blur-md">
                                <th class="px-4 py-3.5">ID</th>
                                <th class="px-4 py-3.5">Kiểu tra cứu</th>
                                <th class="px-4 py-3.5">Truy vấn</th>
                                <th class="px-4 py-3.5 text-center">Tham số K</th>
                                <th class="px-4 py-3.5 text-center">Trạng thái</th>
                                <th class="px-4 py-3.5 text-right">Thời gian tạo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center text-white/30" style="font-family: 'Inter', sans-serif;">
                                        Chưa có lịch sử chẩn đoán nào được ghi nhận.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                                <tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition-all">
                                    <td class="px-4 py-3.5 font-mono text-white/40">#<?= e((string) $row['id']) ?></td>
                                    
                                    <td class="px-4 py-3.5">
                                        <?php if ($row['query_type'] === 'drug'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-300 text-[10px] font-semibold">
                                                <i data-lucide="pill" class="w-2.5 h-2.5"></i>
                                                <span>Thuốc → Bệnh</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 text-[10px] font-semibold">
                                                <i data-lucide="activity" class="w-2.5 h-2.5"></i>
                                                <span>Bệnh → Thuốc</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-4 py-3.5 font-semibold text-white/85 max-w-[320px] truncate" title="<?= e((string) $row['input_text']) ?>">
                                        <?= e((string) $row['input_text']) ?>
                                    </td>
                                    
                                    <td class="px-4 py-3.5 text-center">
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-white/5 border border-white/10 text-white/70 font-mono text-[10px] font-semibold">
                                            Top-<?= e((string) $row['top_k']) ?>
                                        </span>
                                    </td>
                                    
                                    <td class="px-4 py-3.5 text-center">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-[10px] font-semibold">
                                            <span class="w-1 h-1 rounded-full bg-emerald-400 animate-ping"></span>
                                            <span>Hoàn tất</span>
                                        </span>
                                    </td>
                                    
                                    <td class="px-4 py-3.5 text-right font-mono text-white/40 text-[11.5px]">
                                        <?= e((string) $row['created_at']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
