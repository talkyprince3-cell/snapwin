import { ImageResponse } from 'next/og'
import { findBookingByCode } from '@/lib/bookings-store'

export const dynamic = 'force-dynamic'

type Params = { params: Promise<{ code: string }> }

// A shareable PNG of a booked slip — big code, the teams, the odds, SnapWin
// branding. Punters share this straight to WhatsApp / status.
export async function GET(_req: Request, { params }: Params) {
  const { code } = await params
  const booking = await findBookingByCode(code)

  if (!booking) {
    return new Response('Not found', { status: 404 })
  }

  const sels = booking.selections
  const shown = sels.slice(0, 8)
  const extra = sels.length - shown.length

  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          backgroundColor: '#0b0b0c',
          backgroundImage:
            'radial-gradient(circle at 18% 8%, rgba(249, 115, 22,0.35), transparent 45%), radial-gradient(circle at 88% 92%, rgba(225, 29, 72,0.30), transparent 45%)',
          padding: '56px',
          fontFamily: 'sans-serif',
          color: '#ffffff',
        }}
      >
        {/* brand */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'baseline', fontSize: 46, fontWeight: 800 }}>
            <span style={{ color: '#f7f7f8' }}>SNAP</span>
            <span style={{ color: '#ffc800' }}>WIN</span>
          </div>
          <div
            style={{
              display: 'flex',
              fontSize: 22,
              fontWeight: 700,
              letterSpacing: 2,
              color: '#22d3ee',
              border: '2px solid rgba(34,211,238,0.4)',
              borderRadius: 999,
              padding: '8px 20px',
            }}
          >
            BOOKED
          </div>
        </div>

        {/* big code */}
        <div style={{ display: 'flex', flexDirection: 'column', marginTop: 44 }}>
          <div style={{ display: 'flex', fontSize: 24, letterSpacing: 4, color: '#9ca3af' }}>
            BOOKING CODE
          </div>
          <div
            style={{
              display: 'flex',
              fontSize: 132,
              fontWeight: 800,
              letterSpacing: 8,
              lineHeight: 1.05,
              backgroundImage: 'linear-gradient(90deg, #ffc800, #ff9f0a)',
              backgroundClip: 'text',
              color: 'transparent',
            }}
          >
            {booking.code}
          </div>
        </div>

        {/* selections */}
        <div style={{ display: 'flex', flexDirection: 'column', marginTop: 28, flex: 1 }}>
          {shown.map((s, i) => (
            <div
              key={i}
              style={{
                display: 'flex',
                flexDirection: 'column',
                backgroundColor: 'rgba(255,255,255,0.05)',
                border: '1px solid rgba(255,255,255,0.08)',
                borderRadius: 18,
                padding: '16px 22px',
                marginBottom: 12,
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div style={{ display: 'flex', fontSize: 30, fontWeight: 700 }}>{s.match}</div>
                <div style={{ display: 'flex', fontSize: 30, fontWeight: 800, color: '#22d3ee' }}>
                  {Number(s.odds).toFixed(2)}
                </div>
              </div>
              <div style={{ display: 'flex', fontSize: 23, color: '#c4b5fd', marginTop: 4 }}>
                {s.pick}
                <span style={{ color: '#6b7280', marginLeft: 10 }}>· {s.market}</span>
              </div>
            </div>
          ))}
          {extra > 0 && (
            <div style={{ display: 'flex', fontSize: 24, color: '#9ca3af', marginTop: 4 }}>
              + {extra} more selection{extra > 1 ? 's' : ''}
            </div>
          )}
        </div>

        {/* footer: total odds + cta */}
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginTop: 20,
            paddingTop: 26,
            borderTop: '1px solid rgba(255,255,255,0.1)',
          }}
        >
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            <div style={{ display: 'flex', fontSize: 22, color: '#9ca3af' }}>TOTAL ODDS</div>
            <div style={{ display: 'flex', fontSize: 56, fontWeight: 800 }}>
              {booking.totalOdds.toFixed(2)}
            </div>
          </div>
          <div style={{ display: 'flex', fontSize: 26, color: '#e5e7eb', maxWidth: 420, textAlign: 'right' }}>
            Load this code on SnapWin to play
          </div>
        </div>
      </div>
    ),
    { width: 900, height: 1200 },
  )
}
