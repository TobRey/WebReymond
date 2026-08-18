/**
 * Rückmeldung unter einem Formular.
 *
 * `role="alert"` sorgt dafür, dass Screenreader die Meldung sofort vorlesen –
 * sonst bemerken blinde Nutzerinnen einen Fehler gar nicht.
 */
export function FormMessage({ kind, children }: { kind: 'error' | 'success'; children: string }) {
  return (
    <p
      role="alert"
      data-testid="form-message"
      className={
        kind === 'error'
          ? 'mt-4 rounded-token-md bg-surface-muted px-4 py-3 text-sm text-content'
          : 'mt-4 rounded-token-md bg-accent-subtle px-4 py-3 text-sm text-content'
      }
    >
      {children}
    </p>
  );
}
