"use client";

import { useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { ArrowDownToLine, ArrowUpRight, Trophy, Ticket, XCircle, Loader2 } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { formatMoneyWithCurrency } from "@/lib/format-money";
import { getUserId } from "@/lib/user-session";

interface TxnItem {
  id: string;
  kind: "deposit" | "withdrawal" | "bet-placed" | "bet-won" | "bet-lost";
  amount: number;
  currency: string;
  status: "pending" | "success" | "failed" | "cancelled";
  createdAt: string;
  reference?: string;
  description: string;
}

const TABS = [
  { id: "all", label: "All" },
  { id: "deposit", label: "Deposits" },
  { id: "withdrawal", label: "Withdrawals" },
  { id: "bet-won", label: "Winnings" },
] as const;

const META: Record<TxnItem["kind"], { icon: React.ReactNode; tone: string }> = {
  deposit: { icon: <ArrowDownToLine size={16} />, tone: "text-[var(--color-emerald)] bg-[var(--color-emerald)]/12" },
  withdrawal: { icon: <ArrowUpRight size={16} />, tone: "text-[var(--color-cyan)] bg-[var(--color-cyan)]/12" },
  "bet-won": { icon: <Trophy size={16} />, tone: "text-[var(--color-amber)] bg-[var(--color-amber)]/12" },
  "bet-placed": { icon: <Ticket size={16} />, tone: "text-[var(--color-brand)] bg-[var(--color-brand)]/12" },
  "bet-lost": { icon: <XCircle size={16} />, tone: "text-[var(--color-rose)] bg-[var(--color-rose)]/12" },
};

export default function TransactionsPage() {
  const [tab, setTab] = useState<(typeof TABS)[number]["id"]>("all");
  const [items, setItems] = useState<TxnItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [noSession, setNoSession] = useState(false);

  const load = useCallback(async () => {
    const id = getUserId();
    if (!id) { setNoSession(true); setLoading(false); return; }
    try {
      const res = await fetch(`/api/users/${id}/transactions`);
      const data = await res.json();
      setItems(data.transactions ?? []);
    } catch {
      /* keep empty */
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const list = tab === "all" ? items : items.filter((t) => t.kind === tab);

  return (
    <AppShell tabs={false}>
      <div className="flex items-center gap-2.5 mb-4">
        <span className="title-bar" />
        <h1 className="font-display font-extrabold text-[18px]">Transactions</h1>
      </div>

      {noSession ? (
        <div className="card p-10 text-center">
          <p className="text-[14px] text-[var(--color-ink-dim)]">Sign in to view your transactions.</p>
          <Link href="/login" className="inline-block mt-4 rounded-xl px-5 py-2.5 font-display font-bold grad-brand text-white text-sm">Sign In</Link>
        </div>
      ) : (
        <>
          <div className="flex gap-2 overflow-x-auto no-scrollbar mb-4">
            {TABS.map((t) => (
              <button key={t.id} data-active={tab === t.id} onClick={() => setTab(t.id)} className="chip shrink-0 px-4 py-1.5">
                {t.label}
              </button>
            ))}
          </div>

          <div className="card overflow-hidden">
            <div className="flex items-center justify-between px-4 py-3 border-b border-[var(--color-line)] bg-[var(--color-bg-2)]">
              <span className="font-display font-bold text-[13px]">History</span>
              <span className="text-[11px] text-[var(--color-ink-faint)]">{list.length} item{list.length === 1 ? "" : "s"}</span>
            </div>

            {loading ? (
              <div className="grid place-items-center py-16 text-[var(--color-ink-dim)]"><Loader2 size={22} className="animate-spin" /></div>
            ) : (
              <div className="px-4 divide-y divide-[var(--color-line)]">
                {list.map((t) => <Row key={t.id} t={t} />)}
                {list.length === 0 && (
                  <div className="py-12 text-center text-[13px] text-[var(--color-ink-faint)]">No transactions in this category.</div>
                )}
              </div>
            )}
          </div>
        </>
      )}
    </AppShell>
  );
}

function Row({ t }: { t: TxnItem }) {
  const m = META[t.kind];
  const credit = t.amount > 0;
  const date = new Date(t.createdAt);
  const when = isNaN(date.getTime())
    ? ""
    : date.toLocaleDateString("en-GB", { day: "2-digit", month: "short" }) +
      " · " + date.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });

  return (
    <div className="flex items-center gap-3 py-3">
      <span className={`grid place-items-center w-9 h-9 rounded-xl shrink-0 ${m.tone}`}>{m.icon}</span>
      <div className="min-w-0 flex-1">
        <div className="text-[13.5px] font-semibold truncate">{t.description}</div>
        <div className="text-[11px] text-[var(--color-ink-faint)]">
          {when}{t.reference ? ` · ${t.reference}` : ""}
          {t.status !== "success" ? ` · ${t.status}` : ""}
        </div>
      </div>
      <div className={`num text-[13.5px] font-bold shrink-0 ${credit ? "text-[var(--color-emerald)]" : "text-[var(--color-ink)]"}`}>
        {credit ? "+" : t.amount < 0 ? "−" : ""}{formatMoneyWithCurrency(Math.abs(t.amount), t.currency)}
      </div>
    </div>
  );
}
