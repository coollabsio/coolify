import { Select as SelectPrimitive } from '@base-ui/react/select';
import { CaretDownIcon, CaretUpIcon, CheckIcon } from '@phosphor-icons/react';
import type * as React from 'react';

import { cn } from '@/lib/utils';

const Select = SelectPrimitive.Root;

type SelectGroupProps = React.ComponentProps<typeof SelectPrimitive.Group>;

function SelectGroup({ className, ...props }: SelectGroupProps) {
    return <SelectPrimitive.Group data-slot="select-group" className={cn('scroll-my-1', className)} {...props} />;
}

type SelectValueProps = React.ComponentProps<typeof SelectPrimitive.Value>;

function SelectValue({ className, ...props }: SelectValueProps) {
    return <SelectPrimitive.Value data-slot="select-value" className={cn('flex flex-1 text-left', className)} {...props} />;
}

type SelectTriggerProps = React.ComponentProps<typeof SelectPrimitive.Trigger> & {
    size?: 'default' | 'sm';
    variant?: 'default' | 'ghost';
};

function SelectTrigger({ className, size = 'default', variant = 'default', children, ...props }: SelectTriggerProps) {
    return (
        <SelectPrimitive.Trigger
            data-slot="select-trigger"
            data-size={size}
            data-variant={variant}
            className={cn(
                "flex w-fit items-center justify-between gap-1.5 rounded-none whitespace-nowrap transition-colors outline-none select-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive data-placeholder:text-muted-foreground data-[size=default]:h-8 data-[size=sm]:h-7 data-[size=sm]:rounded-none *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-1.5 dark:aria-invalid:border-destructive/50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                variant === 'default' &&
                    'border border-input bg-transparent py-2 pr-2 pl-2.5 text-xs focus-visible:border-ring dark:bg-input/30 dark:hover:bg-input/50',
                variant === 'ghost' &&
                    'h-auto border border-transparent bg-transparent px-0 py-0 text-sm font-medium text-foreground hover:bg-transparent focus-visible:border-ring dark:bg-transparent dark:hover:bg-transparent',
                className,
            )}
            {...props}
        >
            {children}
            <SelectPrimitive.Icon render={<CaretDownIcon className="pointer-events-none size-4 text-muted-foreground" />} />
        </SelectPrimitive.Trigger>
    );
}

type SelectPositionerProps = React.ComponentProps<typeof SelectPrimitive.Positioner>;
type SelectPopupProps = React.ComponentProps<typeof SelectPrimitive.Popup>;

type SelectContentProps = SelectPopupProps & {
    position?: 'popper' | 'item-aligned';
    side?: SelectPositionerProps['side'];
    sideOffset?: SelectPositionerProps['sideOffset'];
    align?: SelectPositionerProps['align'];
    alignOffset?: SelectPositionerProps['alignOffset'];
    alignItemWithTrigger?: SelectPositionerProps['alignItemWithTrigger'];
};

function SelectContent({
    className,
    children,
    position,
    side = 'bottom',
    sideOffset = 4,
    align = 'center',
    alignOffset = 0,
    alignItemWithTrigger = position === 'popper' ? false : true,
    ...props
}: SelectContentProps) {
    return (
        <SelectPrimitive.Portal>
            <SelectPrimitive.Positioner
                side={side}
                sideOffset={sideOffset}
                align={align}
                alignOffset={alignOffset}
                alignItemWithTrigger={alignItemWithTrigger}
                className="isolate z-50"
            >
                <SelectPrimitive.Popup
                    data-slot="select-content"
                    data-align-trigger={alignItemWithTrigger}
                    className={cn(
                        'relative isolate z-50 max-h-(--available-height) w-(--anchor-width) min-w-36 origin-(--transform-origin) overflow-x-hidden overflow-y-auto rounded-none bg-popover text-popover-foreground shadow-md ring-1 ring-foreground/10 duration-100 data-[align-trigger=true]:animate-none data-[side=bottom]:slide-in-from-top-2 data-[side=inline-end]:slide-in-from-left-2 data-[side=inline-start]:slide-in-from-right-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
                        className,
                    )}
                    {...props}
                >
                    <SelectScrollUpButton />
                    <SelectPrimitive.List>{children}</SelectPrimitive.List>
                    <SelectScrollDownButton />
                </SelectPrimitive.Popup>
            </SelectPrimitive.Positioner>
        </SelectPrimitive.Portal>
    );
}

type SelectLabelProps = React.ComponentProps<typeof SelectPrimitive.GroupLabel>;

function SelectLabel({ className, ...props }: SelectLabelProps) {
    return (
        <SelectPrimitive.GroupLabel
            data-slot="select-label"
            className={cn('px-2 py-2 text-xs text-muted-foreground', className)}
            {...props}
        />
    );
}

type SelectItemProps = React.ComponentProps<typeof SelectPrimitive.Item>;

function SelectItem({ className, children, ...props }: SelectItemProps) {
    return (
        <SelectPrimitive.Item
            data-slot="select-item"
            className={cn(
                "relative flex w-full cursor-default items-center gap-2 rounded-none py-2 pr-8 pl-2 text-xs outline-hidden select-none focus:bg-accent focus:text-accent-foreground not-data-[variant=destructive]:focus:**:text-accent-foreground data-disabled:pointer-events-none data-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 *:[span]:last:flex *:[span]:last:items-center *:[span]:last:gap-2",
                className,
            )}
            {...props}
        >
            <SelectPrimitive.ItemText className="flex flex-1 shrink-0 gap-2 whitespace-nowrap">{children}</SelectPrimitive.ItemText>
            <SelectPrimitive.ItemIndicator
                render={<span className="pointer-events-none absolute right-2 flex size-4 items-center justify-center" />}
            >
                <CheckIcon className="pointer-events-none" />
            </SelectPrimitive.ItemIndicator>
        </SelectPrimitive.Item>
    );
}

type SelectSeparatorProps = React.ComponentProps<typeof SelectPrimitive.Separator>;

function SelectSeparator({ className, ...props }: SelectSeparatorProps) {
    return <SelectPrimitive.Separator data-slot="select-separator" className={cn('pointer-events-none -mx-1 h-px bg-border', className)} {...props} />;
}

type SelectScrollUpButtonProps = React.ComponentProps<typeof SelectPrimitive.ScrollUpArrow>;

function SelectScrollUpButton({ className, ...props }: SelectScrollUpButtonProps) {
    return (
        <SelectPrimitive.ScrollUpArrow
            data-slot="select-scroll-up-button"
            className={cn('top-0 z-10 flex w-full cursor-default items-center justify-center bg-popover py-1 [&_svg:not([class*=\'size-\'])]:size-4', className)}
            {...props}
        >
            <CaretUpIcon />
        </SelectPrimitive.ScrollUpArrow>
    );
}

type SelectScrollDownButtonProps = React.ComponentProps<typeof SelectPrimitive.ScrollDownArrow>;

function SelectScrollDownButton({ className, ...props }: SelectScrollDownButtonProps) {
    return (
        <SelectPrimitive.ScrollDownArrow
            data-slot="select-scroll-down-button"
            className={cn('bottom-0 z-10 flex w-full cursor-default items-center justify-center bg-popover py-1 [&_svg:not([class*=\'size-\'])]:size-4', className)}
            {...props}
        >
            <CaretDownIcon />
        </SelectPrimitive.ScrollDownArrow>
    );
}

export {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectScrollDownButton,
    SelectScrollUpButton,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
};
