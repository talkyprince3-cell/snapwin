'use client'

/**
 * Withdrawal confirmation: an iOS-style alert, then a drop-down banner with
 * the package artwork and tone. Payment and balance copy is drawn on top of
 * ab-mobilemoney-light.png. Amount and new_balance must come from the server.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Check } from 'lucide-react'

const ALERT_DURATION = 2200
const BANNER_DURATION = 5000
const ASSET_BASE = '/withdrawal-notification/assets'

export interface WithdrawalNotice {
  amount: number
  /** Balance read back from the server after the request — never computed here. */
  currentBalance: number
  currency: string
  /** False while an operator still has to settle it, which is the normal path. */
  settled?: boolean
}

function formatMoney(value: number, currency: string): string {
  if (!Number.isFinite(value) || value < 0) return `${currency} 0.00`
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  } catch {
    return `${currency} ${value.toFixed(2)}`
  }
}

export function WithdrawalNotification({
  notice,
  brandName = 'SnapWin',
  onDone,
}: {
  notice: WithdrawalNotice | null
  brandName?: string
  onDone?: () => void
}) {
  const [phase, setPhase] = useState<'idle' | 'alert' | 'banner' | 'leaving'>('idle')
  const audioRef = useRef<HTMLAudioElement | null>(null)
  const timer = useRef<number | null>(null)

  const clear = () => {
    if (timer.current !== null) window.clearTimeout(timer.current)
    timer.current = null
  }

  const toBanner = useCallback(() => {
    clear()
    setPhase('banner')
    const a = audioRef.current
    if (a) {
      a.currentTime = 0
      void a.play().catch(() => {})
    }
    timer.current = window.setTimeout(() => {
      setPhase('leaving')
      timer.current = window.setTimeout(() => {
        setPhase('idle')
        onDone?.()
      }, 380)
    }, BANNER_DURATION)
  }, [onDone])

  useEffect(() => {
    if (!notice) return
    setPhase('alert')
    clear()
    timer.current = window.setTimeout(toBanner, ALERT_DURATION)
    return clear
  }, [notice, toBanner])

  useEffect(() => clear, [])

  if (!notice || phase === 'idle') return null

  const amount = formatMoney(notice.amount, notice.currency)
  const balance = formatMoney(notice.currentBalance, notice.currency)
  const settled = notice.settled === true

  return (
    <>
      <div
        className={`withdrawal-ios-overlay${phase === 'alert' ? ' is-visible' : ''}`}
        aria-hidden={phase !== 'alert'}
      >
        <section
          className="withdrawal-ios-alert"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="withdrawalIosTitle"
        >
          <div className="withdrawal-ios-alert__content">
            <h2 className="withdrawal-ios-alert__title" id="withdrawalIosTitle">
              {settled ? 'Withdrawal successful' : 'Withdrawal requested'}
            </h2>
            <p className="withdrawal-ios-alert__message">
              {settled
                ? `Your withdrawal of ${amount} was completed.`
                : `Your withdrawal of ${amount} was received and is being processed.`}
            </p>
          </div>
          <button className="withdrawal-ios-alert__button" type="button" onClick={toBanner}>
            OK
          </button>
        </section>
      </div>

      <aside
        className={`withdrawal-ios-banner${
          phase === 'banner' ? ' is-visible' : phase === 'leaving' ? ' is-leaving' : ''
        }`}
        role="status"
        aria-live="polite"
      >
        <div className="withdrawal-ios-banner__art">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            className="withdrawal-ios-banner__image"
            src={`${ASSET_BASE}/ab-mobilemoney-light.png`}
            alt=""
            aria-hidden="true"
          />
          <div className="withdrawal-ios-banner__message">
            <span className="withdrawal-ios-banner__line">
              Payment received for {amount} from {brandName}.
            </span>
            <span className="withdrawal-ios-banner__line">
              Current Balance: {balance}. Available Balance: {balance}
            </span>
          </div>
        </div>
        {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
        <audio ref={audioRef} preload="auto" src={`${ASSET_BASE}/tone.mp3`} />
      </aside>

      <div
        className={`withdrawal-ios-toast${phase === 'banner' ? ' is-visible' : ''}`}
        role="status"
      >
        <Check size={16} className="withdrawal-ios-toast__tick" />
        <span>{settled ? 'Withdrawal successful.' : 'Withdrawal requested.'}</span>
      </div>
    </>
  )
}
