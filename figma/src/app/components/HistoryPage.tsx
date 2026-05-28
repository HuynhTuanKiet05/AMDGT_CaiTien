import { useMemo, useState } from "react";
import { History, Search, Filter, Download, CheckCircle2, Pill, HeartPulse, Inbox, ArrowRight } from "lucide-react";

type Kind = "drug" | "disease" | "pair";
type Record = {
  id: string;
  kind: Kind;
  query: string;
  subquery?: string;
  topK: number;
  status: "success" | "running" | "failed";
  time: string;
  dataset: string;
};

const records: Record[] = [
  { id: "RX-00184", kind: "drug", query: "Metformin", subquery: "Diabetes Type 2", topK: 10, status: "success", time: "25/05/2026 · 14:32", dataset: "B-dataset" },
  { id: "RX-00183", kind: "pair", query: "Atorvastatin", subquery: "Coronary Artery Disease", topK: 15, status: "success", time: "25/05/2026 · 14:18", dataset: "B-dataset" },
  { id: "RX-00182", kind: "disease", query: "Alzheimer's", topK: 20, status: "success", time: "25/05/2026 · 11:47", dataset: "C-dataset" },
  { id: "RX-00181", kind: "drug", query: "Sertraline", subquery: "Depression", topK: 5, status: "success", time: "24/05/2026 · 22:09", dataset: "B-dataset" },
  { id: "RX-00180", kind: "drug", query: "Lisinopril", subquery: "Hypertension", topK: 10, status: "success", time: "24/05/2026 · 18:55", dataset: "F-dataset" },
  { id: "RX-00179", kind: "pair", query: "Warfarin", subquery: "Stroke", topK: 10, status: "success", time: "24/05/2026 · 16:21", dataset: "B-dataset" },
  { id: "RX-00178", kind: "disease", query: "Parkinson's", topK: 15, status: "success", time: "23/05/2026 · 09:14", dataset: "C-dataset" },
  { id: "RX-00177", kind: "drug", query: "Gabapentin", subquery: "Epilepsy", topK: 10, status: "success", time: "22/05/2026 · 20:42", dataset: "B-dataset" },
  { id: "RX-00176", kind: "drug", query: "Levothyroxine", subquery: "Hypothyroidism", topK: 5, status: "success", time: "22/05/2026 · 13:28", dataset: "F-dataset" },
  { id: "RX-00175", kind: "pair", query: "Aspirin", subquery: "Heart Failure", topK: 20, status: "success", time: "21/05/2026 · 10:05", dataset: "B-dataset" },
];

