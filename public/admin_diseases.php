<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $sourceCode = trim($_POST['source_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($action === 'create' && $sourceCode !== '' && $name !== '') {
        $stmt = db()->prepare('INSERT INTO diseases (source_code, name, description) VALUES (:source_code, :name, :description)');
        $stmt->execute([
            'source_code' => $sourceCode,
            'name' => $name,
            'description' => $description
        ]);
        flash('success', 'Đã thêm bệnh lý mới.');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM diseases WHERE id = :id');
        $stmt->execute(['id' => $id]);
        flash('success', 'Đã xoá bệnh lý.');
    }

    redirect('admin_diseases.php');
}

$success = flash('success');
$rows = db()->query('SELECT * FROM diseases ORDER BY created_at DESC LIMIT 50')->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý bệnh lý · AMNTDDA AI</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              border: 'rgba(255, 255, 255, 0.08)',
              input: 'rgba(255, 255, 255, 0.05)',
              ring: oklch => 'oklch(0.439 0 0)',
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
            
            <!-- Page Header -->
            <section class="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden flex justify-between items-center flex-wrap gap-4">
                <div>
                    <h2 class="text-white font-semibold text-xl flex items-center gap-2" style="font-family: 'Space Grotesk', sans-serif;">
                        <i data-lucide="activity" class="text-purple-300 w-5 h-5"></i>
                        <span>Quản lý thực thể Bệnh lý</span>
                    </h2>
                    <p class="text-white/50 text-sm mt-1" style="font-family: 'Inter', sans-serif;">
                        Biên soạn thực thể bệnh lý, mã phân loại và mô tả triệu chứng y sinh.
                    </p>
                </div>
                <a href="admin.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/[0.03] border border-white/[0.08] text-white/70 hover:text-white hover:bg-white/[0.06] transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <span>Quay lại Admin</span>
                </a>
            </section>

            <?php if ($success): ?>
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-sm font-medium" style="font-family: 'Inter', sans-serif;"><?= e($success) ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">
                
                <!-- Create Form (2 cols) -->
                <div class="lg:col-span-2 rounded-[24px] bg-white/[0.03] border border-white/[0.06] p-6 backdrop-blur-2xl">
                    <h3 class="text-white font-semibold text-[15px] mb-2" style="font-family: 'Space Grotesk', sans-serif;">Thêm bệnh lý mới</h3>
                    <p class="text-white/40 text-xs mb-5" style="font-family: 'Inter', sans-serif;">Nhập mã nguồn và tên bệnh lý để lưu trữ vào cơ sở dữ liệu y sinh.</p>
                    
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="action" value="create">
                        
                        <label class="flex flex-col gap-1.5">
                            <span class="text-white/50 px-1 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Mã nguồn (Disease ID)</span>
                            <input class="w-full h-11 px-4 rounded-xl bg-black/40 border border-white/[0.08] focus:border-purple-400/40 focus:outline-none focus:ring-2 focus:ring-purple-500/20 text-white placeholder:text-white/30 text-sm" name="source_code" placeholder="Ví dụ: D102100" required style="font-family: 'Inter', sans-serif;">
                        </label>
                        
                        <label class="flex flex-col gap-1.5">
                            <span class="text-white/50 px-1 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Tên bệnh lý</span>
                            <input class="w-full h-11 px-4 rounded-xl bg-black/40 border border-white/[0.08] focus:border-purple-400/40 focus:outline-none focus:ring-2 focus:ring-purple-500/20 text-white placeholder:text-white/30 text-sm" name="name" placeholder="Ví dụ: Lung Cancer" required style="font-family: 'Inter', sans-serif;">
                        </label>
                        
                        <label class="flex flex-col gap-1.5">
                            <span class="text-white/50 px-1 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Mô tả bệnh lý</span>
                            <textarea class="w-full h-32 p-3 rounded-xl bg-black/40 border border-white/[0.08] focus:border-purple-400/40 focus:outline-none focus:ring-2 focus:ring-purple-500/20 text-white placeholder:text-white/30 text-sm" name="description" placeholder="Thông tin triệu chứng, mô tả hoặc mã phân loại y sinh..." style="font-family: 'Inter', sans-serif;"></textarea>
                        </label>
                        
                        <button type="submit" class="relative group inline-flex items-center justify-center gap-2 h-11 w-full rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_6px_24px_-8px_rgba(96,165,250,0.5)] hover:shadow-[0_10px_32px_-4px_rgba(139,92,246,0.6)] transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                            <span class="absolute inset-0 rounded-xl bg-gradient-to-r from-white/10 to-transparent opacity-40"></span>
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span>Lưu thông tin</span>
                        </button>
                    </form>
                </div>
                
                <!-- Table View (3 cols) -->
                <div class="lg:col-span-3 rounded-[24px] bg-white/[0.03] border border-white/[0.06] p-6 backdrop-blur-2xl">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-white font-semibold text-[15px]" style="font-family: 'Space Grotesk', sans-serif;">Danh sách bệnh lý</h3>
                            <p class="text-white/40 text-xs" style="font-family: 'Inter', sans-serif;">Hiển thị tối đa 50 thực thể bệnh lý vừa thêm.</p>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 text-[11px] font-semibold tracking-wider font-mono">
                            <?= count($rows) ?> bản ghi
                        </span>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-white/[0.05] bg-black/20 max-h-[580px] overflow-y-auto custom-scroll">
                        <table class="w-full border-collapse text-[12.5px] text-left" style="font-family: 'Inter', sans-serif;">
                            <thead>
                                <tr class="bg-white/[0.04] border-b border-white/[0.08] text-white/60 font-semibold text-[11px] uppercase tracking-wider sticky top-0 z-10 backdrop-blur-md">
                                    <th class="px-4 py-3">ID</th>
                                    <th class="px-4 py-3">Mã nguồn</th>
                                    <th class="px-4 py-3">Tên bệnh lý</th>
                                    <th class="px-4 py-3 text-right">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-white/35" style="font-family: 'Inter', sans-serif;">
                                            Chưa có dữ liệu bệnh lý nào được lưu.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition-all">
                                        <td class="px-4 py-3 text-white/35 font-mono">#<?= $row['id'] ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 font-mono text-[10px] font-semibold uppercase">
                                                <?= e((string) $row['source_code']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-white/80"><?= e((string) $row['name']) ?></td>
                                        <td class="px-4 py-3 text-right">
                                            <form method="post" class="inline-block" onsubmit="return confirm('Bạn có chắc chắn muốn xoá bệnh lý này?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                                <button class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-white/40 hover:text-red-400 hover:bg-white/[0.05] transition-all" type="submit" title="Xóa bệnh lý">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
