import type * as React from 'react';

import { cn } from '@/lib/utils';

type FieldProps = React.ComponentProps<'label'>;

function Field({ className, ...props }: FieldProps) {
    return <label data-slot="field" className={cn('flex flex-col gap-1 text-sm', className)} {...props} />;
}

type FieldLabelProps = React.ComponentProps<'span'>;

function FieldLabel({ className, ...props }: FieldLabelProps) {
    return <span data-slot="field-label" className={cn('font-medium text-foreground', className)} {...props} />;
}

type FieldErrorProps = React.ComponentProps<'span'> & {
    message?: string;
};

function FieldError({ className, message, ...props }: FieldErrorProps) {
    return (
        <span
            aria-hidden={message ? undefined : true}
            aria-live="polite"
            data-slot="field-error"
            className={cn('min-h-4 text-xs leading-4 text-destructive', className)}
            {...props}
        >
            {message ?? ''}
        </span>
    );
}

export { Field, FieldError, FieldLabel };
