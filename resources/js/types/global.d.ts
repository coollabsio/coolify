declare function route(name: string, params?: Record<string, any>): string;

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof routeFn;
    }
}