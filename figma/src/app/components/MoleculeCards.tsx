import { Atom, ArrowUpRight } from "lucide-react";

const ATOM = {
  O: "#fb7185", // coral
  N: "#22d3ee", // cyan
  S: "#f59e0b", // amber
  F: "#34d399", // emerald
  H: "#ffffff",
};

type MolData = {
  tag: "Input Molecule" | "Matched Target";
  id: string;
  name: string;
  formula: string;
  weight: string;
  confidence: number;
  Draw: React.FC;
};

const molecules: MolData[] = [
  {
    tag: "Input Molecule",
    id: "DB00659",
    name: "Acamprosate",
    formula: "C₅H₁₁NO₄S",
    weight: "181.21 g/mol",
    confidence: 0.9412,
    Draw: AcamprosateSVG,
  },
  {
    tag: "Matched Target",
    id: "DB00945",
    name: "Aspirin",
    formula: "C₉H₈O₄",
    weight: "180.16 g/mol",
    confidence: 0.9128,
    Draw: AspirinSVG,
  },
  {
    tag: "Matched Target",
    id: "DB00331",
    name: "Metformin",
    formula: "C₄H₁₁N₅",
    weight: "129.16 g/mol",
    confidence: 0.9687,
    Draw: MetforminSVG,
  },
  {
    tag: "Matched Target",
    id: "DB01076",
    name: "Atorvastatin",
    formula: "C₃₃H₃₅FN₂O₅",
    weight: "558.64 g/mol",
    confidence: 0.9234,
    Draw: AtorvastatinSVG,
  },
];

export function MoleculeCards() {
  return (
    <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
      <div className="flex items-center justify-between mb-5">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-xl bg-purple-500/15 border border-purple-500/25 grid place-items-center text-purple-300">
            <Atom size={14} />
          </div>
          <div>
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
              Cấu trúc phân tử · 2D Skeletal
            </h2>
            <p className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px' }}>
              Drawn from canonical SMILES — atoms: O coral · N cyan · S amber · F emerald
            </p>
          </div>
        </div>
        <button className="inline-flex items-center gap-1 text-blue-300 hover:text-blue-200" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px', fontWeight: 500 }}>
          Xem 3D <ArrowUpRight size={12} />
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {molecules.map((m) => <Card key={m.id} {...m} />)}
      </div>

      <div className="mt-4 flex items-center justify-center gap-4 flex-wrap text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px' }}>
        <AtomLegend symbol="O" color={ATOM.O} name="Oxygen" />
        <AtomLegend symbol="N" color={ATOM.N} name="Nitrogen" />
        <AtomLegend symbol="S" color={ATOM.S} name="Sulfur" />
        <AtomLegend symbol="F" color={ATOM.F} name="Fluorine" />
      </div>
    </section>
  );
}

function AtomLegend({ symbol, color, name }: { symbol: string; color: string; name: string }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full grid place-items-center" style={{ background: color, boxShadow: `0 0 10px ${color}` }}>
        <span style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 8, fontWeight: 700, color: "#0a0a0f" }}>{symbol}</span>
      </span>
      {name}
    </span>
  );
}

