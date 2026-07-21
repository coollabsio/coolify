import type { AnchorHTMLAttributes, ReactNode } from 'react';

/**
 * Full browser navigation into the classic Livewire UI.
 *
 * Never use Inertia `<Link>` for classic destinations — soft visits leave a
 * hybrid React/Livewire shell. Prefer this (or a plain `<a href>`) for every
 * link that points at a Livewire/Blade route.
 */
export function ClassicLink({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { children?: ReactNode }) {
    return <a data-classic-nav="true" {...props}>{children}</a>;
}
