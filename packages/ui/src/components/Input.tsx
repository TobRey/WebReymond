import type { InputHTMLAttributes } from 'react';
import { useId } from 'react';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string;
  hint?: string;
  error?: string;
}

/**
 * Eingabefeld mit fest verbundenem Label.
 *
 * Das Label ist Pflicht, nicht optional: Ein Feld ohne Label ist für
 * Screenreader unbenutzbar – und wir bauen ein Portal, das jeder bedienen soll.
 */
export function Input({ label, hint, error, className, ...props }: InputProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

  return (
    <div className="wr-field">
      <label className="wr-field__label" htmlFor={id}>
        {label}
      </label>
      <input
        id={id}
        className={['wr-input', className].filter(Boolean).join(' ')}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        {...props}
      />
      {hint ? (
        <span className="wr-field__hint" id={hintId}>
          {hint}
        </span>
      ) : null}
      {error ? (
        <span className="wr-field__error" id={errorId} role="alert">
          {error}
        </span>
      ) : null}
    </div>
  );
}
