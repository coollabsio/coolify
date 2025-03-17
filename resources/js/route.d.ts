import { RouteParamsWithQueryOverload } from 'ziggy-js';

declare module '@/route' {
  export function route(name: string, params?: Record<string, any> | RouteParamsWithQueryOverload, absolute?: boolean, config?: any): string;
}