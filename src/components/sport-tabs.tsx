"use client";

import { useState } from "react";
import { sports } from "@/lib/data";
import { cn } from "@/lib/utils";

/**
 * Sport selector. Underline-active tabs in a solid bar — the sportsbook
 * convention — rather than free-floating pills, so the strip reads as one
 * continuous piece of navigation under the masthead.
 */
export function SportTabs() {
  const [active, setActive] = useState("football");
  return (
    <div className="border-b border-[var(--color-line)] bg-[var(--color-surface)]">
      <div className="mx-auto max-w-[1600px] flex items-center px-3 sm:px-5 overflow-x-auto no-scrollbar">
        {sports.map((s) => {
          const on = active === s.id;
          return (
            <button
              key={s.id}
              onClick={() => setActive(s.id)}
              aria-current={on ? "page" : undefined}
              className={cn(
                "relative shrink-0 flex items-center gap-1.5 px-3.5 py-2.5 text-[12.5px] font-semibold transition-colors",
                on ? "text-[var(--color-ink)]" : "text-[var(--color-ink-dim)] hover:text-[var(--color-ink)]",
              )}
            >
              <span className={cn("transition-opacity", on ? "opacity-100" : "opacity-60")}>{s.icon}</span>
              {s.name}
              {on && <span className="absolute inset-x-2 bottom-0 h-[2.5px] rounded-t-sm grad-brand" />}
            </button>
          );
        })}
      </div>
    </div>
  );
}
