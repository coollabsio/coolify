import { route as ziggyRoute } from 'ziggy-js';

export function route(name, params, absolute = true, config) {
  let route = ziggyRoute(name, params, absolute, config);
  try {
    if (absolute === false) {
      return route
    }

    const originUrl = new URL(window.location.origin);
    let routeUrl = new URL(route);
    if (routeUrl.protocol !== originUrl.protocol) {
      routeUrl.protocol = originUrl.protocol;
      route = routeUrl.toString();
    }
    return route
  } catch (error) {
    console.error(error)
    return route
  }
}