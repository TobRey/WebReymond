import type { ButtonHTMLAttributes, ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  children: ReactNode;
}

/**
 * Standardschaltfläche.
 *
 * `type` ist bewusst auf "button" vorbelegt: Der HTML-Standard wäre "submit",
 * was in Formularen regelmässig ungewollte Absendungen auslöst.
 */
export function Button({ variant = 'primary', className, type = 'button', ...props }: ButtonProps) {
  const classes = ['wh-button', `wh-button--${variant}`, className].filter(Boolean).join(' ');
  return <button type={type} className={classes} {...props} />;
}
