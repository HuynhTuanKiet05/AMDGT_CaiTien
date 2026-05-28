import { useState } from "react";
import { Sparkles, TrendingUp, Beaker, Trash2, ExternalLink, Pill, HeartPulse, ChevronDown } from "lucide-react";
import { AssociationGraph } from "./AssociationGraph";
import { MoleculeCards } from "./MoleculeCards";

type Row = {
  id: string;
  drugId: string;
  drug: string;
  diseaseId: string;
  disease: string;
  improved: number;
  baseline: number;
};

const rows: Row[] = [
  { id: "1", drugId: "DB00659", drug: "Acamprosate", diseaseId: "C0001973", disease: "Alcohol Dependence", improved: 0.9412, baseline: 0.8167 },
  { id: "2", drugId: "DB00945", drug: "Aspirin", diseaseId: "C0018802", disease: "Heart Failure", improved: 0.9128, baseline: 0.8345 },
  { id: "3", drugId: "DB00331", drug: "Metformin", diseaseId: "C0011860", disease: "Diabetes Type 2", improved: 0.9687, baseline: 0.8954 },
  { id: "4", drugId: "DB01076", drug: "Atorvastatin", diseaseId: "C0010068", disease: "Coronary Artery", improved: 0.9234, baseline: 0.8521 },
  { id: "5", drugId: "DB00472", drug: "Fluoxetine", diseaseId: "C0011570", disease: "Depression", improved: 0.8956, baseline: 0.8923 },
  { id: "6", drugId: "DB00564", drug: "Carbamazepine", diseaseId: "C0014544", disease: "Epilepsy", improved: 0.9345, baseline: 0.8612 },
  { id: "7", drugId: "DB00563", drug: "Methotrexate", diseaseId: "C0003873", disease: "Rheumatoid Arthritis", improved: 0.9078, baseline: 0.9145 },
  { id: "8", drugId: "DB00641", drug: "Simvastatin", diseaseId: "C0020538", disease: "Hypertension", improved: 0.9512, baseline: 0.8734 },
];

