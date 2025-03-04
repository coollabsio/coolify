import { route as ziggyRoute } from 'ziggy-js';

// Wrap the route function to always set absolute URLs to false
export function route(name, params, absolute = true, config) {
  const route = ziggyRoute(name, params, absolute, config);
  try {
    if (absolute === false) {
      return route
    }

    const origin = window.location.origin;
    const routeUrl = new URL(route);
    const originUrl = new URL(origin);
    console.log(routeUrl.protocol)
    console.log(originUrl.protocol)
    if (routeUrl.protocol !== originUrl.protocol) {
      routeUrl.protocol = originUrl.protocol;
      route = routeUrl.toString();
    }
    return route
  } catch (error) {
    console.error(error)
    return route
  } finally {
    console.log(route)
  }
}