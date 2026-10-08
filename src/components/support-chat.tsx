"use client";

import { useState, useRef, useEffect } from "react";
import { usePathname } from "next/navigation";
import { MessageCircle, X, Send } from "lucide-react";
import { useSupport } from "@/lib/store";

type Msg = { from: "bot" | "me"; text: string };

/**
 * Support reaches a person on WhatsApp. The widget answers the four questions
 * it genuinely knows instantly, and hands everything else over — it used to
 * reply "a support agent will be with you shortly" to anything it didn't
 * recognise, which reached nobody and was simply untrue.
 *
 * Ghana mobile in international form, no plus: wa.me rejects the 0 prefix.
 */
const WHATSAPP_NUMBER = "233241036037";

function waLink(text?: string): string {
  const base = `https://wa.me/${WHATSAPP_NUMBER}`;
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

  // What an unanswered question should carry over to WhatsApp, so the person
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
        "I can't answer that one myself — tap WhatsApp below and a person will pick it up. Your message comes with you. 🙌";
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
                <span className="w-1.5 h-1.5 rounded-full bg-[var(--color-emerald)]" /> Live help on WhatsApp
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
              href={waLink(handoff ?? undefined)}
              target="_blank"
              rel="noopener noreferrer"
              className={`flex items-center justify-center gap-2 w-full rounded-xl py-2.5 font-display font-bold text-[13px] transition active:scale-[.99] ${
                handoff
                  ? "bg-[#25D366] text-white"
                  : "border border-[#25D366]/40 text-[#25D366] hover:bg-[#25D366]/10"
              }`}
            >
              <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true">
                <path d="M17.47 14.38c-.3-.15-1.73-.85-2-.95-.27-.1-.47-.15-.67.15-.2.3-.77.95-.94 1.15-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.73-.71 1.98-1.39.24-.68.24-1.26.17-1.39-.07-.12-.27-.2-.57-.35z" />
                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.004c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.13h-.004a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.17 8.17 0 0 1-1.25-4.36c0-4.54 3.7-8.23 8.24-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.69 8.21-8.25 8.21z" />
              </svg>
              {handoff ? "Continue on WhatsApp" : "Chat with us on WhatsApp"}
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
