import { initializeCopyButtonComponent } from './copy-button.js';
import { initializeNumberStepperComponent } from './number-stepper.js';
import { initializeRuntimeLogsComponent } from './runtime-logs.js';
import { initializeTerminalComponent } from './terminal.js';
import './traffic-globe.js';
import './traffic-table.js';
import { registerLivewireRequestFailureHandler } from './livewire-request-failure.js';
import { registerLivewireUpdateDedupe } from './livewire-update-dedupe.js';
import { parseSubmitAction } from './modal-confirmation.js';

// The canvases (Svelte, Svelte Flow) load only on pages that have one. After the
// import, each canvas mounts itself, also after later Livewire SPA navigation.
const canvases = [
    { selector: '[data-deploy-canvas]', load: () => import('./canvas/deploy/index.js').then((m) => m.initializeDeployCanvas()) },
    { selector: '[data-environment-canvas]', load: () => import('./canvas/environment/index.js').then((m) => m.initializeEnvironmentCanvas()) },
];
const loadCanvases = () => {
    for (const canvas of canvases) {
        if (!canvas.loading && document.querySelector(canvas.selector)) {
            canvas.loading = canvas.load();
        }
    }
};
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadCanvases);
} else {
    loadCanvases();
}
document.addEventListener('livewire:navigated', loadCanvases);

// Used by the modal-confirmation Blade component to call its submitAction.
window.parseModalSubmitAction = parseSubmitAction;

document.addEventListener('livewire:init', () => {
    registerLivewireRequestFailureHandler(window.Livewire);
    registerLivewireUpdateDedupe(window.Livewire);
});

// Livewire 3.5.19+ re-applies `x-cloak` to morphed elements during wire:navigate
// (via replaceHtmlAttributes). With `[x-cloak]{display:none}` on the app wrapper,
// this blanks the whole page on every navigation until Alpine re-processes it.
// Strip leftover x-cloak after each navigation; the initial-load FOUC guard stays.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[x-cloak]').forEach((el) => el.removeAttribute('x-cloak'));
});

// Register the terminal data provider before Alpine initializes the page.
// Keeping this registration independent from the current route also makes it
// available before Alpine processes terminal markup after wire:navigate.
document.addEventListener('alpine:init', initializeTerminalComponent);
document.addEventListener('alpine:init', initializeCopyButtonComponent);
document.addEventListener('alpine:init', initializeNumberStepperComponent);
document.addEventListener('alpine:init', initializeRuntimeLogsComponent);

/**
 * Smooth-scroll a settings section into view, then flash its border for 500ms
 * after the scroll has settled. Starting the flash immediately makes long
 * jumps (top → bottom) finish scrolling after the animation has already ended.
 *
 * @param {string|HTMLElement} target Section id or element
 */
/** A resource favicon loaded: show it in place of the logo. */
window.resourceLogoLoaded = function resourceLogoLoaded(img) {
  img.closest('[data-resource-logo]')?.querySelector('[data-resource-logo-image]')?.setAttribute('hidden', '');
  img.removeAttribute('hidden');
};

/** A resource logo failed to load: drop a favicon, try the fallback URL of a logo, then show the resource type icon instead. */
window.resourceLogoError = function resourceLogoError(img) {
  if (img.hasAttribute('data-resource-logo-favicon')) {
    img.remove();
    return;
  }
  if (img.dataset.fallback && img.src !== img.dataset.fallback) {
    img.src = img.dataset.fallback;
    return;
  }
  const wrapper = img.closest('[data-resource-logo]');
  wrapper?.querySelector('[data-resource-logo-image]')?.setAttribute('hidden', '');
  if (!wrapper?.querySelector('[data-resource-logo-favicon]:not([hidden])')) {
    wrapper?.querySelector('[data-resource-logo-icon]')?.removeAttribute('hidden');
  }
};

