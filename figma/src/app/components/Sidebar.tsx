import { LayoutDashboard, Sparkles, FlaskConical, GitCompare, History, Shield, LogOut } from "lucide-react";

const navItems = [
  { id: "overview", label: "Tổng quan", icon: LayoutDashboard },
  { id: "results", label: "Kết quả dự đoán", icon: FlaskConical },
  { id: "compare", label: "So sánh Model", icon: GitCompare },
  { id: "history", label: "Lịch sử", icon: History },
  { id: "admin", label: "Quản trị", icon: Shield },
];

export function Sidebar({ active = "predict", onChange }: { active?: string; onChange?: (id: string) => void }) {
  return (
    <aside className="w-[240px] shrink-0 h-screen sticky top-0 flex flex-col border-r border-white/5 bg-white/[0.02] backdrop-blur-2xl">
      <div className="px-5 pt-7 pb-8">
        <div className="flex items-center gap-3">
          <div className="relative w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 grid place-items-center shadow-[0_0_28px_-4px_rgba(96,165,250,0.7)]">
            <Sparkles className="text-white" size={19} strokeWidth={2.5} />
            <span className="absolute inset-0 rounded-xl border border-white/20" />
          </div>
          <div className="flex flex-col leading-tight min-w-0">
            <span style={{ fontFamily: 'Space Grotesk, sans-serif', fontWeight: 700, fontSize: '15px', letterSpacing: '0.02em' }} className="text-white">
              AMNTDDA AI
            </span>
            <span style={{ fontFamily: 'Inter, sans-serif', fontSize: '10px', lineHeight: 1.3 }} className="text-white/40 truncate">
              Nền tảng GNN y sinh chính xác
            </span>
          </div>
        </div>
      </div>

      <nav className="flex-1 px-3 flex flex-col gap-1">
        {navItems.map((item) => {
          const isActive = item.id === active;
          const Icon = item.icon;
          return (
            <button
              key={item.id}
              onClick={() => onChange?.(item.id)}
              className={`relative group flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all overflow-hidden ${
                isActive
                  ? "text-white"
                  : "text-white/55 hover:text-white hover:bg-white/[0.04]"
              }`}
              style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', fontWeight: isActive ? 600 : 500 }}
            >
              {isActive && (
                <>
                  <span className="absolute inset-0 bg-gradient-to-r from-blue-500/25 via-purple-500/15 to-transparent" />
                  <span className="absolute inset-y-1 right-0 w-[2px] rounded-l-full bg-gradient-to-b from-blue-400 to-purple-500 shadow-[0_0_12px_2px_rgba(96,165,250,0.7)]" />
                  <span className="absolute inset-0 border border-white/10 rounded-xl" />
                </>
              )}
              <Icon size={17} strokeWidth={isActive ? 2.25 : 2} className="relative" />
              <span className="relative">{item.label}</span>
            </button>
          );
        })}
      </nav>

      <div className="p-3 border-t border-white/5 mt-2">
        <div className="flex items-center gap-2.5 p-2 rounded-2xl bg-white/[0.03] border border-white/5">
          <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 grid place-items-center text-white shrink-0" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '12px', fontWeight: 600 }}>
            DR
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-white truncate" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 600 }}>Dr. Nguyễn</div>
            <div className="text-white/40 truncate" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px' }}>nghien.cuu@lab.ai</div>
          </div>
          <button className="w-7 h-7 grid place-items-center rounded-lg text-white/40 hover:text-red-400 hover:bg-white/[0.05] transition-colors shrink-0" title="Đăng xuất">
            <LogOut size={14} strokeWidth={2} />
          </button>
        </div>
      </div>
    </aside>
  );
}
