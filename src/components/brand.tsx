import Link from "next/link";
import { cn } from "@/lib/utils";

export function LogoMark({ size = 32, id = "main" }: { size?: number; id?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <defs>
        <linearGradient id={`logoGrad-${id}`} x1="4" y1="2" x2="28" y2="30">
          <stop stopColor="#fbbf24" />
          <stop offset="0.45" stopColor="#f97316" />
          <stop offset="1" stopColor="#e11d48" />
        </linearGradient>
      </defs>
      {/* Tile */}
      <rect width="32" height="32" rx="8" fill={`url(#logoGrad-${id})`} />
      {/* Snap bolt, knocked out of the tile */}
      <path d="M18.4 4L9 17.6h5.4L13.6 28 23 14.4h-5.4L18.4 4z" fill="#140b0e" />
    </svg>
  );
}

export function Brand({
  size = 32,
  className,
  id = "main",
  href = "/",
}: {
  size?: number;
  className?: string;
  id?: string;
  href?: string | null;
}) {
  const inner = (
    <span className={cn("flex items-center gap-2 select-none", className)}>
      <LogoMark size={size} id={id} />
      <span className="font-display font-extrabold tracking-tight text-[19px] leading-none">
        SNAP<span className="grad-text">WIN</span>
      </span>
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
