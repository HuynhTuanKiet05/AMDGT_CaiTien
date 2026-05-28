<?php
require_once __DIR__ . '/../app/services/AuthService.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (AuthService::attemptLogin($username, $password)) {
        redirect('index.php');
    }

    $error = 'Sai tên đăng nhập hoặc mật khẩu.';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập · AMNTDDA AI</title>
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
<body class="bg-[#0a0a0f] text-white flex min-h-screen w-full items-center justify-center p-4 overflow-hidden relative">

    <!-- Glowing background meshes -->
    <div class="pointer-events-none fixed inset-0 opacity-[0.35] z-0" style="background: radial-gradient(ellipse 80% 60% at 50% 30%, rgba(96,165,250,0.15), transparent 60%), radial-gradient(ellipse 70% 50% at 85% 80%, rgba(139,92,246,0.18), transparent 60%), radial-gradient(ellipse 50% 40% at 15% 80%, rgba(59,130,246,0.06), transparent 70%);"></div>
    <div class="pointer-events-none fixed inset-0 opacity-[0.18] z-0" style="background-image: radial-gradient(rgba(255,255,255,0.35) 1px, transparent 1px); background-size: 24px 24px;"></div>

    <div class="relative z-10 w-full max-w-[460px] p-7 md:p-8 rounded-[32px] bg-white/[0.02] border border-white/[0.06] backdrop-blur-3xl shadow-[0_24px_64px_-16px_rgba(0,0,0,0.7)] flex flex-col gap-6">
        
        <!-- Header -->
        <div class="flex items-center gap-3">
            <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 grid place-items-center shadow-[0_0_32px_-4px_rgba(96,165,250,0.7)] shrink-0">
                <i data-lucide="sparkles" class="text-white w-5 h-5"></i>
                <span class="absolute inset-0 rounded-2xl border border-white/20"></span>
            </div>
            <div class="flex flex-col leading-tight min-w-0">
                <span style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 17px; letter-spacing: 0.02em;" class="text-white">
                    AMNTDDA AI
                </span>
                <span style="font-family: 'Inter', sans-serif; font-size: 11px;" class="text-white/40 truncate">
                    Nền tảng GNN y sinh chính xác
                </span>
            </div>
        </div>

        <div>
            <h1 class="text-white font-semibold text-[20px] tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">Đăng nhập hệ thống</h1>
            <p class="text-white/40 text-[12.5px] mt-1" style="font-family: 'Inter', sans-serif;">
                Khám phá tương tác thuốc - bệnh lý qua mô hình đồ thị dị thể (HGT) nâng cao.
            </p>
        </div>

        <!-- Alert messages -->
        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs font-medium" style="font-family: 'Inter', sans-serif;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Form fields -->
        <form method="post" class="space-y-4">
            <label class="flex flex-col gap-1.5">
                <span class="text-white/50 px-1 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Tên tài khoản</span>
                <div class="relative">
                    <input class="w-full h-11 pl-10 pr-4 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white placeholder:text-white/30 text-sm" type="text" name="username" placeholder="Nhập tên đăng nhập" required style="font-family: 'Inter', sans-serif;">
                    <div class="absolute inset-y-0 left-3.5 flex items-center pointer-events-none text-white/45">
                        <i data-lucide="user" class="w-4 h-4"></i>
                    </div>
                </div>
            </label>
            
            <label class="flex flex-col gap-1.5">
                <span class="text-white/50 px-1 text-[11px] font-semibold uppercase tracking-wider" style="font-family: 'Inter', sans-serif;">Mật khẩu</span>
                <div class="relative">
                    <input class="w-full h-11 pl-10 pr-4 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white placeholder:text-white/30 text-sm" type="password" name="password" placeholder="Nhập mật khẩu" required style="font-family: 'Inter', sans-serif;">
                    <div class="absolute inset-y-0 left-3.5 flex items-center pointer-events-none text-white/45">
                        <i data-lucide="lock" class="w-4 h-4"></i>
                    </div>
                </div>
            </label>
            
            <button type="submit" class="relative group inline-flex items-center justify-center gap-2 h-11 w-full rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_6px_24px_-8px_rgba(96,165,250,0.5)] hover:shadow-[0_10px_32px_-4px_rgba(139,92,246,0.6)] transition-all text-xs font-semibold" style="font-family: 'Inter', sans-serif;">
                <span class="absolute inset-0 rounded-xl bg-gradient-to-r from-white/10 to-transparent opacity-40"></span>
                <span>Đăng nhập</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </button>
        </form>

        <!-- Credentials pill -->
        <div class="p-3 rounded-2xl bg-white/[0.02] border border-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 grid place-items-center text-blue-300 shrink-0">
                <i data-lucide="key" class="w-4 h-4"></i>
            </div>
            <div class="flex-1 min-w-0" style="font-family: 'Inter', sans-serif;">
                <div class="text-white/50 text-[10px] uppercase font-semibold tracking-wider">Tài khoản thử nghiệm</div>
                <div class="text-white text-xs font-medium mt-0.5">
                    User: <code class="font-mono bg-white/5 px-1 py-0.5 rounded text-blue-300">admin</code> / Pass: <code class="font-mono bg-white/5 px-1 py-0.5 rounded text-blue-300">password</code>
                </div>
            </div>
        </div>

        <!-- Features -->
        <div class="space-y-2 pt-3 border-t border-white/5" style="font-family: 'Inter', sans-serif;">
            <div class="flex items-start gap-2.5 text-[11.5px] text-white/45 leading-normal">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-400 mt-1.5 shrink-0"></span>
                <span>Dự đoán tương quan thuốc - bệnh qua đồ thị dị thể trong giây lát.</span>
            </div>
            <div class="flex items-start gap-2.5 text-[11.5px] text-white/45 leading-normal">
                <span class="w-1.5 h-1.5 rounded-full bg-purple-400 mt-1.5 shrink-0"></span>
                <span>So sánh đối chuẩn 10-Fold CV trực quan giữa các mô hình học sâu.</span>
            </div>
            <div class="flex items-start gap-2.5 text-[11.5px] text-white/45 leading-normal">
                <span class="w-1.5 h-1.5 rounded-full bg-pink-400 mt-1.5 shrink-0"></span>
                <span>Truy lục nhật ký chẩn đoán và quản lý tập thực thể sinh học tối giản.</span>
            </div>
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
