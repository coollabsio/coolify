interface Echo {
    private(channel: string): EchoChannel;
    // Add other Echo methods as needed
}

interface EchoChannel {
    listen(event: string, callback: (e: any) => void): void;
    // Add other channel methods as needed
}

export function useEcho(): Echo;
export function useEchoPrivate(channel: string): EchoChannel;
