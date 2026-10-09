"use client";

import { useState, useRef, useEffect } from "react";
import { usePathname } from "next/navigation";
import { MessageCircle, X, Send } from "lucide-react";
import { useSupport } from "@/lib/store";

type Msg = { from: "bot" | "me"; text: string };

/**
 * Support reaches a person on Telegram. The widget answers the four questions
 * it genuinely knows instantly, and hands everything else over — it used to
 * reply "a support agent will be with you shortly" to anything it didn't
 * recognise, which reached nobody and was simply untrue.
 *
 * Username without the @: t.me links don't take the prefix.
 */
const TELEGRAM_USERNAME = "snapwinsupport";

function telegramLink(text?: string): string {
  const base = `https://t.me/${TELEGRAM_USERNAME}`;
  const msg = text?.trim()
    ? text.trim()
    : "Hi SnapWin support, I need help with my account.";
  return `${base}?text=${encodeURIComponent(msg)}`;
}

const QUICK = ["How do I deposit?", "Verify a ticket", "Withdrawal time?", "Bonus terms"];

const REPLIES: Record<string, string> = {
  "How do I deposit?": "Tap Deposit, choose MTN / Telecel / Vodafone Cash, enter the amount and approve the prompt on your phone. Funds land instantly. 💸",
  "Verify a ticket": "Head to the Verify page and paste your ticket code — you'll see real-time results and authenticity in seconds. 🎟️",
  "Withdrawal time?": "Mobile-money withdrawals are typically processed within 5–10 minutes, 24/7. ⚡",
  "Bonus terms": "Your 100% welcome bonus must be wagered 5× on odds of 1.50+ within 30 days. Acca insurance applies to 5+ legs. ✅",
};

export function SupportChat() {
  const open = useSupport((s) => s.open);
  const setOpen = useSupport((s) => s.setOpen);
  const toggleOpen = useSupport((s) => s.toggle);
  // Home already offers Support as a quick-action tile, and the floating
  // launcher was covering the winners ticker there. Keep it on every other
  // page, where nothing else reaches support.
  const onHome = usePathname() === "/";
  const [msgs, setMsgs] = useState<Msg[]>([
    { from: "bot", text: "👋 Hi, I'm the SnapWin assistant. How can I help you win today?" },
  ]);
  const [input, setInput] = useState("");
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [msgs, open]);

  // What an unanswered question should carry over to Telegram, so the person
  // doesn't have to type it a second time.
  const [handoff, setHandoff] = useState<string | null>(null);

  function send(text: string) {
    if (!text.trim()) return;
    setMsgs((m) => [...m, { from: "me", text }]);
    setInput("");
    const known = REPLIES[text];
    if (!known) setHandoff(text);
    setTimeout(() => {
      const reply =
        known ??
        "I can't answer that one myself — tap Telegram below and a person will pick it up. Your message comes with you. 🙌";
      setMsgs((m) => [...m, { from: "bot", text: reply }]);
    }, 600);
  }

  return (
    <>
      {(!onHome || open) && (
        <button
          onClick={toggleOpen}
          className="fixed bottom-[76px] xl:bottom-6 right-4 xl:right-6 z-40 grid place-items-center w-[52px] h-[52px] rounded-full grad-brand text-[var(--color-on-brand)] shadow-[0_12px_36px_-8px_rgba(255,200,0,.55)] hover:scale-105 active:scale-95 transition"
          aria-label={open ? "Close support chat" : "Open support chat"}
        >
          <span className="absolute inset-0 rounded-full grad-brand animate-ping opacity-20" />
          {open ? <X size={22} /> : <MessageCircle size={22} />}
        </button>
      )}

      {open && (
        <div className="fixed bottom-[140px] xl:bottom-[88px] right-4 xl:right-6 z-40 w-[min(370px,calc(100vw-2rem))] h-[min(540px,70dvh)] card flex flex-col overflow-hidden animate-rise shadow-2xl">
          {/* header */}
          <div className="flex items-center gap-3 px-4 py-3.5 bg-[var(--color-bg-2)] border-b border-[var(--color-line)]">
            <div className="relative grid place-items-center w-9 h-9 rounded-full grad-brand text-[var(--color-on-brand)]">
              <MessageCircle size={18} />
              <span className="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-[var(--color-emerald)] border-2 border-[var(--color-bg-2)]" />
            </div>
            <div>
              <div className="font-display font-bold text-[14px]">SnapWin Support</div>
              {/* Says where a real answer comes from. It used to claim
                  "Online · Replies instantly", which described a canned bot. */}
              <div className="flex items-center gap-1.5 text-[10.5px] text-[var(--color-emerald)]">
                <span className="w-1.5 h-1.5 rounded-full bg-[var(--color-emerald)]" /> Live help on Telegram
              </div>
            </div>
            <button onClick={() => setOpen(false)} className="ml-auto text-[var(--color-ink-faint)] hover:text-[var(--color-ink)]">
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
                      ? "grad-brand text-[var(--color-on-brand)] rounded-2xl rounded-br-md px-3.5 py-2.5 text-[13px] max-w-[80%]"
                      : "bg-[var(--color-surface-2)] border border-[var(--color-line)] rounded-2xl rounded-bl-md px-3.5 py-2.5 text-[13px] max-w-[85%] text-[var(--color-ink)]"
                  }
                >
                  {m.text}
                </div>
              </div>
            ))}
            <div ref={endRef} />
          </div>

          {/* The way to an actual person. Always present, and emphasised
              once the widget has failed to answer something. */}
          <div className="px-3.5 pb-2">
            <a
              href={telegramLink(handoff ?? undefined)}
              target="_blank"
              rel="noopener noreferrer"
              className={`flex items-center justify-center gap-2 w-full rounded-xl py-2.5 font-display font-bold text-[13px] transition active:scale-[.99] ${
                handoff
                  ? "bg-[#2AABEE] text-white"
                  : "border border-[#2AABEE]/40 text-[#2AABEE] hover:bg-[#2AABEE]/10"
              }`}
            >
              <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true">
                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
              </svg>
              {handoff ? "Continue on Telegram" : "Chat with us on Telegram"}
            </a>
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
              className="grid place-items-center w-10 h-10 rounded-xl grad-brand text-[var(--color-on-brand)] shrink-0 active:scale-95 transition"
            >
              <Send size={16} />
            </button>
          </div>
        </div>
      )}
    </>
  );
}
