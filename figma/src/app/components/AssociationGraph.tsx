import { useState } from "react";
import { Network, Box, Maximize2, Filter } from "lucide-react";

type Node = { id: string; label: string; sub?: string; x: number; y: number; type: "drug" | "protein" | "disease" };
type Link = { from: string; to: string; kind: "truth" | "predicted"; confidence: number };

const nodes: Node[] = [
  // Drugs (left column)
  { id: "d1", label: "DB00659", sub: "Acamprosate", x: 110, y: 110, type: "drug" },
  { id: "d2", label: "DB00945", sub: "Aspirin", x: 110, y: 240, type: "drug" },
  { id: "d3", label: "DB00331", sub: "Metformin", x: 110, y: 370, type: "drug" },
  { id: "d4", label: "DB01076", sub: "Atorvastatin", x: 110, y: 500, type: "drug" },

  // Proteins (middle column)
  { id: "p1", label: "GRIN1", sub: "Glutamate R.", x: 450, y: 90, type: "protein" },
  { id: "p2", label: "PTGS2", sub: "COX-2", x: 450, y: 200, type: "protein" },
  { id: "p3", label: "AMPK", sub: "PRKAA1", x: 450, y: 310, type: "protein" },
  { id: "p4", label: "HMGCR", sub: "Reductase", x: 450, y: 420, type: "protein" },
  { id: "p5", label: "GABRA1", sub: "GABA R.", x: 450, y: 520, type: "protein" },

  // Diseases (right column)
  { id: "x1", label: "C0001973", sub: "Alcohol Dep.", x: 790, y: 110, type: "disease" },
  { id: "x2", label: "C0018802", sub: "Heart Failure", x: 790, y: 240, type: "disease" },
  { id: "x3", label: "C0011860", sub: "Diabetes T2", x: 790, y: 370, type: "disease" },
  { id: "x4", label: "C0020538", sub: "Hypertension", x: 790, y: 500, type: "disease" },
];

const links: Link[] = [
  { from: "d1", to: "p1", kind: "truth", confidence: 0.96 },
  { from: "p1", to: "x1", kind: "truth", confidence: 0.94 },
  { from: "d1", to: "p5", kind: "predicted", confidence: 0.78 },
  { from: "p5", to: "x1", kind: "predicted", confidence: 0.82 },

  { from: "d2", to: "p2", kind: "truth", confidence: 0.97 },
  { from: "p2", to: "x2", kind: "predicted", confidence: 0.81 },
  { from: "d2", to: "p4", kind: "predicted", confidence: 0.65 },

  { from: "d3", to: "p3", kind: "truth", confidence: 0.98 },
  { from: "p3", to: "x3", kind: "truth", confidence: 0.95 },
  { from: "d3", to: "p4", kind: "predicted", confidence: 0.72 },

  { from: "d4", to: "p4", kind: "truth", confidence: 0.99 },
  { from: "p4", to: "x4", kind: "truth", confidence: 0.93 },
  { from: "d4", to: "p2", kind: "predicted", confidence: 0.74 },
  { from: "p2", to: "x4", kind: "predicted", confidence: 0.69 },
];

const byId = Object.fromEntries(nodes.map((n) => [n.id, n]));

