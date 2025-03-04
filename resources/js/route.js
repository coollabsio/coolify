import { route as ziggyRoute } from 'ziggy-js';

// Wrap the route function to always set absolute URLs to false
export function route(name, params, absolute = false, config) {
  return ziggyRoute(name, params, absolute, config);
}