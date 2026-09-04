"use client";

import Image from "next/image";
import { X } from "lucide-react";
import { formatMoneyWithCurrency } from "@/lib/format-money";

interface WinCongratsProps {
  open: boolean;
  onClose: () => void;
  amount: number;
  currency?: string;
  verifyCode?: string;
  ticketId: string;
  /** Called when the user taps "Details" — dismiss splash, reveal ticket. */
  onDetails?: () => void;
}

/**
 * SportyBet-style "YOU WON" celebration splash — ported from the reference
 * project's bet-ticket-details trophy splash. Big trophy, amount, verify code.
 */
export function WinCongrats({ open, onClose, amount, currency = "GHS", verifyCode, ticketId, onDetails }: WinCongratsProps) {
  if (!open) return null;

  const shareWin = () => {
    void navigator.share?.({
      title: "SnapWin — Won!",
      text: `Just won ${formatMoneyWithCurrency(amount, currency)} on SnapWin (ticket ${ticketId})`,
    }).catch(() => {});
  };

  return (
    <div className="fixed inset-0 z-[80] flex flex-col items-center px-5 sm:px-6 bg-black/92 animate-rise">
      {/* Close — top right */}
      <button
        type="button"
        onClick={onClose}
        aria-label="Close"
        className="absolute top-3 right-3 sm:top-4 sm:right-4 w-9 h-9 rounded-full flex items-center justify-center text-white/90 hover:bg-white/10 transition-colors"
      >
        <X className="w-6 h-6" strokeWidth={2.5} />
      </button>

      {/* Headline */}
      <div className="mt-16 sm:mt-20 text-center">
        <p className="text-5xl sm:text-6xl font-display font-extrabold text-white tracking-tight drop-shadow-lg">
          YOU WON
        </p>
        <p className="mt-2 num text-3xl sm:text-4xl font-bold text-white drop-shadow-md">
          {formatMoneyWithCurrency(amount, currency)}
        </p>
      </div>

      {/* Trophy */}
      <div className="relative flex-1 w-full mt-1 sm:mt-2 min-h-0">
        <Image
          src="/won_trophy_image.png"
          alt="Trophy"
          fill
          priority
          className="object-contain drop-shadow-[0_0_50px_rgba(255,200,0,0.55)]"
        />
      </div>

      {/* Verify code */}
      {verifyCode && (
        <p className="mt-1 text-sm sm:text-base text-white text-center">
          <span className="font-medium text-white/80">Verify Code: </span>
          <span className="num font-bold tracking-wider">{verifyCode}</span>
        </p>
      )}

      {/* Actions */}
      <div className="mt-3 mb-3 w-full max-w-sm flex gap-3">
        <button
          type="button"
          onClick={() => (onDetails ? onDetails() : onClose())}
          className="flex-1 h-12 rounded-xl border-2 border-[var(--color-emerald)] text-[var(--color-emerald)] bg-transparent hover:bg-[var(--color-emerald)]/10 font-display font-bold text-base transition-colors"
        >
          Details
        </button>
        <button
          type="button"
          onClick={shareWin}
          className="flex-1 h-12 rounded-xl bg-[var(--color-emerald)] hover:brightness-110 text-white font-display font-bold text-base transition"
        >
          Show Off
        </button>
      </div>
    </div>
  );
}
