import { useState } from "react";
import {
  Shield, Pill, HeartPulse, Dna, BarChart3, ChevronDown, Database,
  Pencil, ArrowRight, Network, ArrowUpRight, CheckCircle2
} from "lucide-react";

type Kind = "drug" | "disease" | "pair";

const recent = [
  { id: "RX-00184", kind: "drug" as Kind, query: "Metformin → Diabetes Type 2", topK: 10, time: "14:32" },
  { id: "RX-00183", kind: "pair" as Kind, query: "Atorvastatin → CAD", topK: 15, time: "14:18" },
  { id: "RX-00182", kind: "disease" as Kind, query: "Alzheimer's", topK: 20, time: "11:47" },
  { id: "RX-00181", kind: "drug" as Kind, query: "Sertraline → Depression", topK: 5, time: "22:09" },
  { id: "RX-00180", kind: "drug" as Kind, query: "Lisinopril → Hypertension", topK: 10, time: "18:55" },
  { id: "RX-00179", kind: "pair" as Kind, query: "Warfarin → Stroke", topK: 10, time: "16:21" },
  { id: "RX-00178", kind: "disease" as Kind, query: "Parkinson's", topK: 15, time: "09:14" },
];

const datasets: Record<string, { drugs: number; diseases: number; proteins: number; links: number; predictions: number }> = {
  "B-dataset": { drugs: 663, diseases: 409, proteins: 5642, links: 2532, predictions: 18437 },
  "C-dataset": { drugs: 894, diseases: 521, proteins: 7128, links: 3814, predictions: 12092 },
  "F-dataset": { drugs: 1142, diseases: 612, proteins: 8451, links: 4267, predictions: 7654 },
};

