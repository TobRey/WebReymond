import type { SVGProps } from 'react';

export interface LogoProps {
  /** Nur das Bildzeichen (Wolke mit W), ohne Schriftzug. */
  markOnly?: boolean;
  /** Grösse des Bildzeichens in Pixeln. */
  size?: number;
  className?: string;
}

/**
 * Bildzeichen: Wolke mit W, im Markenverlauf Türkis → Blau.
 *
 * Vektorfassung des WebHeaven-Logos. Liegt eine offizielle SVG-Datei vor,
 * kann sie hier eins zu eins eingesetzt werden – der Rest der Anwendung
 * verwendet ausschliesslich diese Komponente und muss nicht angefasst werden.
 */
function LogoMark({ size = 32, ...props }: { size?: number } & SVGProps<SVGSVGElement>) {
  return (
    <svg
      width={size}
      height={(size * 48) / 64}
      viewBox="0 0 64 48"
      fill="none"
      aria-hidden="true"
      focusable="false"
      {...props}
    >
      <defs>
        <linearGradient id="wh-logo-gradient" x1="4" y1="40" x2="60" y2="8">
          <stop offset="0%" stopColor="var(--wh-brand-cyan)" />
          <stop offset="100%" stopColor="var(--wh-brand-blue)" />
        </linearGradient>
      </defs>

      {/* Wolke als offene Kontur */}
      <path
        d="M20 39h24a11 11 0 0 0 1.2-21.9A14 14 0 0 0 18.6 14.2 10.4 10.4 0 0 0 20 39Z"
        stroke="url(#wh-logo-gradient)"
        strokeWidth="4.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/* W */}
      <path
        d="M21.5 17.5 27 33l5-9.5 5 9.5 5.5-15.5"
        stroke="url(#wh-logo-gradient)"
        strokeWidth="4.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/**
 * Logo mit Schriftzug.
 *
 * „Web" steht in der Textfarbe, „Heaven" im Markenverlauf – wie im Original.
 * Der Verlauf ist mit einer einfarbigen Rückfallebene hinterlegt, falls ein
 * Browser `background-clip: text` nicht beherrscht.
 */
export function Logo({ markOnly = false, size = 32, className }: LogoProps) {
  const classes = ['wh-logo', className].filter(Boolean).join(' ');

  if (markOnly) {
    return (
      <span className={classes}>
        <LogoMark size={size} />
        <span className="wh-visually-hidden">WebHeaven</span>
      </span>
    );
  }

  return (
    <span className={classes}>
      <LogoMark size={size} />
      <span className="wh-logo__wordmark">
        <span className="wh-logo__web">Web</span>
        <span className="wh-logo__heaven">Heaven</span>
      </span>
    </span>
  );
}