export function HistoryPage() {
  const [q, setQ] = useState("");
  const [showEmpty, setShowEmpty] = useState(false);

  const filtered = useMemo(() => {
    if (showEmpty) return [];
    if (!q) return records;
    const lower = q.toLowerCase();
    return records.filter((r) =>
      r.query.toLowerCase().includes(lower) ||
      r.subquery?.toLowerCase().includes(lower) ||
      r.id.toLowerCase().includes(lower)
    );
  }, [q, showEmpty]);

  return (
    <div className="flex flex-col gap-6">
      {/* Header banner */}
      <section className="relative overflow-hidden rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-8 py-10">
        <div className="absolute -top-24 -left-16 w-96 h-96 rounded-full bg-blue-500/20 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-32 right-1/4 w-96 h-96 rounded-full bg-purple-500/15 blur-3xl pointer-events-none" />

        {/* tiny background dots */}
        <svg className="absolute inset-0 w-full h-full pointer-events-none opacity-30" viewBox="0 0 800 240" preserveAspectRatio="xMidYMid slice">
          <defs>
            <linearGradient id="hxLine" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="#60a5fa" stopOpacity="0" />
              <stop offset="50%" stopColor="#60a5fa" stopOpacity="0.5" />
              <stop offset="100%" stopColor="#8b5cf6" stopOpacity="0" />
            </linearGradient>
          </defs>
          <g stroke="url(#hxLine)" strokeWidth="1" fill="none">
            <path d="M 0 160 Q 200 120 400 150 T 800 130" />
            <path d="M 0 80 Q 180 110 380 70 T 800 90" />
          </g>
          <g fill="#93c5fd" opacity="0.7">
            <circle cx="200" cy="135" r="2" />
            <circle cx="500" cy="140" r="2" />
            <circle cx="700" cy="120" r="2" />
            <circle cx="120" cy="92" r="2" />
            <circle cx="420" cy="78" r="2" />
          </g>
        </svg>

        <div className="relative flex items-start gap-4 max-w-3xl">
          <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500/30 to-purple-600/30 border border-white/10 grid place-items-center text-blue-300 shadow-[0_0_24px_-4px_rgba(96,165,250,0.6)] shrink-0">
            <History size={22} strokeWidth={2} />
          </div>
          <div className="flex flex-col gap-2">
            <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
              <span className="w-1.5 h-1.5 rounded-full bg-blue-400 shadow-[0_0_8px_2px_rgba(96,165,250,0.7)]" />
              <span className="text-white/65" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 500, letterSpacing: '0.1em' }}>
                LỊCH SỬ · NHẬT KÝ HỆ THỐNG
              </span>
            </div>
            <h1 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '32px', fontWeight: 700, letterSpacing: '-0.02em', lineHeight: 1.1 }}>
              Lịch sử tra cứu
            </h1>
            <p className="text-white/60" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', lineHeight: 1.6 }}>
              Lưu lại toàn bộ các phiên chẩn đoán đã thực hiện — bao gồm thuốc, bệnh, top-K kết quả và trạng thái xử lý.
            </p>
          </div>
        </div>

        <div className="relative mt-6 flex items-center gap-2 flex-wrap">
          <Stat label="Tổng phiên" value="184" />
          <Stat label="Tuần này" value="27" tone="blue" />
          <Stat label="Thành công" value="99.4%" tone="green" />
          <button
            onClick={() => setShowEmpty((s) => !s)}
            className="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/55 hover:text-white hover:bg-white/[0.07] transition-colors"
            style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px', fontWeight: 500 }}
          >
            {showEmpty ? "Hiển thị dữ liệu" : "Xem trạng thái rỗng"}
          </button>
        </div>
      </section>

      {/* Table card */}
      <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
        <div className="flex items-center justify-between gap-4 flex-wrap mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '17px', fontWeight: 600, letterSpacing: '-0.01em' }}>
              Nhật ký chẩn đoán
            </h2>
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-500/15 border border-blue-500/25 text-blue-300" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11px', fontWeight: 600 }}>
              {filtered.length} bản ghi
            </span>
          </div>
          <div className="flex items-center gap-2">
            <div className="relative">
              <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
              <input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Tìm theo thuốc, bệnh hoặc ID..."
                className="w-[260px] pl-8 pr-3 py-2 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white placeholder:text-white/30"
                style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px' }}
              />
            </div>
            <button className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07] transition-colors" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px', fontWeight: 500 }}>
              <Filter size={13} /> Lọc
            </button>
            <button className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07] transition-colors" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px', fontWeight: 500 }}>
              <Download size={13} /> Xuất CSV
            </button>
          </div>
        </div>

        <div className="rounded-2xl border border-white/[0.06] bg-black/30 overflow-hidden">
          {filtered.length === 0 ? (
            <EmptyState />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full border-collapse">
                <thead>
                  <tr className="bg-white/[0.03] border-b border-white/[0.08]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600, letterSpacing: '0.1em' }}>
                    <Th>ID</Th>
                    <Th>KIỂU TRA CỨU</Th>
                    <Th>TRUY VẤN</Th>
                    <Th align="center">TOP-K</Th>
                    <Th align="center">TRẠNG THÁI</Th>
                    <Th align="right">THỜI GIAN</Th>
                    <Th align="right"> </Th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((r) => (
                    <tr key={r.id} className="group border-b border-white/[0.04] last:border-0 hover:bg-white/[0.025] transition-colors">
                      <td className="px-5 py-3.5 text-white/55" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '12px', fontWeight: 500 }}>
                        {r.id}
                      </td>
                      <td className="px-5 py-3.5">
                        <KindBadge kind={r.kind} />
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex flex-col">
                          <span className="text-white" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', fontWeight: 600 }}>
                            {r.query}
                          </span>
                          {r.subquery && (
                            <span className="text-white/45 inline-flex items-center gap-1" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px' }}>
                              <ArrowRight size={10} className="text-white/30" />
                              {r.subquery}
                              <span className="ml-2 text-white/30" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>· {r.dataset}</span>
                            </span>
                          )}
                          {!r.subquery && (
                            <span className="text-white/40" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>{r.dataset}</span>
                          )}
                        </div>
                      </td>
                      <td className="px-5 py-3.5 text-center">
                        <span className="inline-flex items-center justify-center min-w-[36px] px-2 py-0.5 rounded-md bg-white/[0.05] border border-white/[0.08] text-white/75" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px', fontWeight: 600 }}>
                          {r.topK}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 text-center">
                        <StatusBadge status={r.status} />
                      </td>
                      <td className="px-5 py-3.5 text-right text-white/55" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px' }}>
                        {r.time}
                      </td>
                      <td className="px-5 py-3.5 text-right">
                        <button className="opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white/[0.05] border border-white/[0.08] text-white/70 hover:text-white hover:bg-white/[0.1]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}>
                          Xem
                          <ArrowRight size={11} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <div className="mt-4 flex items-center justify-between flex-wrap gap-3 text-white/40" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px' }}>
          <span>Hiển thị {filtered.length} / {records.length} bản ghi gần nhất</span>
          <span className="inline-flex items-center gap-3">
            <KindLegend />
          </span>
        </div>
      </section>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "blue" | "green" }) {
  const valueColor = tone === "blue" ? "text-blue-300" : tone === "green" ? "text-emerald-300" : "text-white";
  return (
    <div className="inline-flex items-baseline gap-2 px-3.5 py-2 rounded-xl bg-white/[0.04] border border-white/[0.08]">
      <span className={valueColor} style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '15px', fontWeight: 700, letterSpacing: '-0.01em' }}>
        {value}
      </span>
      <span className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px' }}>
        {label}
      </span>
    </div>
  );
}