export function AdminPage() {
  const [ds, setDs] = useState<keyof typeof datasets>("B-dataset");
  const [open, setOpen] = useState(false);
  const stats = datasets[ds];

  return (
    <div className="flex flex-col gap-6">
      {/* Header banner */}
      <section className="relative overflow-hidden rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-8 py-8">
        <div className="absolute -top-24 -left-16 w-96 h-96 rounded-full bg-purple-500/25 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-32 right-1/4 w-96 h-96 rounded-full bg-fuchsia-500/15 blur-3xl pointer-events-none" />

        <div className="relative flex items-start justify-between gap-6 flex-wrap">
          <div className="flex items-start gap-4 max-w-2xl">
            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500/30 to-fuchsia-500/30 border border-purple-300/30 grid place-items-center text-purple-300 shadow-[0_0_28px_-4px_rgba(168,85,247,0.7)] shrink-0">
              <Shield size={22} strokeWidth={2} />
            </div>
            <div className="flex flex-col gap-2">
              <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-purple-500/12 border border-purple-400/25 w-fit">
                <span className="w-1.5 h-1.5 rounded-full bg-purple-300 shadow-[0_0_8px_2px_rgba(192,132,252,0.7)]" />
                <span className="text-purple-200" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 600, letterSpacing: '0.1em' }}>
                  ADMIN · QUYỀN HẠN CAO
                </span>
              </div>
              <h1 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '32px', fontWeight: 700, letterSpacing: '-0.02em', lineHeight: 1.1 }}>
                Khu vực quản trị
              </h1>
              <p className="text-white/60" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', lineHeight: 1.6 }}>
                Quản lý dữ liệu nguồn thuốc, bệnh và liên kết tri thức — kiểm soát phiên bản dataset đang được mô hình HGT sử dụng.
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <AdminAction icon={<Pill size={14} />} label="Quản lý Thuốc" tone="blue" />
            <AdminAction icon={<HeartPulse size={14} />} label="Quản lý Bệnh" tone="purple" />
            <AdminAction icon={<Network size={14} />} label="Quản lý Liên kết" tone="cyan" />
          </div>
        </div>
      </section>

      {/* Dataset selector */}
      <section className="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-white/[0.04] border border-white/[0.08] grid place-items-center text-blue-300">
            <Database size={15} />
          </div>
          <div className="flex flex-col">
            <span className="text-white/50" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', letterSpacing: '0.06em' }}>
              DATASET ĐANG HOẠT ĐỘNG
            </span>
            <span className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '14px', fontWeight: 600 }}>
              Chọn nguồn dữ liệu chính
            </span>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/12 border border-emerald-500/25 text-emerald-300" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600 }}>
            <CheckCircle2 size={11} />
            Đang sử dụng
          </span>

          <div className="relative">
            <button
              onClick={() => setOpen((o) => !o)}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-500/20 to-purple-500/20 border border-blue-400/30 text-white shadow-[0_0_24px_-6px_rgba(96,165,250,0.5)] hover:shadow-[0_0_28px_-4px_rgba(139,92,246,0.6)] transition-all"
              style={{ fontFamily: 'Inter, sans-serif', fontSize: '13px', fontWeight: 600 }}
            >
              {ds}
              <ChevronDown size={14} className={`transition-transform ${open ? "rotate-180" : ""}`} />
            </button>
            {open && (
              <div className="absolute right-0 top-full mt-2 z-10 min-w-[180px] rounded-xl bg-[#0f0f17]/95 border border-white/[0.08] backdrop-blur-2xl p-1 shadow-2xl">
                {(Object.keys(datasets) as (keyof typeof datasets)[]).map((k) => (
                  <button
                    key={k}
                    onClick={() => { setDs(k); setOpen(false); }}
                    className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-left transition-colors ${
                      k === ds ? "bg-blue-500/15 text-blue-200" : "text-white/70 hover:bg-white/[0.05] hover:text-white"
                    }`}
                    style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 500 }}
                  >
                    {k}
                    {k === ds && <CheckCircle2 size={12} />}
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>
      </section>

      {/* Stats grid */}
      <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <StatCard icon={<Pill size={18} />} label="Tổng số thuốc" value={stats.drugs.toLocaleString()} delta="+12 tháng này" tone="blue" />
        <StatCard icon={<HeartPulse size={18} />} label="Tổng số bệnh" value={stats.diseases.toLocaleString()} delta="+5 tháng này" tone="red" />
        <StatCard icon={<Dna size={18} />} label="Tổng số protein" value={stats.proteins.toLocaleString()} delta="+128 tuần này" tone="yellow" />
        <StatCard icon={<BarChart3 size={18} />} label="Tổng lượt chẩn đoán" value={stats.predictions.toLocaleString()} delta="+847 hôm nay" tone="green" />
      </section>

      {/* Two-column */}
      <section className="grid grid-cols-1 lg:grid-cols-[1fr_400px] gap-5">
        {/* Recent diagnoses */}
        <div className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
              Lượt chẩn đoán gần đây
            </h2>
            <button className="inline-flex items-center gap-1 text-blue-300 hover:text-blue-200" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px', fontWeight: 500 }}>
              Xem tất cả <ArrowUpRight size={12} />
            </button>
          </div>
          <div className="rounded-2xl border border-white/[0.06] bg-black/30 overflow-hidden">
            <table className="w-full border-collapse">
              <thead>
                <tr className="bg-white/[0.03] border-b border-white/[0.08]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 600, letterSpacing: '0.1em' }}>
                  <th className="text-left px-4 py-2.5 text-white/45">ID</th>
                  <th className="text-left px-4 py-2.5 text-white/45">TYPE</th>
                  <th className="text-left px-4 py-2.5 text-white/45">TRUY VẤN</th>
                  <th className="text-center px-4 py-2.5 text-white/45">TOP-K</th>
                  <th className="text-right px-4 py-2.5 text-white/45">THỜI GIAN</th>
                </tr>
              </thead>
              <tbody>
                {recent.map((r) => (
                  <tr key={r.id} className="border-b border-white/[0.04] last:border-0 hover:bg-white/[0.025] transition-colors">
                    <td className="px-4 py-2.5 text-white/55" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px' }}>{r.id}</td>
                    <td className="px-4 py-2.5"><KindBadge kind={r.kind} /></td>
                    <td className="px-4 py-2.5 text-white" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 500 }}>{r.query}</td>
                    <td className="px-4 py-2.5 text-center">
                      <span className="inline-flex items-center justify-center min-w-[32px] px-2 py-0.5 rounded-md bg-white/[0.05] border border-white/[0.08] text-white/75" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11px', fontWeight: 600 }}>
                        {r.topK}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-right text-white/50" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11px' }}>{r.time}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Data system panel */}
        <div className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
          <div className="absolute -top-20 -right-16 w-64 h-64 rounded-full bg-cyan-500/15 blur-3xl pointer-events-none" />
          <div className="absolute -bottom-24 left-1/4 w-64 h-64 rounded-full bg-purple-500/15 blur-3xl pointer-events-none" />

          <div className="relative flex items-center gap-2 mb-4">
            <Network size={15} className="text-cyan-300" />
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
              Hệ thống dữ liệu
            </h2>
          </div>

          <div className="relative rounded-2xl bg-gradient-to-br from-blue-500/10 via-purple-500/10 to-cyan-500/10 border border-white/[0.08] p-5">
            <div className="flex items-center justify-between mb-2">
              <span className="text-white/55" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px', letterSpacing: '0.06em' }}>
                TỔNG SỐ LIÊN KẾT
              </span>
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-cyan-500/15 border border-cyan-500/25 text-cyan-300" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', fontWeight: 600 }}>
                {ds}
              </span>
            </div>
            <div className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '44px', fontWeight: 700, letterSpacing: '-0.03em', lineHeight: 1 }}>
              {stats.links.toLocaleString()}
            </div>
            <p className="text-white/50 mt-2" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px', lineHeight: 1.55 }}>
              Đồ thị tri thức hiện tại chứa các cặp Thuốc–Bệnh đã được kiểm chứng và sử dụng để huấn luyện mô hình HGT.
            </p>
          </div>

          <div className="relative grid grid-cols-2 gap-3 mt-4">
            <MiniStat label="Cặp đã kiểm chứng" value={Math.round(stats.links * 0.92).toLocaleString()} tone="green" />
            <MiniStat label="Đang đánh giá" value={Math.round(stats.links * 0.08).toLocaleString()} tone="yellow" />
          </div>

          <button className="relative mt-5 w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_28px_-6px_rgba(96,165,250,0.7)] hover:shadow-[0_12px_36px_-4px_rgba(139,92,246,0.8)] transition-all" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', fontWeight: 600 }}>
            Quản lý liên kết ngay
            <ArrowRight size={14} />
          </button>
        </div>
      </section>
    </div>
  );
}

function AdminAction({ icon, label, tone }: { icon: React.ReactNode; label: string; tone: "blue" | "purple" | "cyan" }) {
  const toneCls = tone === "blue"
    ? "from-blue-500/20 to-blue-600/10 border-blue-400/30 hover:shadow-[0_0_20px_-6px_rgba(96,165,250,0.7)] text-blue-200"
    : tone === "purple"
      ? "from-purple-500/20 to-purple-600/10 border-purple-400/30 hover:shadow-[0_0_20px_-6px_rgba(168,85,247,0.7)] text-purple-200"
      : "from-cyan-500/20 to-cyan-600/10 border-cyan-400/30 hover:shadow-[0_0_20px_-6px_rgba(34,211,238,0.7)] text-cyan-200";
  return (
    <button
      className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-gradient-to-br ${toneCls} border transition-all`}
      style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 600 }}
    >
      {icon}
      {label}
      <Pencil size={11} className="opacity-50" />
    </button>
  );
}

