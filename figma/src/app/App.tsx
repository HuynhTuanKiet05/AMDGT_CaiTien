import { useState } from "react";
import { Sidebar } from "./components/Sidebar";
import { Hero } from "./components/Hero";
import { PredictionForm } from "./components/PredictionForm";
import { ModelCompare } from "./components/ModelCompare";
import { HistoryPage } from "./components/HistoryPage";
import { AdminPage } from "./components/AdminPage";
import { ResultsPage } from "./components/ResultsPage";

export default function App() {
  const [active, setActive] = useState("results");

  return (
    <div className="relative min-h-screen w-full text-white overflow-x-hidden" style={{ background: '#0a0a0f', fontFamily: 'Inter, sans-serif' }}>
      <div
        className="pointer-events-none fixed inset-0 opacity-[0.35]"
        style={{
          background:
            'radial-gradient(ellipse 80% 60% at 15% 10%, rgba(96,165,250,0.15), transparent 60%), radial-gradient(ellipse 70% 50% at 85% 80%, rgba(139,92,246,0.18), transparent 60%), radial-gradient(ellipse 50% 40% at 50% 50%, rgba(59,130,246,0.06), transparent 70%)',
        }}
      />
      <div
        className="pointer-events-none fixed inset-0 opacity-[0.18]"
        style={{
          backgroundImage:
            'radial-gradient(rgba(255,255,255,0.35) 1px, transparent 1px)',
          backgroundSize: '24px 24px',
        }}
      />

      <div className="relative flex">
        <Sidebar active={active} onChange={setActive} />
        <main className="flex-1 min-w-0 p-6 lg:p-8 flex flex-col gap-6">
          {active === "compare" ? (
            <ModelCompare />
          ) : active === "history" ? (
            <HistoryPage />
          ) : active === "admin" ? (
            <AdminPage />
          ) : active === "results" ? (
            <ResultsPage />
          ) : (
            <>
              <Hero />
              <PredictionForm />
            </>
          )}
        </main>
      </div>

      <style>{`
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb {
          background: linear-gradient(180deg, rgba(96,165,250,0.4), rgba(139,92,246,0.4));
          border-radius: 999px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
          background: linear-gradient(180deg, rgba(96,165,250,0.7), rgba(139,92,246,0.7));
        }
      `}</style>
    </div>
  );
}
