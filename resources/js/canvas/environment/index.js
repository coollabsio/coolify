// Environment canvas page mount (initial load and Livewire SPA navigation).
import { mount, unmount } from 'svelte';
import { mountOnPage } from '../../canvas-core.js';
import EnvironmentCanvas from './EnvironmentCanvas.svelte';

export function initializeEnvironmentCanvas() {
  mountOnPage('[data-environment-canvas]', '[data-ec-root]', container => {
    const app = mount(EnvironmentCanvas, { target: container, props: { container } });
    return { root: container.querySelector('[data-ec-root]'), unmount: () => unmount(app) };
  });
}