function StatCard({ icon, label, value, delta, tone }: { icon: React.ReactNode; label: string; value: string; delta: string; tone: "blue" | "red" | "yellow" | "green" }) {
  const map = {
    blue: { gradient: "from-blue-500/30 to-blue-500/0", icon: "bg-blue-500/15 text-blue-300 border-blue-500/25", glow: "rgba(96,165,250,0.4)" },
    red: { gradient: "from-rose-500/30 to-rose-500/0", icon: "bg-rose-500/15 text-rose-300 border-rose-500/25", glow: "rgba(244,63,94,0.4)" },
    yellow: { gradient: "from-amber-500/30 to-amber-500/0", icon: "bg-amber-500/15 text-amber-300 border-amber-500/25", glow: "rgba(251,191,36,0.4)" },
    green: { gradient: "from-emerald-500/30 to-emerald-500/0", icon: "bg-emerald-500/15 text-emerald-300 border-emerald-500/25", glow: "rgba(52,211,153,0.4)" },
  }[tone];
  return (
    <div className="relative rounded-[20px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-5 overflow-hidden group hover:border-white/[0.12] transition-colors">
      <div className={`absolute -top-12 -right-12 w-40 h-40 rounded-full bg-gradient-to-br ${map.gradient} blur-2xl pointer-events-none`} />
      <div className={`absolute top-3 right-3 w-2 h-2 rounded-full`} style={{ background: map.glow, boxShadow: `0 0 12px ${map.glow}` }} />
      <div className="relative flex items-start justify-between mb-4">
        <div className={`w-10 h-10 rounded-xl border grid place-items-center ${map.icon}`}>
          {icon}
        </div>
      </div>
      <div className="relative text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '30px', fontWeight: 700, letterSpacing: '-0.025em', lineHeight: 1 }}>
        {value}
      </div>
      <div className="relative mt-1 text-white/55" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px' }}>
        {label}
      </div>
      <div className="relative mt-3 inline-flex items-center gap-1 text-emerald-300/80" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', fontWeight: 500 }}>
        <ArrowUpRight size={10} />
        {delta}
      </div>
    </div>
  );
}

function MiniStat({ label, value, tone }: { label: string; value: string; tone: "green" | "yellow" }) {
  const c = tone === "green" ? "text-emerald-300" : "text-amber-300";
  const dot = tone === "green" ? "bg-emerald-400" : "bg-amber-400";
  return (
    <div className="rounded-xl bg-black/30 border border-white/[0.06] p-3">
      <div className="flex items-center gap-1.5 mb-1">
        <span className={`w-1.5 h-1.5 rounded-full ${dot}`} />
        <span className="text-white/50" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px' }}>{label}</span>
      </div>
      <div className={c} style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '17px', fontWeight: 700, letterSpacing: '-0.01em' }}>
        {value}
      </div>
    </div>
  );
}

function KindBadge({ kind }: { kind: Kind }) {
  const map = {
    drug: { label: "Thuốc", cls: "bg-blue-500/15 text-blue-300 border-blue-500/25" },
    disease: { label: "Bệnh", cls: "bg-purple-500/15 text-purple-300 border-purple-500/25" },
    pair: { label: "Cặp", cls: "bg-cyan-500/15 text-cyan-300 border-cyan-500/25" },
  }[kind];
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full border ${map.cls}`} style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 600 }}>
      {map.label}
    </span>
  );
}
