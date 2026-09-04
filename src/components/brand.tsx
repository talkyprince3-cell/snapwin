import Image from "next/image";
import Link from "next/link";
import { cn } from "@/lib/utils";

/**
 * The SnapWin badge: a black disc with a gold rim and a knocked-out bolt.
 *
 * Drawn as paths rather than SVG <text> so it renders identically as a favicon,
 * an app icon and an OG image — none of which load our web fonts.
 */
export function LogoMark({ size = 32, id = "main" }: { size?: number; id?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <defs>
        <linearGradient id={`logoGrad-${id}`} x1="6" y1="3" x2="26" y2="29">
          <stop stopColor="#ffe9a3" />
          <stop offset="0.45" stopColor="#ffc800" />
          <stop offset="1" stopColor="#ff9f0a" />
        </linearGradient>
      </defs>
      <circle cx="16" cy="16" r="15" fill="#0b0b0c" stroke={`url(#logoGrad-${id})`} strokeWidth="2" />
      {/* Bolt, leaning forward to match the wordmark's oblique. */}
      <path d="M18.6 5.5L9.4 17.9h5.2l-1 8.6 9.2-12.4h-5.2l1-8.6z" fill={`url(#logoGrad-${id})`} />
    </svg>
  );
}

/** Natural size of public/logo.png after its transparent padding was trimmed. */
const WORDMARK_W = 383;
const WORDMARK_H = 69;

/**
 * Full lockup: the supplied SNAP/WIN wordmark, optionally preceded by the badge.
 *
 * `size` is the lockup's HEIGHT in px; the wordmark keeps its own 5.55:1 ratio
 * rather than being boxed into a square. SNAP is white in the artwork, so this
 * only reads on a dark ground — which is the only ground the app has.
 */
export function Brand({
  size = 28,
  className,
  id = "main",
  href = "/",
  mark = false,
  priority = false,
}: {
  size?: number;
  className?: string;
  id?: string;
  href?: string | null;
  /** Set on the masthead only — it is the LCP image there. Everywhere else the
   *  lockup is below the fold, and eager-loading them all just warns. */
  priority?: boolean;
  /** Show the bolt badge alongside the wordmark. Off by default: the wordmark
   *  already carries the brand, and doubling up crowds a 60px-tall masthead. */
  mark?: boolean;
}) {
  const inner = (
    <span className={cn("flex items-center gap-2 select-none", className)}>
      {mark && <LogoMark size={size} id={id} />}
      <Image
        src="/logo.png"
        alt="SnapWin"
        width={WORDMARK_W}
        height={WORDMARK_H}
        priority={priority}
        style={{ height: size, width: "auto" }}
        className="object-contain"
      />
    </span>
  );
  if (href === null) return inner;
  return <Link href={href}>{inner}</Link>;
}

/**
 * League/country flag. Renders the real flag image from the feed when we have
 * one, otherwise falls back to the emoji flag (or globe for unknown countries).
 */
export function CountryFlag({
  url,
  emoji,
  className = "",
}: {
  url?: string;
  emoji: string;
  className?: string;
}) {
  if (url) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={url}
        alt=""
        aria-hidden
        loading="lazy"
        className={cn("inline-block h-[12px] w-[16px] rounded-[2px] object-cover align-[-1px]", className)}
      />
    );
  }
  return <span className={className}>{emoji}</span>;
}

export function TeamBadge({
  short,
  color,
  size = 38,
  logo,
}: {
  short: string;
  color: string;
  size?: number;
  logo?: string;
}) {
  // Real crest from the feed when we have one; otherwise an initials badge.
  if (logo) {
    return (
      <span
        className="grid place-items-center rounded-full shrink-0 overflow-hidden bg-white/5"
        style={{ width: size, height: size, border: `1.5px solid ${color}66` }}
      >
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={logo}
          alt={short}
          width={Math.round(size * 0.74)}
          height={Math.round(size * 0.74)}
          loading="lazy"
          className="object-contain"
        />
      </span>
    );
  }
  return (
    <span
      className="grid place-items-center rounded-full font-display font-extrabold shrink-0"
      style={{
        width: size,
        height: size,
        fontSize: size * 0.34,
        background: `radial-gradient(circle at 30% 25%, ${color}38, ${color}14)`,
        border: `1.5px solid ${color}66`,
        color: "#fff",
      }}
    >
      {short.slice(0, 3)}
    </span>
  );
}