export function ResultsPage() {
  const [topK, setTopK] = useState("Top 10");

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <section className="relative overflow-hidden rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-8 py-7">
        <div className="absolute -top-24 -left-16 w-96 h-96 rounded-full bg-emerald-500/15 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-32 right-1/4 w-96 h-96 rounded-full bg-blue-500/20 blur-3xl pointer-events-none" />

        <div className="relative flex items-start justify-between gap-6 flex-wrap">
          <div className="flex items-start gap-4 max-w-2xl">
            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-400/30 to-blue-500/30 border border-emerald-300/30 grid place-items-center text-emerald-300 shadow-[0_0_28px_-4px_rgba(74,222,128,0.6)] shrink-0">
              <Sparkles size={22} strokeWidth={2} />
            </div>
            <div className="flex flex-col gap-2">
              <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_2px_rgba(74,222,128,0.7)]" />
                <span className="text-white/65" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 500, letterSpacing: '0.1em' }}>
                  KẾT QUẢ DỰ ĐOÁN · HGT v2.1
                </span>
              </div>
              <h1 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '32px', fontWeight: 700, letterSpacing: '-0.02em', lineHeight: 1.1 }}>
                Kết quả dự đoán liên kết
              </h1>
              <p className="text-white/60" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', lineHeight: 1.6 }}>
                So sánh điểm dự đoán giữa mô hình HGT cải tiến và baseline gốc — kèm cấu trúc phân tử SMILES và đồ thị benchmark trực quan.
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Legend color="#4ade80" label="Improved GNN" />
            <Legend color="#2563eb" label="Baseline HGT" />
            <div className="relative">
              <button className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white hover:bg-white/[0.07] transition-colors" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 500 }}>
                {topK}
                <ChevronDown size={13} className="text-white/50" />
              </button>
            </div>
          </div>
        </div>
      </section>

      {/* Score Matrix Table */}
      <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-xl bg-emerald-500/15 border border-emerald-500/25 grid place-items-center text-emerald-300">
              <TrendingUp size={14} />
            </div>
            <div>
              <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
                Prediction Score Matrix
              </h2>
              <p className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px' }}>
                {rows.length} cặp Thuốc–Bệnh · sắp xếp theo Δ giảm dần
              </p>
            </div>
          </div>
          <span className="px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/25 text-emerald-300" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', fontWeight: 600 }}>
            AVG Δ +0.0727
          </span>
        </div>

        <div className="overflow-x-auto rounded-2xl border border-white/[0.06] bg-black/30">
          <table className="w-full border-collapse">
            <thead>
              <tr className="bg-white/[0.03] border-b border-white/[0.08]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 600, letterSpacing: '0.1em' }}>
                <th className="text-left px-5 py-3 text-white/45">DRUG ENTITY</th>
                <th className="text-left px-5 py-3 text-white/45">DISEASE ENTITY</th>
                <th className="text-right px-5 py-3 text-emerald-300/80">IMPROVED GNN</th>
                <th className="text-right px-5 py-3 text-blue-300/80">BASELINE HGT</th>
                <th className="text-right px-5 py-3 text-white/45">DELTA (Δ)</th>
                <th className="text-right px-5 py-3 text-white/45 w-[100px]"> </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const delta = r.improved - r.baseline;
                const positive = delta > 0;
                return (
                  <tr key={r.id} className="group relative border-b border-white/[0.04] last:border-0 hover:bg-gradient-to-r hover:from-emerald-500/[0.04] hover:via-transparent hover:to-blue-500/[0.04] transition-all">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2.5">
                        <div className="w-7 h-7 rounded-lg bg-blue-500/15 border border-blue-500/25 grid place-items-center text-blue-300 shrink-0">
                          <Pill size={12} />
                        </div>
                        <div className="flex flex-col">
                          <span className="text-white" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13px', fontWeight: 600 }}>{r.drug}</span>
                          <span className="text-white/40" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>{r.drugId}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2.5">
                        <div className="w-7 h-7 rounded-lg bg-purple-500/15 border border-purple-500/25 grid place-items-center text-purple-300 shrink-0">
                          <HeartPulse size={12} />
                        </div>
                        <div className="flex flex-col">
                          <span className="text-white" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13px', fontWeight: 600 }}>{r.disease}</span>
                          <span className="text-white/40" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>{r.diseaseId}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5 text-right tabular-nums">
                      <ScoreCell value={r.improved} tone="emerald" />
                    </td>
                    <td className="px-5 py-3.5 text-right tabular-nums">
                      <ScoreCell value={r.baseline} tone="blue" />
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <DeltaTag value={delta} positive={positive} />
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <div className="inline-flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button className="w-7 h-7 grid place-items-center rounded-lg text-white/50 hover:text-white hover:bg-white/[0.06]">
                          <ExternalLink size={13} />
                        </button>
                        <button className="w-7 h-7 grid place-items-center rounded-lg text-white/50 hover:text-red-300 hover:bg-red-500/10">
                          <Trash2 size={13} />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>

      {/* Comparison Bar Chart */}
      <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
        <div className="absolute -top-32 right-1/4 w-96 h-96 rounded-full bg-blue-500/10 blur-3xl pointer-events-none" />

        <div className="relative flex items-center justify-between mb-5">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-xl bg-blue-500/15 border border-blue-500/25 grid place-items-center text-blue-300">
              <Beaker size={14} />
            </div>
            <div>
              <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
                Model Comparison
              </h2>
              <p className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px' }}>
                Improved GNN vs. Original HGT trên các cặp Top-K
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Legend color="#4ade80" label="Improved GNN" />
            <Legend color="#2563eb" label="Original HGT" />
          </div>
        </div>

        <BarChart rows={rows} />
      </section>

      <AssociationGraph />
      <MoleculeCards />
    </div>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/70" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}>
      <span className="w-2 h-2 rounded-full" style={{ background: color, boxShadow: `0 0 8px ${color}` }} />
      {label}
    </span>
  );
}

