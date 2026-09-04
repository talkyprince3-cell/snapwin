"use client";

import { useState, useRef, useEffect } from "react";
import { MessageCircle, X, Send } from "lucide-react";

type Msg = { from: "bot" | "me"; text: string };

const QUICK = ["How do I deposit?", "Verify a ticket", "Withdrawal time?", "Bonus terms"];

const REPLIES: Record<string, string> = {
  "How do I deposit?": "Tap Deposit, choose MTN / Telecel / Vodafone Cash, enter the amount and approve the prompt on your phone. Funds land instantly. 💸",
  "Verify a ticket": "Head to the Verify page and paste your ticket code — you'll see real-time results and authenticity in seconds. 🎟️",
  "Withdrawal time?": "Mobile-money withdrawals are typically processed within 5–10 minutes, 24/7. ⚡",
  "Bonus terms": "Your 100% welcome bonus must be wagered 5× on odds of 1.50+ within 30 days. Acca insurance applies to 5+ legs. ✅",
};

export function SupportChat() {
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState<Msg[]>([
    { from: "bot", text: "👋 Hi, I'm the SnapWin assistant. How can I help you win today?" },
  ]);
  const [input, setInput] = useState("");
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [msgs, open]);

  function send(text: string) {
    if (!text.trim()) return;
    setMsgs((m) => [...m, { from: "me", text }]);
    setInput("");
    setTimeout(() => {
      const reply = REPLIES[text] ?? "Thanks! A support agent will be with you shortly. Meanwhile, you can check our FAQ or try one of the quick options below. 🙌";
      setMsgs((m) => [...m, { from: "bot", text: reply }]);
    }, 600);
  }

  return (
    <>
      <button
        onClick={() => setOpen((v) => !v)}
        className="fixed bottom-[76px] xl:bottom-6 right-4 xl:right-6 z-40 grid place-items-center w-[52px] h-[52px] rounded-full grad-brand text-white shadow-[0_12px_36px_-8px_rgba(249,115,22,.7)] hover:scale-105 active:scale-95 transition"
        aria-label="Support chat"
      >
        <span className="absolute inset-0 rounded-full grad-brand animate-ping opacity-20" />
        {open ? <X size={22} /> : <MessageCircle size={22} />}
      </button>

      {open && (
        <div className="fixed bottom-[140px] xl:bottom-[88px] right-4 xl:right-6 z-40 w-[min(370px,calc(100vw-2rem))] h-[min(540px,70dvh)] card flex flex-col overflow-hidden animate-rise shadow-2xl">
          {/* header */}
          <div className="flex items-center gap-3 px-4 py-3.5 bg-[var(--color-bg-2)] border-b border-[var(--color-line)]">
            <div className="relative grid place-items-center w-9 h-9 rounded-full grad-brand text-white">
              <MessageCircle size={18} />
              <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-[var(--color-emerald)] border-2 border-[var(--color-bg-2)]" />
            </div>
            <div>
              <div className="font-display font-bold text-[14px]">SnapWin Support</div>
              <div className="flex items-center gap-1.5 text-[10.5px] text-[var(--color-emerald)]">
                <span className="w-1.5 h-1.5 rounded-full bg-[var(--color-emerald)]" /> Online · Replies instantly
              </div>
            </div>
            <button onClick={() => setOpen(false)} className="ml-auto text-[var(--color-ink-faint)] hover:text-white">
              <X size={18} />
            </button>
          </div>

          {/* messages */}
          <div className="flex-1 overflow-y-auto no-scrollbar p-3.5 space-y-3">
            {msgs.map((m, i) => (
              <div key={i} className={m.from === "me" ? "flex justify-end" : "flex justify-start"}>
                <div
                  className={
                    m.from === "me"
                      ? "grad-brand text-white rounded-2xl rounded-br-md px-3.5 py-2.5 text-[13px] max-w-[80%]"
                      : "bg-[var(--color-surface-2)] border border-[var(--color-line)] rounded-2xl rounded-bl-md px-3.5 py-2.5 text-[13px] max-w-[85%] text-[var(--color-ink)]"
                  }
                >
                  {m.text}
                </div>
              </div>
            ))}
            <div ref={endRef} />
          </div>

          {/* quick replies */}
          <div className="px-3.5 pb-2 flex flex-wrap gap-1.5">
            {QUICK.map((q) => (
              <button key={q} onClick={() => send(q)} className="chip px-2.5 py-1 text-[10.5px]">
                {q}
              </button>
            ))}
          </div>

          {/* input */}
          <div className="p-3 border-t border-[var(--color-line)] flex items-center gap-2">
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && send(input)}
              placeholder="Type a message…"
              className="flex-1 bg-[var(--color-surface-2)] border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-[13px] outline-none focus:border-[var(--color-brand)]/50"
            />
            <button
              onClick={() => send(input)}
              className="grid place-items-center w-10 h-10 rounded-xl grad-brand text-white shrink-0 active:scale-95 transition"
            >
              <Send size={16} />
            </button>
          </div>
        </div>
      )}
    </>
  );
}
