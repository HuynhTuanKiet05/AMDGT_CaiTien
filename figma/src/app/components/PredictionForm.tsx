import { useState } from "react";
import { Dna, Pill, HeartPulse, Play, ChevronDown, Sliders } from "lucide-react";
import { Picker } from "./Picker";

const drugs = [
  "Aspirin", "Ibuprofen", "Metformin", "Atorvastatin", "Amoxicillin",
  "Lisinopril", "Omeprazole", "Levothyroxine", "Albuterol", "Gabapentin",
  "Hydrochlorothiazide", "Sertraline", "Losartan", "Furosemide", "Pantoprazole",
  "Prednisone", "Tramadol", "Citalopram", "Warfarin", "Clopidogrel",
];

const diseases = [
  "Diabetes Type 2", "Hypertension", "Asthma", "Alzheimer's", "Parkinson's",
  "Migraine", "Depression", "Anxiety", "Arthritis", "Osteoporosis",
  "Hypothyroidism", "Coronary Artery Disease", "COPD", "Epilepsy", "Stroke",
  "Heart Failure", "Chronic Kidney Disease", "Anemia", "Hepatitis B", "Lupus",
];

const drugItems = drugs.map((n, i) => ({ id: `d${i}`, name: n, meta: `DB${String(i + 1).padStart(3, '0')}` }));
const diseaseItems = diseases.map((n, i) => ({ id: `b${i}`, name: n, meta: `DZ${String(i + 1).padStart(3, '0')}` }));

export function PredictionForm() {
  const [selDrugs, setSelDrugs] = useState<string[]>(["d0", "d2"]);
  const [selDiseases, setSelDiseases] = useState<string[]>(["b0"]);
  const [topK, setTopK] = useState(10);
  const [dataset, setDataset] = useState("B-dataset");

  return (
    <section className="relative rounded-[24px] bg-white/[0.03] border border-white/[0.06] backdrop-blur-2xl p-8 overflow-hidden">
      <div className="absolute -top-32 right-1/4 w-80 h-80 rounded-full bg-purple-500/10 blur-3xl pointer-events-none" />

      <div className="relative flex items-center justify-between mb-6">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/30 to-purple-600/30 border border-white/10 grid place-items-center text-blue-300">
            <Dna size={18} />
          </div>
          <div>
            <h2 className="text-white" style={{ fontFamily: 'Space Grotesk, sans-serif', fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em' }}>
              Dự đoán liên kết
            </h2>
            <p className="text-white/45" style={{ fontFamily: 'Inter, sans-serif', fontSize: '12px' }}>
              Chọn thuốc và bệnh để mô hình HGT phân tích liên kết
            </p>
          </div>
        </div>
        <div className="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.04] border border-white/[0.06]">
          <Sliders size={12} className="text-white/50" />
          <span className="text-white/60" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500 }}>
            HGT v2.1
          </span>
        </div>
      </div>

      <div className="relative grid grid-cols-1 lg:grid-cols-2 gap-5">
        <Picker
          label="Tên thuốc"
          items={drugItems}
          selected={selDrugs}
          onChange={setSelDrugs}
          accent="blue"
          icon={<Pill size={14} />}
        />
        <Picker
          label="Tên bệnh"
          items={diseaseItems}
          selected={selDiseases}
          onChange={setSelDiseases}
          accent="purple"
          icon={<HeartPulse size={14} />}
        />
      </div>

      <div className="relative mt-6 grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
        <DropdownField label="Top-K kết quả" value={String(topK)} onChange={(v) => setTopK(Number(v))} options={["5", "10", "15", "20"]} />
        <DropdownField label="Dataset" value={dataset} onChange={setDataset} options={["B-dataset", "C-dataset", "F-dataset"]} />
        <button
          disabled={selDrugs.length === 0 || selDiseases.length === 0}
          className="group relative inline-flex items-center justify-center gap-2 h-[46px] px-7 rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-[0_8px_32px_-8px_rgba(96,165,250,0.7)] hover:shadow-[0_12px_40px_-4px_rgba(139,92,246,0.8)] transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none"
          style={{ fontFamily: 'Inter, sans-serif', fontSize: '13.5px', fontWeight: 600 }}
        >
          <Play size={14} className="fill-current" />
          Chạy dự đoán
        </button>
      </div>
    </section>
  );
}

function DropdownField({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: string[] }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-white/50 px-1" style={{ fontFamily: 'Inter, sans-serif', fontSize: '11px', fontWeight: 500, letterSpacing: '0.04em' }}>
        {label}
      </span>
      <div className="relative">
        <select
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="appearance-none w-full h-[46px] pl-4 pr-10 rounded-xl bg-black/40 border border-white/[0.08] focus:border-blue-400/40 focus:outline-none focus:ring-2 focus:ring-blue-500/20 text-white cursor-pointer"
          style={{ fontFamily: 'Inter, sans-serif', fontSize: '13px', fontWeight: 500 }}
        >
          {options.map((o) => (
            <option key={o} value={o} className="bg-[#0a0a0f]">{o}</option>
          ))}
        </select>
        <ChevronDown size={15} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 pointer-events-none" />
      </div>
    </label>
  );
}
