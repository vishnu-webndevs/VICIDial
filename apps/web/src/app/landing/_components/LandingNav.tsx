"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { getSessionStorageState } from "@/lib/auth-session";

const navLinks = [
  { href: "/landing", label: "Home" },
  { href: "/landing/pricing", label: "Pricing" },
  { href: "/landing/about", label: "About" },
  { href: "/landing/contact", label: "Contact" },
];

export default function LandingNav() {
  const [isOpen, setIsOpen] = useState(false);
  const pathname = usePathname();
  const [isAuthenticated, setIsAuthenticated] = useState(false);

  useEffect(() => {
    const { token } = getSessionStorageState();
    setIsAuthenticated(Boolean(token));
  }, []);

  return (
    <nav className="sticky top-0 z-50 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        {/* Logo */}
        <Link href="/landing" className="flex items-center gap-2.5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 shadow-sm">
            <svg className="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2.5}
                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
              />
            </svg>
          </div>
          <span className="text-lg font-bold text-slate-900">WND Dialer</span>
        </Link>

        {/* Desktop Nav */}
        <div className="hidden items-center gap-1 md:flex">
          {navLinks.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                pathname === link.href
                  ? "bg-indigo-50 text-indigo-600"
                  : "text-slate-600 hover:bg-slate-50 hover:text-slate-900"
              }`}
            >
              {link.label}
            </Link>
          ))}
        </div>

        {/* Desktop CTA */}
        <div className="hidden items-center gap-3 md:flex">
          {isAuthenticated ? (
            <Link
              href="/dashboard"
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
            >
              Open Dashboard
            </Link>
          ) : (
            <>
              <Link
                href="/login"
                className="text-sm font-medium text-slate-600 transition-colors hover:text-slate-900"
              >
                Sign in
              </Link>
              <Link
                href="/register"
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
              >
                Start Free Trial
              </Link>
            </>
          )}
        </div>

        {/* Mobile Menu Toggle */}
        <button
          onClick={() => setIsOpen(!isOpen)}
          className="rounded-md p-2 text-slate-600 hover:bg-slate-50 md:hidden"
          aria-label="Toggle navigation menu"
        >
          {isOpen ? (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          ) : (
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          )}
        </button>
      </div>

      {/* Mobile Menu */}
      {isOpen && (
        <div className="border-t border-slate-100 bg-white px-6 pb-4 md:hidden">
          <div className="flex flex-col gap-1 pt-2">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setIsOpen(false)}
                className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  pathname === link.href
                    ? "bg-indigo-50 text-indigo-600"
                    : "text-slate-700 hover:bg-slate-50"
                }`}
              >
                {link.label}
              </Link>
            ))}
            <hr className="my-2 border-slate-100" />
            {isAuthenticated ? (
              <Link
                href="/dashboard"
                onClick={() => setIsOpen(false)}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-semibold text-white"
              >
                Open Dashboard
              </Link>
            ) : (
              <>
                <Link
                  href="/login"
                  onClick={() => setIsOpen(false)}
                  className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                  Sign in
                </Link>
                <Link
                  href="/register"
                  onClick={() => setIsOpen(false)}
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-semibold text-white"
                >
                  Start Free Trial
                </Link>
              </>
            )}
          </div>
        </div>
      )}
    </nav>
  );
}
