import { useMemo, useState } from "react";
import { Trophy, TrendingUp, FileCode2, Sparkles } from "lucide-react";

type Row = { fold: string; base: [number, number, number]; imp: [number, number, number] };

const data: Record<string, Row[]> = {
  B: [
    { fold: "Fold 0", base: [0.9412, 0.8124, 0.8631], imp: [0.9587, 0.8412, 0.8842] },
    { fold: "Fold 1", base: [0.9356, 0.8067, 0.8574], imp: [0.9531, 0.8378, 0.8798] },
    { fold: "Fold 2", base: [0.9478, 0.8211, 0.8702], imp: [0.9624, 0.8501, 0.8911] },
    { fold: "Fold 3", base: [0.9391, 0.8098, 0.8612], imp: [0.9572, 0.8403, 0.8825] },
    { fold: "Fold 4", base: [0.9445, 0.8156, 0.8668], imp: [0.9601, 0.8467, 0.8876] },
    { fold: "Fold 5", base: [0.9402, 0.8112, 0.8625], imp: [0.9568, 0.8421, 0.8838] },
    { fold: "Fold 6", base: [0.9421, 0.8134, 0.8647], imp: [0.9593, 0.8445, 0.8864] },
    { fold: "Fold 7", base: [0.9387, 0.8089, 0.8603], imp: [0.9559, 0.8398, 0.8819] },
    { fold: "Fold 8", base: [0.9434, 0.8145, 0.8659], imp: [0.9612, 0.8478, 0.8889] },
    { fold: "Fold 9", base: [0.9408, 0.8121, 0.8638], imp: [0.9581, 0.8434, 0.8852] },
  ],
  C: [
    { fold: "Fold 0", base: [0.9211, 0.7834, 0.8421], imp: [0.9402, 0.8167, 0.8654] },
    { fold: "Fold 1", base: [0.9167, 0.7789, 0.8378], imp: [0.9367, 0.8124, 0.8612] },
    { fold: "Fold 2", base: [0.9245, 0.7867, 0.8456], imp: [0.9434, 0.8201, 0.8689] },
    { fold: "Fold 3", base: [0.9189, 0.7812, 0.8401], imp: [0.9389, 0.8147, 0.8634] },
    { fold: "Fold 4", base: [0.9223, 0.7845, 0.8434], imp: [0.9412, 0.8178, 0.8667] },
    { fold: "Fold 5", base: [0.9201, 0.7823, 0.8412], imp: [0.9398, 0.8156, 0.8645] },
    { fold: "Fold 6", base: [0.9234, 0.7856, 0.8445], imp: [0.9421, 0.8189, 0.8678] },
    { fold: "Fold 7", base: [0.9178, 0.7801, 0.8389], imp: [0.9378, 0.8134, 0.8623] },
    { fold: "Fold 8", base: [0.9256, 0.7878, 0.8467], imp: [0.9445, 0.8212, 0.8701] },
    { fold: "Fold 9", base: [0.9212, 0.7834, 0.8423], imp: [0.9408, 0.8167, 0.8656] },
  ],
  F: [
    { fold: "Fold 0", base: [0.8967, 0.7521, 0.8123], imp: [0.9234, 0.7912, 0.8456] },
    { fold: "Fold 1", base: [0.8923, 0.7478, 0.8078], imp: [0.9189, 0.7867, 0.8412] },
    { fold: "Fold 2", base: [0.9001, 0.7556, 0.8156], imp: [0.9267, 0.7945, 0.8489] },
    { fold: "Fold 3", base: [0.8945, 0.7501, 0.8101], imp: [0.9212, 0.7889, 0.8434] },
    { fold: "Fold 4", base: [0.8978, 0.7534, 0.8134], imp: [0.9245, 0.7923, 0.8467] },
    { fold: "Fold 5", base: [0.8956, 0.7512, 0.8112], imp: [0.9223, 0.7901, 0.8445] },
    { fold: "Fold 6", base: [0.8989, 0.7545, 0.8145], imp: [0.9256, 0.7934, 0.8478] },
    { fold: "Fold 7", base: [0.8934, 0.7489, 0.8089], imp: [0.9201, 0.7878, 0.8423] },
    { fold: "Fold 8", base: [0.9012, 0.7567, 0.8167], imp: [0.9278, 0.7956, 0.8501] },
    { fold: "Fold 9", base: [0.8967, 0.7523, 0.8124], imp: [0.9234, 0.7912, 0.8456] },
  ],
};

function meanStd(rows: Row[]) {
  const cols = 6;
  const flat: number[][] = Array.from({ length: cols }, () => []);
  rows.forEach((r) => {
    [...r.base, ...r.imp].forEach((v, i) => flat[i].push(v));
  });
  const mean = flat.map((arr) => arr.reduce((a, b) => a + b, 0) / arr.length);
  const std = flat.map((arr, i) => Math.sqrt(arr.reduce((acc, v) => acc + (v - mean[i]) ** 2, 0) / arr.length));
  return { mean, std };
}

