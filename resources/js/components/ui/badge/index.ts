import { cva, type VariantProps } from 'class-variance-authority'

export { default as Badge } from './Badge.vue'

export const badgeVariants = cva(
    'inline-flex items-center rounded-xl border border-neutral-200 px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-neutral-950 focus:ring-offset-2 dark:focus:ring-neutral-300',
    {
        variants: {
            variant: {
                default:
                    'border-transparent bg-neutral-900 text-neutral-50 hover:bg-neutral-900/80 dark:bg-neutral-50 dark:text-neutral-900 dark:hover:bg-neutral-50/80',
                secondary:
                    'border-transparent bg-neutral-100 text-neutral-900 hover:bg-neutral-100/80 dark:bg-neutral-800 dark:text-neutral-50 dark:hover:bg-neutral-800/80',
                destructive:
                    'dark:border-red-500 bg-red-500 text-neutral-50 hover:bg-red-500/80 dark:bg-red-900 dark:text-neutral-50 dark:hover:bg-red-900/80',
                outline: 'text-neutral-950 dark:text-neutral-50',
                success: 'border-success bg-success/10 text-success',
                circle: 'rounded-full',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
)

export type BadgeVariants = VariantProps<typeof badgeVariants>