export function AssociationGraph() {
  const [is3D, setIs3D] = useState(false);
  const [hover, setHover] = useState<string | null>(null);

  return (
    <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-6 overflow-hidden">
      <div className="absolute -top-32 -left-20 w-96 h-96 rounded-full bg-blue-500/10 blur-3xl pointer-events-none" />
      <div className="absolute -bottom-40 right-1/4 w-96 h-96 rounded-full bg-purple-500/10 blur-3xl pointer-events-none" />

      <div className="relative flex items-center justify-between mb-5 flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-xl bg-cyan-500/15 border border-cyan-500/25 grid place-items-center text-cyan-300">
            <Network size={14} />
          </div>
          <div>
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '16px', fontWeight: 600 }}>
              Đồ thị liên kết Thuốc–Protein–Bệnh
            </h2>
            <p className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px' }}>
              Mạng kết nối 3 lớp với cạnh ground-truth (xanh lá) và AI dự đoán (xanh dương)
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <Legend dot="#22c55e" label="Ground truth" />
          <Legend dot="#38bdf8" label="AI predicted" dashed />
          <button className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px', fontWeight: 500 }}>
            <Filter size={12} /> Lọc cạnh
          </button>
          <button
            onClick={() => setIs3D((v) => !v)}
            className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border transition-all ${
              is3D
                ? "bg-gradient-to-r from-cyan-500/25 to-blue-500/25 border-cyan-400/40 text-white shadow-[0_0_24px_-4px_rgba(34,211,238,0.7)]"
                : "bg-white/[0.04] border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07]"
            }`}
            style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px', fontWeight: 600 }}
          >
            <Box size={12} /> {is3D ? "3D Mode" : "2D Mode"}
          </button>
          <button className="w-9 h-9 grid place-items-center rounded-xl bg-white/[0.04] border border-white/[0.08] text-white/65 hover:text-white hover:bg-white/[0.07]">
            <Maximize2 size={13} />
          </button>
        </div>
      </div>

      <div className="relative rounded-2xl bg-[#06060c] border border-white/[0.06] overflow-hidden">
        {/* column labels */}
        <div className="absolute inset-x-0 top-0 z-10 grid grid-cols-3 px-6 pt-4 pointer-events-none">
          <ColHeader label="THUỐC · DRUG" color="#38bdf8" />
          <ColHeader label="PROTEIN · BRIDGE" color="#f59e0b" centered />
          <ColHeader label="BỆNH · DISEASE" color="#f87171" right />
        </div>

        <svg viewBox="0 0 900 600" className="w-full block" preserveAspectRatio="xMidYMid meet" style={{ minHeight: 460, transform: is3D ? "perspective(1200px) rotateY(-12deg) rotateX(6deg)" : "none", transformOrigin: "center", transition: "transform 600ms cubic-bezier(.2,.8,.2,1)" }}>
          <defs>
            <radialGradient id="bgGlow">
              <stop offset="0%" stopColor="#1e3a8a" stopOpacity="0.25" />
              <stop offset="100%" stopColor="#1e3a8a" stopOpacity="0" />
            </radialGradient>
            <linearGradient id="truthLine" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stopColor="#22c55e" stopOpacity="0.8" />
              <stop offset="100%" stopColor="#4ade80" stopOpacity="0.9" />
            </linearGradient>
            <linearGradient id="predLine" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stopColor="#38bdf8" stopOpacity="0.7" />
              <stop offset="100%" stopColor="#60a5fa" stopOpacity="0.8" />
            </linearGradient>
            <filter id="ngGlow" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation="3" result="b" />
              <feMerge>
                <feMergeNode in="b" />
                <feMergeNode in="SourceGraphic" />
              </feMerge>
            </filter>
            <filter id="strongGlow" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation="6" result="b" />
              <feMerge>
                <feMergeNode in="b" />
                <feMergeNode in="SourceGraphic" />
              </feMerge>
            </filter>
          </defs>

          {/* dot grid background */}
          <g opacity="0.18">
            {Array.from({ length: 20 }).map((_, i) =>
              Array.from({ length: 14 }).map((__, j) => (
                <circle key={`${i}-${j}`} cx={20 + i * 45} cy={20 + j * 42} r="0.8" fill="#fff" />
              ))
            )}
          </g>

          <circle cx="450" cy="300" r="320" fill="url(#bgGlow)" />

          {/* links */}
          <g>
            {links.map((l, i) => {
              const a = byId[l.from];
              const b = byId[l.to];
              const mx = (a.x + b.x) / 2;
              const my = (a.y + b.y) / 2 + (i % 2 === 0 ? -22 : 22);
              const path = `M ${a.x} ${a.y} Q ${mx} ${my} ${b.x} ${b.y}`;
              const isActive = hover === l.from || hover === l.to;
              const isTruth = l.kind === "truth";
              return (
                <g key={i} opacity={hover && !isActive ? 0.25 : 1} style={{ transition: "opacity 200ms" }}>
                  <path
                    d={path}
                    fill="none"
                    stroke={isTruth ? "url(#truthLine)" : "url(#predLine)"}
                    strokeWidth={isTruth ? 2 : 1.2 + l.confidence}
                    strokeOpacity={isTruth ? 1 : 0.4 + l.confidence * 0.5}
                    strokeDasharray={isTruth ? undefined : "4 5"}
                    filter={isActive ? "url(#strongGlow)" : "url(#ngGlow)"}
                  />
                  {isTruth && (
                    <circle r="2.5" fill="#86efac" filter="url(#strongGlow)">
                      <animateMotion dur={`${3 + i * 0.4}s`} repeatCount="indefinite" path={path} />
                    </circle>
                  )}
                </g>
              );
            })}
          </g>

          {/* nodes */}
          <g>
            {nodes.map((n) => (
              <GraphNode key={n.id} node={n} hovered={hover === n.id} onHover={setHover} />
            ))}
          </g>
        </svg>

        {/* floating stats */}
        <div className="absolute bottom-3 left-3 right-3 flex items-center justify-between text-white/55 pointer-events-none" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', letterSpacing: '0.04em' }}>
          <span>NODES: {nodes.length} · EDGES: {links.length}</span>
          <span>LAYOUT · 3-COL · {is3D ? "PERSP-3D" : "FLAT-2D"}</span>
        </div>
      </div>
    </section>
  );
}

function ColHeader({ label, color, centered, right }: { label: string; color: string; centered?: boolean; right?: boolean }) {
  const align = right ? "justify-end" : centered ? "justify-center" : "justify-start";
  return (
    <div className={`flex ${align}`}>
      <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/40 border border-white/[0.08]" style={{ fontFamily: 'Inter, sans-serif', fontSize: '10.5px', fontWeight: 600, letterSpacing: '0.1em', color }}>
        <span className="w-1.5 h-1.5 rounded-full" style={{ background: color, boxShadow: `0 0 8px ${color}` }} />
        {label}
      </span>
    </div>
  );
}

function Legend({ dot, label, dashed }: { dot: string; label: string; dashed?: boolean }) {
  return (
    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/[0.04] border border-white/[0.08] text-white/70" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}>
      <svg width="20" height="6" viewBox="0 0 20 6">
        <line x1="0" y1="3" x2="20" y2="3" stroke={dot} strokeWidth="2" strokeDasharray={dashed ? "3 3" : undefined} style={{ filter: `drop-shadow(0 0 4px ${dot})` }} />
      </svg>
      {label}
    </span>
  );
}

function GraphNode({ node, hovered, onHover }: { node: Node; hovered: boolean; onHover: (id: string | null) => void }) {
  const { x, y, type, label, sub } = node;

  if (type === "drug") {
    const color = "#38bdf8";
    return (
      <g onMouseEnter={() => onHover(node.id)} onMouseLeave={() => onHover(null)} style={{ cursor: "pointer" }} filter={hovered ? "url(#strongGlow)" : "url(#ngGlow)"}>
        <polygon
          points={hexPoints(x, y, 26)}
          fill="rgba(56,189,248,0.12)"
          stroke={color}
          strokeWidth="1.5"
        />
        <polygon points={hexPoints(x, y, 16)} fill={color} opacity="0.85" />
        <text x={x} y={y + 3} textAnchor="middle" fill="#031827" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9, fontWeight: 700 }}>Rx</text>
        <text x={x - 38} y={y - 32} fill="#7dd3fc" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9.5, fontWeight: 600 }}>{label}</text>
        <text x={x - 38} y={y + 42} fill="#fff" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: 11, fontWeight: 600 }}>{sub}</text>
      </g>
    );
  }

  if (type === "protein") {
    const color = "#f59e0b";
    return (
      <g onMouseEnter={() => onHover(node.id)} onMouseLeave={() => onHover(null)} style={{ cursor: "pointer" }} filter={hovered ? "url(#strongGlow)" : "url(#ngGlow)"}>
        <circle cx={x} cy={y} r="22" fill="rgba(245,158,11,0.1)" stroke={color} strokeWidth="1.5" />
        <circle cx={x} cy={y} r="14" fill={color} opacity="0.85" />
        {/* helix lines */}
        <path d={`M ${x - 10} ${y - 8} Q ${x} ${y - 4} ${x + 10} ${y - 8}`} stroke="#fef3c7" strokeWidth="1" fill="none" />
        <path d={`M ${x - 10} ${y + 8} Q ${x} ${y + 4} ${x + 10} ${y + 8}`} stroke="#fef3c7" strokeWidth="1" fill="none" />
        <line x1={x - 8} y1={y - 6} x2={x - 8} y2={y + 6} stroke="#fef3c7" strokeWidth="0.6" />
        <line x1={x} y1={y - 4} x2={x} y2={y + 4} stroke="#fef3c7" strokeWidth="0.6" />
        <line x1={x + 8} y1={y - 6} x2={x + 8} y2={y + 6} stroke="#fef3c7" strokeWidth="0.6" />
        <text x={x} y={y - 32} textAnchor="middle" fill="#fcd34d" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 10, fontWeight: 700 }}>{label}</text>
        <text x={x} y={y + 38} textAnchor="middle" fill="rgba(255,255,255,0.6)" style={{ fontFamily: 'Inter, sans-serif', fontSize: 10 }}>{sub}</text>
      </g>
    );
  }

  // disease
  const color = "#f87171";
  return (
    <g onMouseEnter={() => onHover(node.id)} onMouseLeave={() => onHover(null)} style={{ cursor: "pointer" }} filter={hovered ? "url(#strongGlow)" : "url(#ngGlow)"}>
      <rect x={x - 24} y={y - 24} width="48" height="48" rx="12" fill="rgba(248,113,113,0.12)" stroke={color} strokeWidth="1.5" />
      {/* plus icon */}
      <rect x={x - 3} y={y - 14} width="6" height="28" rx="2" fill={color} />
      <rect x={x - 14} y={y - 3} width="28" height="6" rx="2" fill={color} />
      <text x={x + 34} y={y - 16} fill="#fca5a5" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: 9.5, fontWeight: 600 }}>{label}</text>
      <text x={x + 34} y={y + 8} fill="#fff" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: 11, fontWeight: 600 }}>{sub}</text>
    </g>
  );
}

function hexPoints(cx: number, cy: number, r: number) {
  const pts: string[] = [];
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 3) * i - Math.PI / 2;
    pts.push(`${cx + r * Math.cos(a)},${cy + r * Math.sin(a)}`);
  }
  return pts.join(" ");
}
