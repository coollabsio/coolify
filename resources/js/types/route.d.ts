import { RouteParamsWithQueryOverload } from 'ziggy-js';

declare module 'vue' {
  interface ComponentCustomProperties {
    route: RouteParamsWithQueryOverload;
  }
}
