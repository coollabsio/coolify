import { writable, derived, readable } from 'svelte/store';

export const dashboard = writable({})
export const dateOptions = {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
    hour12: false
  }