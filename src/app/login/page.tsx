"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Eye, EyeOff, Loader2 } from "lucide-react";
import { AuthShell, Field } from "@/components/auth-shell";
import { saveUserSession } from "@/lib/user-session";

const inputCls =
  "w-full bg-[var(--color-surface)] border border-[var(--color-line)] rounded-xl px-3.5 py-3 text-[14px] outline-none focus:border-[var(--color-brand)]/60 focus:glow-brand transition placeholder:text-[var(--color-ink-faint)]";

export default function LoginPage() {
  const [show, setShow] = useState(false);
  const [identifier, setIdentifier] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await fetch("/api/users/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ identifier: identifier.trim(), password }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data.error ?? "Login failed");
        return;
      }
      saveUserSession(data.user.id, data.user.name);
      router.push("/account");
    } catch {
      setError("Network error — please try again.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthShell title="Welcome back" subtitle="Sign in to continue to SnapWin.">
      <form onSubmit={onSubmit}>
        <Field label="Phone or Email">
          <input
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
            placeholder="024 XXX XXXX or you@email.com"
            autoComplete="username"
            className={inputCls}
          />
        </Field>

        <Field label="Password">
          <div className="relative">
            <input
              type={show ? "text" : "password"}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Enter your password"
              autoComplete="current-password"
              className={inputCls + " pr-11"}
            />
            <button type="button" onClick={() => setShow((v) => !v)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-faint)] hover:text-white">
              {show ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          </div>
        </Field>

        {error && (
          <p className="mb-4 -mt-1 text-[12.5px] font-semibold text-[var(--color-rose,#fb7185)]">{error}</p>
        )}

        <div className="flex justify-end -mt-1 mb-5">
          <button type="button" className="text-[12px] font-semibold text-[var(--color-cyan)] hover:underline">Forgot password?</button>
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-xl py-3.5 font-display font-extrabold text-[14px] grad-brand text-white shadow-[0_10px_30px_-8px_rgba(249,115,22,.55)] active:scale-[.99] transition disabled:opacity-60 flex items-center justify-center gap-2"
        >
          {loading && <Loader2 size={16} className="animate-spin" />}
          {loading ? "Signing in…" : "Sign In"}
        </button>

        <p className="text-center text-[13px] text-[var(--color-ink-dim)] mt-6">
          Don&apos;t have an account? <Link href="/register" className="font-bold text-[var(--color-brand)] hover:underline">Create one</Link>
        </p>
      </form>
    </AuthShell>
  );
}
