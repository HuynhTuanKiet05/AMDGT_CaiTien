import { useMemo, useState } from "react";
import { Search, X, Check } from "lucide-react";

export interface PickerProps {
  label: string;
  items: { id: string; name: string; meta?: string }[];
  selected: string[];
  onChange: (next: string[]) => void;
  max?: number;
  accent: "blue" | "purple";
  icon: React.ReactNode;
}

export function Picker({ label, items, selected, onChange, max = 5, accent, icon }: PickerProps) {
  const [q, setQ] = useState("");
  const filtered = useMemo(
    () => items.filter((it) => it.name.toLowerCase().includes(q.toLowerCase())),
    [items, q]
  );

  const toggle = (id: string) => {
    if (selected.includes(id)) onChange(selected.filter((x) => x !== id));
    else if (selected.length < max) onChange([...selected, id]);
  };

  const accentGlow =
    accent === "blue"
      ? "shadow-[0_0_24px_-8px_rgba(96,165,250,0.6)]"
      : "shadow-[0_0_24px_-8px_rgba(139,92,246,0.6)]";
  const accentText = accent === "blue" ? "text-blue-300" : "text-purple-300";
  const accentBg = accent === "blue" ? "bg-blue-500/15 border-blue-500/30" : "bg-purple-500/15 border-purple-500/30";
  const accentDot = accent === "blue" ? "bg-blue-400" : "bg-purple-400";

  return (
    <div className={`rounded-[20px] bg-white/[0.03] border border-white/[0.06] p-4 backdrop-blur-xl ${accentGlow}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <div className={`w-7 h-7 rounded-lg grid place-items-center border ${accentBg} ${accentText}`}>
            {icon}
          </div>
          <span className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '13.5px', fontWeight: 600 }}>
            {label}
          </span>
        </div>
        <span className={`px-2 py-0.5 rounded-full border ${accentBg} ${accentText}`} style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '10.5px', fontWeight: 600 }}>
          {selected.length}/{max}
        </span>
      </div>

      <div className="relative mb-3">
        <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Tìm kiếm..."
          className="w-full pl-8 pr-3 py-2.5 rounded-xl bg-black/40 border border-white/[0.06] focus:border-white/20 focus:outline-none text-white placeholder:text-white/30"
          style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px' }}
        />
      </div>

      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1.5 mb-3">
          {selected.map((id) => {
            const item = items.find((i) => i.id === id);
            if (!item) return null;
            return (
              <span
                key={id}
                className={`inline-flex items-center gap-1 pl-2.5 pr-1.5 py-1 rounded-full ${accentBg} border ${accentText}`}
                style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}
              >
                {item.name}
                <button onClick={() => toggle(id)} className="w-3.5 h-3.5 grid place-items-center rounded-full hover:bg-white/10">
                  <X size={9} />
                </button>
              </span>
            );
          })}
        </div>
      )}

      <div className="h-[240px] overflow-y-auto pr-1 -mr-1 space-y-1 custom-scroll">
        {filtered.map((item) => {
          const isSel = selected.includes(item.id);
          const disabled = !isSel && selected.length >= max;
          return (
            <button
              key={item.id}
              disabled={disabled}
              onClick={() => toggle(item.id)}
              className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-left ${
                isSel
                  ? `${accentBg} border ${accentText}`
                  : "border border-transparent text-white/70 hover:bg-white/[0.04] hover:text-white"
              } ${disabled ? "opacity-40 cursor-not-allowed" : ""}`}
              style={{ fontFamily: 'Inter, sans-serif', fontSize: '12.5px' }}
            >
              <div
                className={`w-4 h-4 rounded-[5px] border grid place-items-center shrink-0 ${
                  isSel ? `${accentDot} border-transparent` : "border-white/20 bg-black/30"
                }`}
              >
                {isSel && <Check size={10} className="text-white" strokeWidth={3} />}
              </div>
              <span className="flex-1 truncate">{item.name}</span>
              {item.meta && <span className="text-white/30" style={{ fontSize: '10.5px' }}>{item.meta}</span>}
            </button>
          );
        })}
        {filtered.length === 0 && (
          <div className="text-center py-8 text-white/30" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px' }}>
            Không tìm thấy kết quả
          </div>
        )}
      </div>

      <div className="mt-3 pt-3 border-t border-white/5 flex items-center justify-between">
        <span className="text-white/40" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px' }}>Đã chọn</span>
        <span className="text-white" style={{ fontFamily: 'IBM Plex Mono, monospace', fontSize: '12px', fontWeight: 600 }}>
          {selected.length}/{max}
        </span>
      </div>
    </div>
  );
}
