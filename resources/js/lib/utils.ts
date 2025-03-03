import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export const inputType = 'w-full rounded-xl border border-l-4 border-input bg-input-background px-3 py-2 text-sm';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