export function ModelCompare() {
  const [tab, setTab] = useState<"B" | "C" | "F">("B");
  const rows = data[tab];
  const { mean, std } = useMemo(() => meanStd(rows), [rows]);

  const meanBase: [number, number, number] = [mean[0], mean[1], mean[2]];
  const meanImp: [number, number, number] = [mean[3], mean[4], mean[5]];
  const stdBase: [number, number, number] = [std[0], std[1], std[2]];
  const stdImp: [number, number, number] = [std[3], std[4], std[5]];

  const deltaAuc = ((meanImp[0] - meanBase[0]) * 100).toFixed(2);
  const deltaAupr = ((meanImp[1] - meanBase[1]) * 100).toFixed(2);
  const deltaF1 = ((meanImp[2] - meanBase[2]) * 100).toFixed(2);

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-8 overflow-hidden">
        <div className="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-blue-500/15 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-40 right-1/4 w-96 h-96 rounded-full bg-purple-500/15 blur-3xl pointer-events-none" />

        <div className="relative flex items-start justify-between gap-6 flex-wrap">
          <div className="flex items-start gap-4 max-w-2xl">
            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400/30 to-orange-500/30 border border-amber-300/30 grid place-items-center text-amber-300 shadow-[0_0_24px_-4px_rgba(251,191,36,0.5)] shrink-0">
              <Trophy size={22} strokeWidth={2} />
            </div>
            <div className="flex flex-col gap-2">
              <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
                <Sparkles size={10} className="text-blue-300" />
                <span className="text-white/65" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 500, letterSpacing: '0.1em' }}>
                  BENCHMARK · 10-FOLD CV
                </span>
              </div>
              <h1 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '32px', fontWeight: 700, letterSpacing: '-0.02em', lineHeight: 1.1 }}>
                So sánh kết quả test model
              </h1>
              <p className="text-white/60" style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', lineHeight: 1.6 }}>
                Đánh giá mô hình HGT cải tiến so với baseline trên 10-fold cross-validation. Các chỉ số AUC, AUPR và F1-score
                được tính trung bình qua từng fold để đảm bảo độ tin cậy thống kê.
              </p>
            </div>
          </div>

          <div className="flex flex-col gap-2 min-w-[200px]">
            <DeltaPill label="ΔAUC" value={`+${deltaAuc}%`} />
            <DeltaPill label="ΔAUPR" value={`+${deltaAupr}%`} />
            <DeltaPill label="ΔF1" value={`+${deltaF1}%`} />
          </div>
        </div>

        {/* Tabs */}
        <div className="relative mt-7 flex items-center gap-2 flex-wrap">
          {(["B", "C", "F"] as const).map((t) => {
            const isActive = tab === t;
            return (
              <button
                key={t}
                onClick={() => setTab(t)}
                className={`relative inline-flex items-center gap-2 px-4 py-2 rounded-full transition-all ${
                  isActive
                    ? "bg-gradient-to-r from-blue-500/25 to-purple-500/25 border border-blue-400/40 text-white shadow-[0_0_24px_-4px_rgba(96,165,250,0.6)]"
                    : "bg-white/[0.03] border border-white/[0.08] text-white/55 hover:text-white hover:bg-white/[0.06]"
                }`}
                style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: isActive ? 600 : 500 }}
              >
                <span className={`w-1.5 h-1.5 rounded-full ${isActive ? "bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.9)]" : "bg-white/30"}`} />
                {t} Dataset
              </button>
            );
          })}
        </div>
      </section>

      {/* Table */}
      <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <TrendingUp size={15} className="text-blue-300" />
            <span className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '14px', fontWeight: 600 }}>
              Bảng kết quả · {tab}-dataset
            </span>
          </div>
          <span className="text-white/40" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', letterSpacing: '0.06em' }}>
            10 FOLDS · 6 METRICS
          </span>
        </div>

        <div className="overflow-x-auto rounded-2xl border border-white/[0.06] bg-black/30">
          <table className="w-full border-collapse" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '12.5px' }}>
            <thead>
              <tr className="bg-white/[0.04] border-b border-white/[0.08]">
                <th rowSpan={2} className="text-left px-5 py-3 text-white/70" style={{ fontFamily: 'Inter, sans-serif', fontWeight: 600, fontSize: '11.5px', letterSpacing: '0.08em' }}>
                  FOLD
                </th>
                <th colSpan={3} className="text-center px-4 py-2.5 border-l border-white/[0.06]" style={{ fontFamily: 'Inter, sans-serif', fontWeight: 600, fontSize: '11px', letterSpacing: '0.1em' }}>
                  <span className="inline-flex items-center gap-1.5 text-white/50">
                    <span className="w-1.5 h-1.5 rounded-full bg-white/40" />
                    BASELINE
                  </span>
                </th>
                <th colSpan={3} className="text-center px-4 py-2.5 border-l border-white/[0.06]" style={{ fontFamily: 'Inter, sans-serif', fontWeight: 600, fontSize: '11px', letterSpacing: '0.1em' }}>
                  <span className="inline-flex items-center gap-1.5 text-emerald-300">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(74,222,128,0.8)]" />
                    IMPROVED · HGT
                  </span>
                </th>
              </tr>
              <tr className="bg-white/[0.02] border-b border-white/[0.08]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 500, letterSpacing: '0.06em' }}>
                <ColHead>AUC</ColHead>
                <ColHead>AUPR</ColHead>
                <ColHead>F1</ColHead>
                <ColHead accent>AUC</ColHead>
                <ColHead accent>AUPR</ColHead>
                <ColHead accent>F1</ColHead>
              </tr>
            </thead>
            <tbody>
              {rows.map((r, idx) => (
                <tr
                  key={r.fold}
                  className="border-b border-white/[0.04] hover:bg-white/[0.02] transition-colors"
                >
                  <td className="px-5 py-2.5 text-white/55" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 500 }}>
                    <span className="text-white/30 mr-2" style={{ fontFamily: 'IBM Plex Mono, monospace' }}>
                      {String(idx).padStart(2, '0')}
                    </span>
                    {r.fold}
                  </td>
                  {r.base.map((v, i) => <Cell key={`b${i}`} v={v} />)}
                  {r.imp.map((v, i) => <Cell key={`i${i}`} v={v} accent better={v > r.base[i]} />)}
                </tr>
              ))}

              <SummaryRow label="Mean" base={meanBase} imp={meanImp} />
              <SummaryRow label="Std" base={stdBase} imp={stdImp} dim />
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-2 text-white/35" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px' }}>
            <FileCode2 size={12} />
            <span>Nguồn:</span>
            <code className="px-1.5 py-0.5 rounded bg-white/[0.04] border border-white/[0.06] text-white/55" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>
              results/{tab.toLowerCase()}-dataset/10fold_cv_summary.csv
            </code>
          </div>
          <div className="flex items-center gap-3 text-white/40" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px' }}>
            <LegendDot color="rgba(255,255,255,0.5)" label="Baseline" />
            <LegendDot color="#4ade80" label="Improved (HGT)" />
            <LegendDot color="#fbbf24" label="Mean / Std" />
          </div>
        </div>
      </section>
    </div>
  );
}

