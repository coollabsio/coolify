import { Button as ButtonPrimitive } from '@base-ui/react/button';
import { cva, type VariantProps } from 'class-variance-authority';
import type * as React from 'react';

import { cn } from '@/lib/utils';

const buttonVariants = cva(
    "group/button inline-flex shrink-0 items-center justify-center rounded-none border border-transparent bg-clip-padding text-xs font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive dark:aria-invalid:border-destructive/50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/80',
                coolify:
                    'border-coollabs bg-coollabs-50 text-coollabs-200 hover:bg-coollabs hover:text-white focus-visible:border-coollabs-100 focus-visible:bg-coollabs focus-visible:text-white dark:border-coollabs-100 dark:bg-coollabs/20 dark:text-white dark:hover:bg-coollabs-100 dark:hover:text-white dark:focus-visible:border-coollabs-100 dark:focus-visible:bg-coollabs-100',
                outline:
                    'border-border bg-background hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
                secondary:
                    'bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_5%)] aria-expanded:bg-secondary aria-expanded:text-secondary-foreground',
                ghost: 'hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50',
                destructive:
                    'bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:border-ring dark:bg-destructive/20 dark:hover:bg-destructive/30 dark:focus-visible:border-ring',
                delete: 'bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:border-ring dark:bg-destructive/20 dark:hover:bg-destructive/30 dark:focus-visible:border-ring',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-8 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2',
                xs: "h-6 gap-1 rounded-none px-2 text-xs has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*='size-'])]:size-3",
                sm: "h-7 gap-1 rounded-none px-2.5 has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*='size-'])]:size-3.5",
                lg: 'h-9 gap-1.5 px-2.5 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2',
                icon: 'size-8',
                'icon-xs': "size-6 rounded-none [&_svg:not([class*='size-'])]:size-3",
                'icon-sm': 'size-7 rounded-none',
                'icon-lg': 'size-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

type ButtonProps = React.ComponentProps<typeof ButtonPrimitive> & VariantProps<typeof buttonVariants>;

function Button({ className, variant = 'default', size = 'default', ...props }: ButtonProps) {
    return <ButtonPrimitive data-slot="button" className={cn(buttonVariants({ variant, size, className }))} {...props} />;
}

export { Button, buttonVariants };
