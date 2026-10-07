export type WithdrawalIosPayload = {
  amount: number
  currentBalance: number
  currency?: string
  brandName?: string
}

declare global {
  interface Window {
    WithdrawalNotification?: {
      configure: (options: Record<string, unknown>) => void
      show: (options: Record<string, unknown>) => void
      hide: () => void
    }
  }
}

/** Plays the v1 iOS withdrawal alert + banner after the server confirms. */
export function showWithdrawalIos(options: WithdrawalIosPayload): void {
  if (typeof window === 'undefined') return
  const show = window.WithdrawalNotification?.show
  if (!show) return
  show({
    amount: options.amount,
    currentBalance: options.currentBalance,
    currency: options.currency || 'GHS',
    brandName: options.brandName || 'SnapWin',
  })
}
