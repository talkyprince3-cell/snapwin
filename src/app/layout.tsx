import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import Script from "next/script";
import "./globals.css";
// The banner's stylesheet. It lives here rather than beside the script in
// public/ so Next bundles it: a hand-written <link> is unoptimised and is
// what the no-css-tags rule is about. It references no assets of its own —
// the script sets the image and tone from assetBase — so it moved cleanly.
import "./withdrawal-notification.css";
import { PwaRegister } from "@/components/pwa-register";
import { WithdrawalIosBoot } from "@/components/withdrawal-ios-boot";

/**
 * Inter for everything, as the reference does — one family across display and
 * body rather than pairing a second face with it. next/font self-hosts it and
 * generates a metric-matched fallback, so there is no layout shift while it
 * loads. Mono is the system stack; no webfont is fetched for it.
 *
 * Both --font-display and --font-body point here so the existing
 * `font-display` utilities keep working untouched.
 */
const inter = Inter({
  variable: "--font-sans",
  subsets: ["latin"],
  // No `weight`: Inter is a variable font, so the whole 100-900 axis ships in
  // one file. Listing weights forces a static instance per weight — seven
  // files for what the reference serves in one.
  display: "swap",
});

export const metadata: Metadata = {
  title: "SnapWin — Premium Sports Betting",
  description:
    "SnapWin — premium international sports betting. Live odds, mobile-money payouts, verified tickets.",
  manifest: "/manifest.webmanifest",
  applicationName: "SnapWin",
  appleWebApp: {
    capable: true,
    title: "SnapWin",
    statusBarStyle: "black-translucent",
  },
  icons: {
    icon: [
      { url: "/icon-192.png", sizes: "192x192", type: "image/png" },
      { url: "/icon-512.png", sizes: "512x512", type: "image/png" },
    ],
    apple: "/apple-touch-icon.png",
  },
};

export const viewport: Viewport = {
  themeColor: "#0b1b33",
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${inter.variable} antialiased`}
    >
      <body suppressHydrationWarning>
        {/* The phone-style withdrawal banner. Loaded for everyone because the
            layout cannot know who is signed in, but it only ever plays when
            the withdraw response says to — which is after the deposit
            verification, or for an admin or approved partner who skips it.
            next/script rather than a bare <script>: a synchronous tag in
            <head> is what the no-sync-scripts rule is about. */}
        <Script
          src="/withdrawal-notification/withdrawal-notification.js"
          strategy="beforeInteractive"
        />
        <WithdrawalIosBoot />
        <PwaRegister />
        {children}
      </body>
    </html>
  );
}
