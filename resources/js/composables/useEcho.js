import { inject } from 'vue'

export function useEcho() {
    const echo = inject('echo')

    if (!echo) {
        throw new Error('Echo is not provided. Make sure it is provided in your app setup.')
    }

    return echo
}
