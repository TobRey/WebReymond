import type { HTMLAttributes, ReactNode } from 'react';

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  title?: string;
  description?: string;
  children?: ReactNode;
}

/** Ruhiger Container für zusammengehörende Inhalte. */
export function Card({ title, description, children, className, ...props }: CardProps) {
  const classes = ['wr-card', className].filter(Boolean).join(' ');
  return (
    <div className={classes} {...props}>
      {title ? <h3 className="wr-card__title">{title}</h3> : null}
      {description ? <p className="wr-card__description">{description}</p> : null}
      {children}
    </div>
  );
}