function Card({ tag, id, name, formula, weight, confidence, Draw }: MolData) {
  const isInput = tag === "Input Molecule";
  const pct = (confidence * 100).toFixed(1);
  return (
    <div className="group relative rounded-2xl bg-white/[0.02] border border-white/[0.08] p-4 overflow-hidden hover:border-white/[0.16] transition-all">
      <div className="absolute -top-24 -right-16 w-48 h-48 rounded-full bg-blue-500/15 blur-3xl pointer-events-none" />
      <div className="absolute -bottom-24 -left-16 w-48 h-48 rounded-full bg-purple-500/12 blur-3xl pointer-events-none" />

      <div className="relative flex items-center justify-between mb-3">
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border ${
          isInput
            ? "bg-blue-500/15 border-blue-400/40 text-blue-200 shadow-[0_0_12px_-3px_rgba(96,165,250,0.7)]"
            : "bg-white/[0.05] border-white/[0.1] text-white/70"
        }`} style={{ fontFamily: 'Inter, sans-serif', fontSize: '10px', fontWeight: 600, letterSpacing: '0.04em' }}>
          {isInput && <span className="w-1 h-1 rounded-full bg-blue-300 shadow-[0_0_4px_rgba(147,197,253,0.9)]" />}
          {tag}
        </span>
        <span className="text-white/50" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', fontWeight: 500 }}>
          {id}
        </span>
      </div>

      <div className="relative aspect-[5/4] rounded-xl bg-gradient-to-br from-blue-500/8 via-transparent to-purple-500/8 border border-white/[0.05] grid place-items-center mb-3 overflow-hidden">
        <Draw />
      </div>

      <div className="relative">
        <div className="text-white truncate" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '15px', fontWeight: 600 }}>
          {name}
        </div>
        <div className="text-white/50 truncate" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px' }}>
          {formula} · {weight}
        </div>
        <div className="mt-3 pt-3 border-t border-white/[0.06]">
          <div className="flex items-center justify-between mb-1.5">
            <span className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10px', letterSpacing: '0.08em' }}>
              CONFIDENCE
            </span>
            <span className="text-emerald-300" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '12.5px', fontWeight: 700, textShadow: '0 0 10px rgba(74,222,128,0.6)' }}>
              {pct}%
            </span>
          </div>
          <div className="h-[5px] rounded-full bg-white/[0.05] overflow-hidden">
            <div className="h-full bg-gradient-to-r from-emerald-500 to-emerald-300 shadow-[0_0_8px_rgba(74,222,128,0.7)]" style={{ width: `${pct}%` }} />
          </div>
        </div>
      </div>
    </div>
  );
}

/* ─────────────────────────────── Molecule drawings ─────────────────────────────── */

const BOND = { stroke: "#e5e7eb", strokeWidth: 1.6, fill: "none" as const, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };

function AtomDot({ x, y, color, label }: { x: number; y: number; color: string; label: string }) {
  return (
    <g>
      <circle cx={x} cy={y} r="9" fill="#0a0a0f" />
      <circle cx={x} cy={y} r="7.5" fill={color} style={{ filter: `drop-shadow(0 0 6px ${color})` }} />
      <text x={x} y={y + 3.2} textAnchor="middle" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9, fontWeight: 700, fill: "#0a0a0f" }}>
        {label}
      </text>
    </g>
  );
}

/* ACAMPROSATE — CH3-C(=O)-NH-CH2-CH2-CH2-S(=O)(=O)-OH (open chain) */
function AcamprosateSVG() {
  return (
    <svg viewBox="0 0 320 220" className="w-full h-full">
      <g {...BOND}>
        {/* zigzag main chain */}
        <line x1="30" y1="140" x2="60" y2="100" />
        {/* C=O double bond */}
        <line x1="60" y1="100" x2="90" y2="140" />
        <line x1="63" y1="98" x2="85" y2="135" strokeWidth="1.6" />
        {/* C=O to O up */}
        <line x1="60" y1="100" x2="60" y2="70" />
        <line x1="64" y1="100" x2="64" y2="72" />
        {/* C to N */}
        <line x1="90" y1="140" x2="120" y2="100" />
        {/* N to CH2 */}
        <line x1="120" y1="100" x2="150" y2="140" />
        <line x1="150" y1="140" x2="180" y2="100" />
        <line x1="180" y1="100" x2="210" y2="140" />
        {/* C to S */}
        <line x1="210" y1="140" x2="240" y2="100" />
        {/* S double bonds */}
        <line x1="240" y1="100" x2="240" y2="60" />
        <line x1="244" y1="100" x2="244" y2="62" />
        <line x1="240" y1="100" x2="276" y2="118" />
        <line x1="242" y1="104" x2="278" y2="122" />
        {/* S to OH */}
        <line x1="240" y1="100" x2="270" y2="140" />
      </g>

      {/* labels for terminal methyl */}
      <text x="30" y="158" textAnchor="middle" fill="rgba(255,255,255,0.7)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 10 }}>CH₃</text>

      {/* highlighted groups */}
      <rect x="44" y="56" width="62" height="100" rx="10" fill="none" stroke="rgba(34,211,238,0.25)" strokeDasharray="3 3" />
      <text x="75" y="50" textAnchor="middle" fill="#67e8f9" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9, fontWeight: 600 }}>acetamide</text>

      <rect x="222" y="40" width="80" height="120" rx="10" fill="none" stroke="rgba(245,158,11,0.3)" strokeDasharray="3 3" />
      <text x="262" y="34" textAnchor="middle" fill="#fcd34d" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9, fontWeight: 600 }}>sulfonate</text>

      {/* atoms */}
      <AtomDot x={60} y={70} color={ATOM.O} label="O" />
      <AtomDot x={120} y={100} color={ATOM.N} label="N" />
      <AtomDot x={240} y={100} color={ATOM.S} label="S" />
      <AtomDot x={240} y={60} color={ATOM.O} label="O" />
      <AtomDot x={278} y={122} color={ATOM.O} label="O" />
      <AtomDot x={270} y={140} color={ATOM.O} label="O" />
      <text x={282} y={146} fill="rgba(255,255,255,0.5)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 8 }}>H</text>
    </svg>
  );
}

/* ASPIRIN — benzene + COOH + OCOCH3 ortho */
function AspirinSVG() {
  // benzene hexagon center
  const cx = 130, cy = 120, r = 38;
  const hex: [number, number][] = [];
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 3) * i - Math.PI / 2;
    hex.push([cx + r * Math.cos(a), cy + r * Math.sin(a)]);
  }
  return (
    <svg viewBox="0 0 320 220" className="w-full h-full">
      <g {...BOND}>
        {/* benzene */}
        <polygon points={hex.map((p) => p.join(",")).join(" ")} />
        {/* alternating double bonds (inside) */}
        {[0, 2, 4].map((i) => {
          const [x1, y1] = hex[i];
          const [x2, y2] = hex[(i + 1) % 6];
          const dx = (x2 - x1) * 0.15;
          const dy = (y2 - y1) * 0.15;
          const nx = -(y2 - y1) * 0.12;
          const ny = (x2 - x1) * 0.12;
          return (
            <line key={i} x1={x1 + dx + nx} y1={y1 + dy + ny} x2={x2 - dx + nx} y2={y2 - dy + ny} />
          );
        })}

        {/* COOH off top-right vertex (hex[1]) */}
        <line x1={hex[1][0]} y1={hex[1][1]} x2={hex[1][0] + 30} y2={hex[1][1] - 18} />
        <line x1={hex[1][0] + 30} y1={hex[1][1] - 18} x2={hex[1][0] + 30} y2={hex[1][1] - 50} />
        <line x1={hex[1][0] + 33} y1={hex[1][1] - 18} x2={hex[1][0] + 33} y2={hex[1][1] - 52} />
        <line x1={hex[1][0] + 30} y1={hex[1][1] - 18} x2={hex[1][0] + 62} y2={hex[1][1] - 6} />

        {/* ester O-C(=O)-CH3 off right vertex (hex[2]) */}
        <line x1={hex[2][0]} y1={hex[2][1]} x2={hex[2][0] + 32} y2={hex[2][1] + 8} />
        <line x1={hex[2][0] + 32} y1={hex[2][1] + 8} x2={hex[2][0] + 56} y2={hex[2][1] + 28} />
        {/* C=O double */}
        <line x1={hex[2][0] + 56} y1={hex[2][1] + 28} x2={hex[2][0] + 56} y2={hex[2][1] + 60} />
        <line x1={hex[2][0] + 59} y1={hex[2][1] + 30} x2={hex[2][0] + 59} y2={hex[2][1] + 60} />
        <line x1={hex[2][0] + 56} y1={hex[2][1] + 28} x2={hex[2][0] + 88} y2={hex[2][1] + 16} />
      </g>

      <text x={hex[2][0] + 92} y={hex[2][1] + 21} fill="rgba(255,255,255,0.7)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 10 }}>CH₃</text>

      {/* atoms */}
      <AtomDot x={hex[1][0] + 30} y={hex[1][1] - 50} color={ATOM.O} label="O" />
      <AtomDot x={hex[1][0] + 62} y={hex[1][1] - 6} color={ATOM.O} label="O" />
      <AtomDot x={hex[2][0] + 32} y={hex[2][1] + 8} color={ATOM.O} label="O" />
      <AtomDot x={hex[2][0] + 56} y={hex[2][1] + 60} color={ATOM.O} label="O" />

      {/* ortho marker */}
      <text x="100" y="200" fill="rgba(255,255,255,0.45)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9 }}>ortho: COOH + ester</text>
    </svg>
  );
}

/* METFORMIN — open biguanide H2N-C(=NH)-NH-C(=NH)-N(CH3)2 */
function MetforminSVG() {
  return (
    <svg viewBox="0 0 320 220" className="w-full h-full">
      <g {...BOND}>
        {/* skeleton zigzag */}
        {/* N1 - C1 */}
        <line x1="40" y1="120" x2="75" y2="90" />
        {/* C1=N (top double bond) */}
        <line x1="75" y1="90" x2="75" y2="55" />
        <line x1="79" y1="90" x2="79" y2="57" />
        {/* C1 - N2 */}
        <line x1="75" y1="90" x2="115" y2="120" />
        {/* N2 - C2 */}
        <line x1="115" y1="120" x2="155" y2="90" />
        {/* C2=N (top double bond) */}
        <line x1="155" y1="90" x2="155" y2="55" />
        <line x1="159" y1="90" x2="159" y2="57" />
        {/* C2 - N3 (dimethyl) */}
        <line x1="155" y1="90" x2="195" y2="120" />
        {/* N3 - CH3 (two) */}
        <line x1="195" y1="120" x2="235" y2="100" />
        <line x1="195" y1="120" x2="235" y2="150" />
      </g>

      <text x="240" y="105" fill="rgba(255,255,255,0.7)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 10 }}>CH₃</text>
      <text x="240" y="155" fill="rgba(255,255,255,0.7)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 10 }}>CH₃</text>
      <text x="40" y="138" textAnchor="middle" fill="rgba(255,255,255,0.55)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9 }}>H₂</text>

      {/* highlight biguanide chain */}
      <rect x="20" y="42" width="200" height="100" rx="12" fill="none" stroke="rgba(34,211,238,0.25)" strokeDasharray="3 3" />
      <text x="120" y="36" textAnchor="middle" fill="#67e8f9" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9, fontWeight: 600 }}>open biguanide · N=C–N–C=N</text>

      <AtomDot x={40} y={120} color={ATOM.N} label="N" />
      <AtomDot x={75} y={55} color={ATOM.N} label="N" />
      <AtomDot x={115} y={120} color={ATOM.N} label="N" />
      <AtomDot x={155} y={55} color={ATOM.N} label="N" />
      <AtomDot x={195} y={120} color={ATOM.N} label="N" />
    </svg>
  );
}

/* ATORVASTATIN — pyrrole core + 3 phenyls (1 F) + dihydroxyheptanoic acid */
function AtorvastatinSVG() {
  return (
    <svg viewBox="0 0 360 240" className="w-full h-full">
      <g {...BOND}>
        {/* central pyrrole (5-ring) */}
        <polygon points="180,90 205,108 196,138 164,138 155,108" />
        {/* pyrrole double bonds */}
        <line x1="182" y1="94" x2="201" y2="108" />
        <line x1="166" y1="134" x2="194" y2="134" />

        {/* Phenyl A (top-left) */}
        <Phenyl cx={120} cy={70} r={18} />
        <line x1="155" y1="108" x2="138" y2="86" />

        {/* Phenyl B (top-right, fluorinated) */}
        <Phenyl cx={240} cy={70} r={18} />
        <line x1="205" y1="108" x2="222" y2="86" />
        <line x1="258" y1="60" x2="278" y2="50" />

        {/* Phenyl C (bottom) */}
        <Phenyl cx={180} cy={195} r={18} />
        <line x1="180" y1="138" x2="180" y2="177" />

        {/* isopropyl off pyrrole right */}
        <line x1="205" y1="108" x2="232" y2="120" />
        <line x1="232" y1="120" x2="248" y2="108" />
        <line x1="232" y1="120" x2="248" y2="132" />

        {/* anilide (C=O - NH - phenyl C) — show only carbonyl off bottom-left */}
        <line x1="164" y1="138" x2="148" y2="158" />
        <line x1="148" y1="158" x2="148" y2="180" />
        <line x1="151" y1="158" x2="151" y2="180" />

        {/* dihydroxyheptanoic acid chain (right tail) */}
        <line x1="196" y1="138" x2="225" y2="158" />
        <line x1="225" y1="158" x2="250" y2="146" />
        <line x1="250" y1="146" x2="278" y2="166" />
        <line x1="278" y1="166" x2="303" y2="154" />
        <line x1="303" y1="154" x2="328" y2="174" />
        {/* terminal COOH */}
        <line x1="328" y1="174" x2="328" y2="200" />
        <line x1="331" y1="174" x2="331" y2="202" />
        <line x1="328" y1="174" x2="350" y2="160" />
        {/* hydroxyls */}
        <line x1="250" y1="146" x2="250" y2="122" />
        <line x1="303" y1="154" x2="303" y2="130" />
      </g>

      {/* atom labels */}
      <AtomDot x={278} y={50} color={ATOM.F} label="F" />
      <AtomDot x={148} y={180} color={ATOM.O} label="O" />
      <AtomDot x={250} y={122} color={ATOM.O} label="O" />
      <AtomDot x={303} y={130} color={ATOM.O} label="O" />
      <AtomDot x={328} y={200} color={ATOM.O} label="O" />
      <AtomDot x={350} y={160} color={ATOM.O} label="O" />
      <AtomDot x={158} y={108} color={ATOM.N} label="N" />
      <text x="142" y="166" textAnchor="middle" fill={ATOM.N} style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 8, fontWeight: 700 }}>NH</text>

      <text x="180" y="20" textAnchor="middle" fill="rgba(255,255,255,0.45)" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9 }}>pyrrole core · 3 phenyl · 1F</text>
    </svg>
  );
}

function Phenyl({ cx, cy, r }: { cx: number; cy: number; r: number }) {
  const pts: [number, number][] = [];
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 3) * i - Math.PI / 2;
    pts.push([cx + r * Math.cos(a), cy + r * Math.sin(a)]);
  }
  return (
    <g>
      <polygon points={pts.map((p) => p.join(",")).join(" ")} />
      {[0, 2, 4].map((i) => {
        const [x1, y1] = pts[i];
        const [x2, y2] = pts[(i + 1) % 6];
        const dx = (x2 - x1) * 0.15;
        const dy = (y2 - y1) * 0.15;
        const nx = -(y2 - y1) * 0.13;
        const ny = (x2 - x1) * 0.13;
        return <line key={i} x1={x1 + dx + nx} y1={y1 + dy + ny} x2={x2 - dx + nx} y2={y2 - dy + ny} />;
      })}
    </g>
  );
}
