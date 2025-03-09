import { inject } from 'vue'

interface Echo {
    private(channel: string): EchoChannel;
    // Add other Echo methods as needed
}

interface EchoChannel {
    listen(event: string, callback: (e: any) => void): void;
    // Add other channel methods as needed
}

export function useEcho(): Echo {
    const echo = inject('echo') as Echo | undefined

    if (!echo) {
        throw new Error('Echo is not provided. Make sure it is provided in your app setup.')
    }

    return echo
}

export function useEchoPrivate(channel: string): EchoChannel {
    const echo = useEcho()
    return echo.private(channel)
}
