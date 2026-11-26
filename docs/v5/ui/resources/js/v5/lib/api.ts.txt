/**
 * General-purpose JSON request helper for v5 pages: same-origin credentials,
 * CSRF header, and a 30 second timeout on every request. The implementation
 * currently lives in canvas-api.ts; import from this module in non-canvas
 * code so the canvas-specific name can be retired later.
 */
export { canvasRequest as apiRequest } from '@/lib/canvas-api';
