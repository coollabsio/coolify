import { route as ziggyRoute } from 'ziggy-js';

// Wrap the route function to always set absolute URLs to false
export function route(name, params, absolute = true, config) {
  const route = ziggyRoute(name, params, absolute, config);
  try {
    const origin = window.location.origin;
    const routeUrl = new URL(route);
    const originUrl = new URL(origin);
    if (routeUrl.protocol !== originUrl.protocol) {
      routeUrl.protocol = originUrl.protocol;
      route = routeUrl.toString();
    }
    return route
  } catch (error) {
    return route
  } finally {
    console.log(route)
  }
}