window.scrollToSettingsSection = function scrollToSettingsSection(target) {
    const el = typeof target === 'string' ? document.getElementById(target) : target;
    if (!el) {
        return;
    }

    if (typeof el._sectionHighlightCleanup === 'function') {
        el._sectionHighlightCleanup();
    }

    const runHighlight = () => {
        el.classList.remove('is-section-highlight');
        // Force reflow so the 500ms highlight can re-run on repeated clicks.
        void el.offsetWidth;
        el.classList.add('is-section-highlight');
        el._sectionHighlightTimer = window.setTimeout(() => {
            el.classList.remove('is-section-highlight');
        }, 500);
    };

    let finished = false;
    let rafId = 0;
    let scrollEndHandler = null;

    const cleanup = () => {
        if (rafId) {
            window.cancelAnimationFrame(rafId);
            rafId = 0;
        }
        if (scrollEndHandler) {
            window.removeEventListener('scrollend', scrollEndHandler);
            scrollEndHandler = null;
        }
        if (el._sectionHighlightTimer) {
            window.clearTimeout(el._sectionHighlightTimer);
            el._sectionHighlightTimer = null;
        }
    };

    const finish = () => {
        if (finished) {
            return;
        }
        finished = true;
        cleanup();
        runHighlight();
    };

    el._sectionHighlightCleanup = () => {
        finished = true;
        cleanup();
        el.classList.remove('is-section-highlight');
        el._sectionHighlightCleanup = null;
    };

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Prefer the native scrollend event when the browser fires it.
    scrollEndHandler = () => finish();
    window.addEventListener('scrollend', scrollEndHandler, { once: true });

    // Fallback: wait until the target's Y position is stable for a few frames
    // (covers browsers without scrollend, and no-op scrolls when already in view).
    let lastTop = null;
    let stableFrames = 0;
    let frames = 0;
    const maxFrames = 180; // ~3s safety cap

    const tick = () => {
        if (finished) {
            return;
        }

        frames += 1;
        const top = el.getBoundingClientRect().top;

        if (lastTop !== null && Math.abs(top - lastTop) < 0.5) {
            stableFrames += 1;
        } else {
            stableFrames = 0;
        }
        lastTop = top;

        // Skip the first couple frames so we don't flash before smooth scroll starts.
        if (frames > 4 && stableFrames >= 4) {
            finish();
            return;
        }

        if (frames >= maxFrames) {
            finish();
            return;
        }

        rafId = window.requestAnimationFrame(tick);
    };

    rafId = window.requestAnimationFrame(tick);
};

/**
 * Items for the "This page" scope of the command palette: the pages of the
 * settings sidebar (rendered by the server), then the visible sections of the
 * current page. Sections that the sidebar already lists are skipped.
 *
 * @returns {Array<{label: string, breadcrumb: string, search_text: string, href: string, navigate?: boolean, element?: HTMLElement}>}
 */
window.currentPageSearchItems = function currentPageSearchItems() {
    let items = [];
    try {
        items = JSON.parse(document.querySelector('[data-settings-search-items]')?.dataset.settingsSearchItems || '[]');
    } catch (e) {
        items = [];
    }

    const toPath = (href) => {
        const url = new URL(href, window.location.href);
        return url.pathname + url.hash;
    };
    const sidebarPaths = new Set(items.map((item) => toPath(item.href)));
    const headingSelector = ':scope > header :is(h1, h2, h3), :scope > .application-settings-section-header :is(h1, h2, h3)';

    document.querySelectorAll('.application-settings-section').forEach((section, index) => {
        if (section.closest('[role=dialog], .command-palette') || section.getClientRects().length === 0) {
            return;
        }
        if (section.id && sidebarPaths.has(window.location.pathname + '#' + section.id)) {
            return;
        }
        const label = (section.dataset.settingsSectionTitle ?? section.querySelector(headingSelector)?.textContent ?? '')
            .replace(/\s+/g, ' ')
            .trim();
        if (!label) {
            return;
        }
        items.push({ label, breadcrumb: 'Section', search_text: label + ' section', href: 'section-' + index, element: section });
    });

    return items;
};

// When a settings sub-section link navigates across pages (href="route#section-id"),
// scroll to that section once the destination page has rendered.
function scrollToHashSettingsSection() {
    const hash = window.location.hash;
    if (!hash || hash.length < 2) {
        return;
    }
    const id = decodeURIComponent(hash.slice(1));
    window.requestAnimationFrame(() => window.scrollToSettingsSection?.(id));
}
document.addEventListener('livewire:navigated', scrollToHashSettingsSection);
document.addEventListener('DOMContentLoaded', scrollToHashSettingsSection);
