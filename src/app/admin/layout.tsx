'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import {
  LayoutDashboard,
  Receipt,
  Trophy,
  PlusSquare,
  UserCheck,
  Users2,
  Wallet,
  Settings,
  LogOut,
  Menu,
  X,
} from 'lucide-react'
import { useState } from 'react'

const NAV = [
  { href: '/admin', label: 'Overview', icon: LayoutDashboard, exact: true },
  { href: '/admin/bets', label: 'Bets', icon: Receipt },
  { href: '/admin/matches', label: 'Matches', icon: Trophy },
  { href: '/admin/custom-matches', label: 'Custom games', icon: PlusSquare },
  { href: '/admin/deposits', label: 'Payments', icon: Wallet },
  { href: '/admin/users', label: 'Players', icon: UserCheck },
  { href: '/admin/sub-admins', label: 'Partners', icon: Users2 },
  { href: '/admin/settings', label: 'Settings', icon: Settings },
]

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  const router = useRouter()
  const [mobileOpen, setMobileOpen] = useState(false)

  // The /admin/login page renders standalone — it has its own layout.
  if (pathname === '/admin/login') {
    return <>{children}</>
  }

  const handleLogout = async () => {
    await fetch('/api/admin/logout', { method: 'POST' })
    router.push('/admin/login')
    router.refresh()
  }

  return (
    <div className="min-h-screen bg-background flex flex-col lg:flex-row">
      {/* Mobile top bar */}
      <header className="lg:hidden bg-card border-b border-border sticky top-0 z-40 shadow-card">
        <div className="flex items-center justify-between px-3 h-14">
          <div className="flex items-center gap-2 min-w-0">
            <button
              onClick={() => setMobileOpen(true)}
              className="p-2 -ml-1 rounded-lg hover:bg-secondary transition-colors"
              aria-label="Open menu"
            >
              <Menu className="w-5 h-5" />
            </button>
            <Link href="/admin" className="flex items-center" aria-label="Admin home">
              <span className="font-display font-extrabold tracking-tight text-lg leading-none">
                Snap<span className="text-primary">Win</span>
              </span>
            </Link>
            <span className="text-eyebrow text-primary border border-primary/30 bg-primary/10 rounded-full px-2 py-0.5">
              Admin
            </span>
          </div>
          <button
            onClick={handleLogout}
            className="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
            aria-label="Logout"
          >
            <LogOut className="w-5 h-5" />
          </button>
        </div>
      </header>

      {/* Desktop sidebar */}
      <aside className="hidden lg:flex w-60 bg-card border-r border-border flex-col">
        <div className="px-5 py-5 border-b border-border flex items-center gap-3">
          <Link href="/admin" className="flex items-center" aria-label="Admin home">
            <span className="font-display font-extrabold tracking-tight text-lg leading-none">
              Snap<span className="text-primary">Win</span>
            </span>
          </Link>
          <span className="text-eyebrow text-primary border border-primary/30 bg-primary/10 rounded-full px-2 py-0.5">
            Admin
          </span>
        </div>
        <NavList pathname={pathname} onNavigate={() => {}} />
        <div className="px-3 py-4 border-t border-border space-y-1.5">
          <button
            onClick={handleLogout}
            className="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
          >
            <LogOut className="w-4 h-4" />
            <span>Logout</span>
          </button>
          <Link
            href="/"
            className="w-full flex items-center justify-center gap-1 text-xs text-muted-foreground hover:text-primary transition-colors"
          >
            ← Back to site
          </Link>
        </div>
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="lg:hidden fixed inset-0 z-50">
          <div
            className="absolute inset-0 bg-background/80 backdrop-blur-sm animate-in fade-in duration-200"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="absolute left-0 top-0 bottom-0 w-64 bg-card border-r border-border flex flex-col shadow-popover animate-in slide-in-from-left duration-200">
            <div className="px-5 py-5 border-b border-border flex items-center justify-between">
              <div className="flex items-center gap-2 min-w-0">
                <span className="font-display font-extrabold tracking-tight text-lg leading-none">
                  Snap<span className="text-primary">Win</span>
                </span>
                <span className="text-eyebrow text-primary border border-primary/30 bg-primary/10 rounded-full px-2 py-0.5">
                  Admin
                </span>
              </div>
              <button
                onClick={() => setMobileOpen(false)}
                className="p-1.5 rounded-lg hover:bg-secondary transition-colors"
                aria-label="Close menu"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <NavList pathname={pathname} onNavigate={() => setMobileOpen(false)} />
            <div className="px-3 py-4 border-t border-border space-y-1.5">
              <button
                onClick={() => {
                  setMobileOpen(false)
                  void handleLogout()
                }}
                className="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
              >
                <LogOut className="w-4 h-4" />
                <span>Logout</span>
              </button>
              <Link
                href="/"
                onClick={() => setMobileOpen(false)}
                className="block text-center text-xs text-muted-foreground hover:text-primary transition-colors"
              >
                ← Back to site
              </Link>
            </div>
          </aside>
        </div>
      )}

      <main className="flex-1 min-w-0">{children}</main>
    </div>
  )
}

function NavList({
  pathname,
  onNavigate,
}: {
  pathname: string
  onNavigate: () => void
}) {
  return (
    <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
      {NAV.map(({ href, label, icon: Icon, exact }) => {
        const active = exact ? pathname === href : pathname.startsWith(href)
        return (
          <Link
            key={href}
            href={href}
            onClick={onNavigate}
            className={`group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition-all ${
              active
                ? 'bg-primary text-primary-foreground shadow-card-pressed'
                : 'text-foreground hover:bg-secondary hover:translate-x-0.5'
            }`}
          >
            <Icon
              className={`w-4 h-4 shrink-0 transition-transform ${
                active ? '' : 'group-hover:scale-110'
              }`}
            />
            <span>{label}</span>
          </Link>
        )
      })}
    </nav>
  )
}