function Th({ children, align = "left" }: { children: React.ReactNode; align?: "left" | "center" | "right" }) {
  const cls = align === "right" ? "text-right" : align === "center" ? "text-center" : "text-left";
  return <th className={`${cls} px-5 py-3 text-white/50`}>{children}</th>;
}

function KindBadge({ kind }: { kind: Kind }) {
  const map = {
    drug: { label: "Thuốc", icon: <Pill size={10} />, cls: "bg-blue-500/15 text-blue-300 border-blue-500/25" },
    disease: { label: "Bệnh", icon: <HeartPulse size={10} />, cls: "bg-purple-500/15 text-purple-300 border-purple-500/25" },
    pair: { label: "Cặp", icon: <ArrowRight size={10} />, cls: "bg-cyan-500/15 text-cyan-300 border-cyan-500/25" },
  }[kind];
  return (
    <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full border ${map.cls}`} style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600 }}>
      {map.icon}
      {map.label}
    </span>
  );
}

function StatusBadge({ status }: { status: "success" | "running" | "failed" }) {
  if (status === "success") {
    return (
      <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/12 text-emerald-300 border border-emerald-500/25" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600 }}>
        <CheckCircle2 size={11} />
        Thành công
      </span>
    );
  }
  if (status === "running") {
    return (
      <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-blue-500/12 text-blue-300 border border-blue-500/25" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600 }}>
        <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse" />
        Đang xử lý
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-red-500/12 text-red-300 border border-red-500/25" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 600 }}>
      Thất bại
    </span>
  );
}

function EmptyState() {
  return (
    <div className="py-20 flex flex-col items-center justify-center text-center px-6">
      <div className="relative w-16 h-16 rounded-2xl bg-white/[0.04] border border-white/[0.08] grid place-items-center text-white/40 mb-4">
        <Inbox size={26} strokeWidth={1.5} />
        <span className="absolute inset-0 rounded-2xl bg-gradient-to-br from-blue-500/10 to-purple-500/10 blur-xl -z-10" />
      </div>
      <h3 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
        Chưa có lịch sử tra cứu
      </h3>
      <p className="text-white/45 mt-1.5 max-w-sm" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', lineHeight: 1.55 }}>
        Các phiên chẩn đoán bạn thực hiện sẽ xuất hiện tại đây. Hãy bắt đầu bằng cách tạo một dự đoán mới.
      </p>
      <button className="mt-5 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_24px_-8px_rgba(96,165,250,0.7)]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 600 }}>
        Tạo dự đoán mới
        <ArrowRight size={13} />
      </button>
    </div>
  );
}

function KindLegend() {
  return (
    <span className="inline-flex items-center gap-3">
      <span className="inline-flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-blue-400" /> Thuốc</span>
      <span className="inline-flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-purple-400" /> Bệnh</span>
      <span className="inline-flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-cyan-400" /> Cặp</span>
    </span>
  );
}
