import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import "./globals.css";
import { PwaRegister } from "@/components/pwa-register";

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
        <PwaRegister />
        {children}
      </body>
    </html>
  );
}
