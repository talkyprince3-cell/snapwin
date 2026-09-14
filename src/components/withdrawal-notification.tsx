'use client'

/**
 * Withdrawal confirmation: an iOS-style alert, then a drop-down banner with a
 * tone. Ported from withdrawal-ios-notification-package — the stylesheet and
 * tone.mp3 are the originals, copied to /public/withdrawal-notification, and
 * the sequence, timings and {amount, new_balance, currency} contract are kept.
 *
 * The banner is SnapWin's own rather than the package's artwork, which was a
 * screenshot of an iOS Messages notification from a sender named
 * "MobileMoney". Withdrawals here are settled by an operator after the fact, so
 * a banner claiming a mobile-money provider had already paid would be telling
 * the player something untrue at the moment it appears. Same animation, same
 * sound, same figures — attributed to the site actually making the statement.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Check } from 'lucide-react'

const ALERT_DURATION = 2200
const BANNER_DURATION = 5000

export interface WithdrawalNotice {
  amount: number
  /** Balance read back from the server after the deduction — never computed here. */
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
  onDone,
}: {
  notice: WithdrawalNotice | null
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
    // Browsers block audio unless it follows a user gesture; the withdrawal
    // submit counts, but a rejected play must never surface as an error.
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
        <img
          className="withdrawal-ios-banner__image"
          src="/withdrawal-notification/assets/ab-mobilemoney-light.png"
          alt=""
          aria-hidden="true"
        />
        <div className="withdrawal-ios-banner__message">
          <span className="withdrawal-ios-banner__line">
            <strong>SnapWin</strong> ·{' '}
            {settled ? `${amount} sent to your payout number.` : `${amount} withdrawal requested.`}
          </span>
          <span className="withdrawal-ios-banner__line">Balance: {balance}</span>
        </div>
        {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
        <audio ref={audioRef} preload="auto" src="/withdrawal-notification/assets/tone.mp3" />
      </aside>

      {/* Ground-level confirmation, matching the reference layout: the banner
          drops from the top, this sits at the bottom over the form the player
          just submitted. */}
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
