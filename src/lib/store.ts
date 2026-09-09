import { create } from "zustand";
import type { Selection } from "./types";

type SlipState = {
  selections: Selection[];
  stake: number;
  mobileOpen: boolean;
  add: (s: Selection) => void;
  remove: (id: string) => void;
  toggle: (s: Selection) => void;
  clear: () => void;
  setStake: (n: number) => void;
  setMobileOpen: (b: boolean) => void;
  has: (id: string) => boolean;
};

export const useSlip = create<SlipState>((set, get) => ({
  selections: [],
  stake: 50,
  mobileOpen: false,
  add: (s) =>
    set((st) =>
      st.selections.some((x) => x.id === s.id)
        ? st
        : { selections: [...st.selections, s] },
    ),
  remove: (id) =>
    set((st) => ({ selections: st.selections.filter((x) => x.id !== id) })),
  toggle: (s) =>
    set((st) =>
      st.selections.some((x) => x.id === s.id)
        ? { selections: st.selections.filter((x) => x.id !== s.id) }
        : { selections: [...st.selections, s] },
    ),
  clear: () => set({ selections: [] }),
  setStake: (n) => set({ stake: Math.max(0, n) }),
  setMobileOpen: (b) => set({ mobileOpen: b }),
  has: (id) => get().selections.some((x) => x.id === id),
}));

export const totalOdds = (sels: Selection[]) =>
  sels.reduce((acc, s) => acc * s.odds, 1);

/**
 * Support-chat visibility. Lives in a store rather than inside SupportChat so
 * the home quick-action tile can open the same panel the floating launcher
 * does, instead of the two keeping rival copies of "is the chat open".
 */
type SupportState = {
  open: boolean;
  setOpen: (b: boolean) => void;
  toggle: () => void;
};

export const useSupport = create<SupportState>((set) => ({
  open: false,
  setOpen: (b) => set({ open: b }),
  toggle: () => set((st) => ({ open: !st.open })),
}));