function ScoreCell({ value, tone }: { value: number; tone: "emerald" | "blue" }) {
  const colorClass = tone === "emerald" ? "text-emerald-300" : "text-blue-300";
  const barColor = tone === "emerald" ? "from-emerald-400 to-emerald-500" : "from-blue-400 to-blue-600";
  return (
    <div className="inline-flex flex-col items-end gap-1 min-w-[80px]">
      <span className={colorClass} style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '13px', fontWeight: 700 }}>
        {value.toFixed(4)}
      </span>
      <span className="block w-full h-[3px] rounded-full bg-white/[0.05] overflow-hidden">
        <span className={`block h-full bg-gradient-to-r ${barColor}`} style={{ width: `${value * 100}%` }} />
      </span>
    </div>
  );
}

function DeltaTag({ value, positive }: { value: number; positive: boolean }) {
  if (!positive || Math.abs(value) < 0.001) {
    return (
      <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-white/[0.04] border border-white/[0.08] text-slate-400" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px', fontWeight: 600 }}>
        {value >= 0 ? "+" : ""}{value.toFixed(4)}
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-400/40 text-emerald-300 shadow-[0_0_14px_-3px_rgba(74,222,128,0.7)]" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px', fontWeight: 700 }}>
      <span className="w-1 h-1 rounded-full bg-emerald-300 shadow-[0_0_6px_rgba(74,222,128,0.9)]" />
      +{value.toFixed(4)}
    </span>
  );
}

function BarChart({ rows }: { rows: Row[] }) {
  const W = 1000;
  const rowH = 44;
  const padL = 180;
  const padR = 80;
  const chartW = W - padL - padR;
  const H = rows.length * rowH + 30;

  return (
    <div className="relative w-full overflow-x-auto">
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ minWidth: 720 }} preserveAspectRatio="xMidYMid meet">
        <defs>
          <linearGradient id="bImproved" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="#10b981" stopOpacity="0.7" />
            <stop offset="100%" stopColor="#4ade80" stopOpacity="1" />
          </linearGradient>
          <linearGradient id="bBaseline" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="#1d4ed8" stopOpacity="0.7" />
            <stop offset="100%" stopColor="#3b82f6" stopOpacity="1" />
          </linearGradient>
          <filter id="bGlow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="3" result="blur" />
            <feMerge>
              <feMergeNode in="blur" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>

        {/* gridlines */}
        {[0, 0.25, 0.5, 0.75, 1].map((g) => (
          <g key={g}>
            <line x1={padL + g * chartW} y1={10} x2={padL + g * chartW} y2={H - 14} stroke="rgba(255,255,255,0.04)" strokeDasharray="2 4" />
            <text x={padL + g * chartW} y={H - 2} textAnchor="middle" fill="rgba(255,255,255,0.3)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9 }}>
              {g.toFixed(2)}
            </text>
          </g>
        ))}

        {rows.map((r, i) => {
          const y = i * rowH + 14;
          const improvedW = r.improved * chartW;
          const baselineW = r.baseline * chartW;
          return (
            <g key={r.id}>
              {/* label */}
              <text x={padL - 12} y={y + 18} textAnchor="end" fill="rgba(255,255,255,0.7)" style={{ fontFamily: 'Inter, sans-serif', fontSize: 11.5, fontWeight: 500 }}>
                {r.drug}
              </text>
              <text x={padL - 12} y={y + 30} textAnchor="end" fill="rgba(255,255,255,0.3)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9 }}>
                {r.drugId}
              </text>

              {/* baseline (back, thinner, lighter) */}
              <rect x={padL} y={y + 4} width={baselineW} height={10} rx={5} fill="url(#bBaseline)" opacity="0.85" filter="url(#bGlow)" />
              <text x={padL + baselineW + 8} y={y + 13} fill="#93c5fd" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9.5, fontWeight: 600 }}>
                {(r.baseline * 100).toFixed(2)}%
              </text>

              {/* improved */}
              <rect x={padL} y={y + 18} width={improvedW} height={10} rx={5} fill="url(#bImproved)" filter="url(#bGlow)" />
              <text x={padL + improvedW + 8} y={y + 27} fill="#86efac" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9.5, fontWeight: 700 }}>
                {(r.improved * 100).toFixed(2)}%
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