function DeltaPill({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3 px-3 py-2 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
      <span className="text-emerald-200/70" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}>
        {label}
      </span>
      <span className="text-emerald-300" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '13px', fontWeight: 600 }}>
        {value}
      </span>
    </div>
  );
}

function ColHead({ children, accent }: { children: React.ReactNode; accent?: boolean }) {
  return (
    <th className={`text-right px-4 py-2 border-l border-white/[0.04] ${accent ? "text-emerald-300/80" : "text-white/45"}`}>
      {children}
    </th>
  );
}

function Cell({ v, accent, better }: { v: number; accent?: boolean; better?: boolean }) {
  const color = accent ? (better ? "text-emerald-300" : "text-emerald-300/80") : "text-white/75";
  return (
    <td className={`text-right px-4 py-2.5 tabular-nums border-l border-white/[0.04] ${color}`}>
      {v.toFixed(4)}
    </td>
  );
}

function SummaryRow({ label, base, imp, dim }: { label: string; base: [number, number, number]; imp: [number, number, number]; dim?: boolean }) {
  return (
    <tr className="border-t border-amber-300/20" style={{ background: 'linear-gradient(90deg, rgba(251,191,36,0.06), rgba(251,146,60,0.04))' }}>
      <td className={`px-5 py-3 ${dim ? "text-amber-200/60" : "text-amber-200"}`} style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px', fontWeight: 700, letterSpacing: '0.04em' }}>
        <span className="inline-flex items-center gap-2">
          <span className="w-1 h-1 rounded-full bg-amber-300 shadow-[0_0_6px_rgba(251,191,36,0.9)]" />
          {label.toUpperCase()}
        </span>
      </td>
      {base.map((v, i) => (
        <td key={`mb${i}`} className={`text-right px-4 py-3 tabular-nums border-l border-white/[0.04] ${dim ? "text-white/55" : "text-white"}`} style={{ fontWeight: dim ? 400 : 600 }}>
          {dim ? `±${v.toFixed(4)}` : v.toFixed(4)}
        </td>
      ))}
      {imp.map((v, i) => (
        <td key={`mi${i}`} className={`text-right px-4 py-3 tabular-nums border-l border-white/[0.04] ${dim ? "text-emerald-300/70" : "text-emerald-300"}`} style={{ fontWeight: dim ? 400 : 700 }}>
          {dim ? `±${v.toFixed(4)}` : v.toFixed(4)}
        </td>
      ))}
    </tr>
  );
}

function LegendDot({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="w-2 h-2 rounded-full" style={{ background: color, boxShadow: `0 0 6px ${color}` }} />
      {label}
    </span>
  );
}
