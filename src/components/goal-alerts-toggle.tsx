"use client";

import { useEffect, useState } from "react";
import { Bell, BellRing, Loader2, BellOff } from "lucide-react";
import { getUserId } from "@/lib/user-session";

const VAPID = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY;

function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const b64 = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(b64);
  const arr = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
  return arr;
}

type State = "idle" | "unsupported" | "enabled" | "working" | "denied";

export function GoalAlertsToggle() {
  const [state, setState] = useState<State>("idle");

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!("serviceWorker" in navigator) || !("PushManager" in window) || !VAPID) {
      setState("unsupported");
      return;
    }
    if (Notification.permission === "denied") {
      setState("denied");
      return;
    }
    navigator.serviceWorker
      .getRegistration()
      .then(async (reg) => {
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        if (sub) setState("enabled");
      })
      .catch(() => {});
  }, []);

  async function enable() {
    if (!VAPID) return;
    setState("working");
    try {
      const perm = await Notification.requestPermission();
      if (perm !== "granted") {
        setState("denied");
        return;
      }
      const reg = await navigator.serviceWorker.register("/sw.js");
      await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(VAPID) as unknown as BufferSource,
      });
      const res = await fetch("/api/push/subscribe", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ userId: getUserId(), subscription: sub.toJSON() }),
      });
      setState(res.ok ? "enabled" : "idle");
    } catch {
      setState("idle");
    }
  }

  if (state === "unsupported") return null;

  if (state === "enabled") {
    return (
      <div className="flex items-center gap-2 rounded-xl border border-[var(--color-emerald)]/30 bg-[var(--color-emerald)]/10 px-3.5 py-2.5 text-[12.5px] font-semibold text-[var(--color-emerald)]">
        <BellRing size={15} /> Goal alerts on — we&apos;ll ping your phone when your teams score.
      </div>
    );
  }

  if (state === "denied") {
    return (
      <div className="flex items-center gap-2 rounded-xl border border-[var(--color-line)] px-3.5 py-2.5 text-[12px] text-[var(--color-ink-dim)]">
        <BellOff size={15} /> Notifications are blocked — enable them for this site in your browser settings.
      </div>
    );
  }

  return (
    <button
      onClick={enable}
      disabled={state === "working"}
      className="flex items-center gap-2 rounded-xl border border-[var(--color-brand)]/40 bg-[var(--color-brand)]/10 px-3.5 py-2.5 text-[12.5px] font-semibold text-[var(--color-brand)] hover:bg-[var(--color-brand)]/15 transition disabled:opacity-60"
    >
      {state === "working" ? <Loader2 size={15} className="animate-spin" /> : <Bell size={15} />}
      {state === "working" ? "Enabling…" : "🔔 Get goal alerts on your phone"}
    </button>
  );
}
