import type { ReactNode } from 'react';
import Link from 'next/link';
import { Logo } from '@webreymond/ui';

/** Gemeinsamer Rahmen für Anmelden, Registrieren und Passwort-Seiten. */
export function AuthCard({
  locale,
  title,
  description,
  children,
  footer,
}: {
  locale: string;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
      <Link href={`/${locale}`} className="mb-10 self-start">
        <Logo />
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
      {description ? <p className="mt-2 text-sm text-content-muted">{description}</p> : null}

      <div className="mt-8">{children}</div>

      {footer ? <div className="mt-8 text-sm text-content-muted">{footer}</div> : null}
    </main>
  );
}
