import type * as React from 'react';

import { cn } from '@/lib/utils';

function Input({ className, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            data-slot="input"
            className={cn(
                'rounded-md border border-border bg-background px-3 py-2 text-sm outline-none transition focus:border-ring focus:ring-1 focus:ring-ring aria-invalid:border-destructive aria-invalid:ring-1 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
