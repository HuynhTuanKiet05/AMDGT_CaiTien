import { ArrowRight } from "lucide-react";

export function Hero() {
  return (
    <section className="relative w-full overflow-hidden rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl px-10 lg:px-14 py-16 lg:py-20">
      <div className="absolute -top-32 -left-24 w-[28rem] h-[28rem] rounded-full bg-blue-500/25 blur-3xl pointer-events-none" />
      <div className="absolute -bottom-40 -right-20 w-[32rem] h-[32rem] rounded-full bg-purple-500/25 blur-3xl pointer-events-none" />
      <div className="absolute top-10 right-1/3 w-72 h-72 rounded-full bg-cyan-400/10 blur-3xl pointer-events-none" />

      {/* Decorative neural network / molecular SVG */}
      <svg
        className="absolute inset-0 w-full h-full pointer-events-none opacity-[0.55]"
        viewBox="0 0 1200 500"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden
      >
        <defs>
          <linearGradient id="lineA" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#60a5fa" stopOpacity="0" />
            <stop offset="50%" stopColor="#60a5fa" stopOpacity="0.7" />
            <stop offset="100%" stopColor="#8b5cf6" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="lineB" x1="0%" y1="100%" x2="100%" y2="0%">
            <stop offset="0%" stopColor="#8b5cf6" stopOpacity="0" />
            <stop offset="50%" stopColor="#a78bfa" stopOpacity="0.6" />
            <stop offset="100%" stopColor="#22d3ee" stopOpacity="0" />
          </linearGradient>
          <radialGradient id="node">
            <stop offset="0%" stopColor="#93c5fd" stopOpacity="1" />
            <stop offset="100%" stopColor="#60a5fa" stopOpacity="0" />
          </radialGradient>
          <radialGradient id="nodePurple">
            <stop offset="0%" stopColor="#c4b5fd" stopOpacity="1" />
            <stop offset="100%" stopColor="#8b5cf6" stopOpacity="0" />
          </radialGradient>
        </defs>

        {/* connection lines */}
        <g stroke="url(#lineA)" strokeWidth="1" fill="none">
          <path d="M 100 380 Q 300 250 500 320 T 900 220" />
          <path d="M 50 120 Q 250 220 450 140 T 850 280" />
          <path d="M 200 60 Q 400 180 600 100 T 1100 200" />
        </g>
        <g stroke="url(#lineB)" strokeWidth="1" fill="none">
          <path d="M 1100 60 Q 900 200 700 120 T 200 260" />
          <path d="M 1150 400 Q 950 320 750 420 T 250 360" />
          <path d="M 650 20 L 580 200 L 720 340 L 540 460" />
          <path d="M 380 470 L 420 300 L 280 220 L 360 60" />
        </g>

        {/* hex / molecule shape */}
        <g stroke="rgba(167,139,250,0.4)" strokeWidth="1" fill="none">
          <polygon points="980,140 1030,170 1030,230 980,260 930,230 930,170" />
          <polygon points="1030,170 1080,140 1130,170 1130,230 1080,260 1030,230" />
          <line x1="980" y1="260" x2="950" y2="320" />
          <line x1="1080" y1="260" x2="1110" y2="320" />
        </g>

        {/* glowing nodes */}
        <g>
          <circle cx="100" cy="380" r="14" fill="url(#node)" />
          <circle cx="100" cy="380" r="3" fill="#93c5fd" />
          <circle cx="500" cy="320" r="10" fill="url(#nodePurple)" />
          <circle cx="500" cy="320" r="2.5" fill="#c4b5fd" />
          <circle cx="900" cy="220" r="14" fill="url(#node)" />
          <circle cx="900" cy="220" r="3" fill="#93c5fd" />
          <circle cx="200" cy="60" r="10" fill="url(#nodePurple)" />
          <circle cx="200" cy="60" r="2.5" fill="#c4b5fd" />
          <circle cx="650" cy="20" r="8" fill="url(#node)" />
          <circle cx="380" cy="470" r="8" fill="url(#nodePurple)" />
          <circle cx="850" cy="280" r="6" fill="url(#node)" />
        </g>
      </svg>

      <div className="relative max-w-3xl flex flex-col gap-6">
        <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.05] border border-white/[0.08] w-fit">
          <span className="w-1.5 h-1.5 rounded-full bg-blue-400 shadow-[0_0_8px_2px_rgba(96,165,250,0.7)]" />
          <span className="text-white/75" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11.5px', fontWeight: 500, letterSpacing: '0.1em' }}>
            AMNTDDA · GRAPH NEURAL NETWORK
          </span>
        </div>

        <h1
          className="text-white"
          style={{
            fontFamily: 'Space Grotesk, sans-serif',
            fontWeight: 700,
            fontSize: 'clamp(36px, 5vw, 56px)',
            letterSpacing: '-0.025em',
            lineHeight: 1.05,
          }}
        >
          Dự đoán liên kết{' '}
          <span className="bg-gradient-to-r from-blue-400 via-cyan-300 to-purple-400 bg-clip-text text-transparent">
            Thuốc – Bệnh
          </span>
          <br />
          bằng AI đồ thị
        </h1>

        <p className="text-white/65 max-w-2xl" style={{ fontFamily: 'Inter, sans-serif', fontSize: '15px', lineHeight: 1.65 }}>
          Chọn một hay nhiều thuốc, bệnh và để mô hình HGT tổng hợp kết quả theo từng chiều
          dự đoán, kèm đồ thị phân tử 2D &amp; 3D trực quan hơn.
        </p>

        <div className="flex flex-wrap items-center gap-4 mt-2">
          <button
            className="group relative inline-flex items-center gap-2 px-6 py-3.5 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_32px_-6px_rgba(96,165,250,0.8)] hover:shadow-[0_12px_40px_-4px_rgba(139,92,246,0.9)] transition-all"
            style={{ fontFamily: 'Inter, sans-serif', fontSize: '14px', fontWeight: 600 }}
          >
            <span className="absolute inset-0 rounded-xl bg-gradient-to-r from-white/20 to-transparent opacity-50" />
            <span className="relative">Bắt đầu dự đoán</span>
            <ArrowRight size={16} className="relative group-hover:translate-x-1 transition-transform" />
          </button>

          <div className="inline-flex items-center gap-2">
            <span className="relative flex w-2 h-2">
              <span className="absolute inset-0 rounded-full bg-emerald-400 animate-ping opacity-75" />
              <span className="relative w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,0.9)]" />
            </span>
            <span className="text-white/55" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '11.5px', letterSpacing: '0.04em' }}>
              AI API · Trực tuyến
            </span>
          </div>
        </div>
      </div>
    </section>
  );
}
