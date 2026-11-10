/**
 * Starred pages of the resource menus (application, service, database, server).
 * A star is a route name. Stars are saved per user and per menu, in the order
 * the user gives them, so one star applies to every resource of that menu type.
 */

const saveUrl = '/profile/starred-menu-items';

// Saves run one after the other, so the last saved order always wins.
let pendingSave = Promise.resolve();

// Number of the last save started per menu, so only the newest save can roll back.
const lastSaveId = {};

/**
 * Registers the store and the group component. The layout renders the saved stars
 * (#starred-menu-items) once per full page load; after that the store is the source.
 */
export function initializeStarredMenu() {
    let menus = {};
    try {
        menus = JSON.parse(document.getElementById('starred-menu-items')?.textContent || '{}') || {};
    } catch (e) {
        menus = {};
    }
    window.Alpine.store('starredMenu', createStarredMenuStore(menus));
    window.Alpine.data('starredMenuGroup', starredMenuGroup);
}

/**
 * @param {Record<string, string[]>} initialMenus
 */
function createStarredMenuStore(initialMenus) {
    return {
        menus: initialMenus,

        // The stars that the server has, per menu. A failed save goes back to them.
        saved: structuredClone(initialMenus),

        keys(menu) {
            return this.menus[menu] ?? [];
        },

        isStarred(menu, key) {
            return this.keys(menu).includes(key);
        },

        toggle(menu, key) {
            const keys = this.keys(menu);
            this.menus[menu] = keys.includes(key) ? keys.filter((k) => k !== key) : [...keys, key];
            this.save(menu);
        },

        /** Moves `key` to the position of `targetKey`, without a save (drag in progress). */
        move(menu, key, targetKey) {
            const keys = [...this.keys(menu)];
            const from = keys.indexOf(key);
            const to = keys.indexOf(targetKey);
            if (from === -1 || to === -1 || from === to) {
                return;
            }
            keys.splice(from, 1);
            keys.splice(to, 0, key);
            this.menus[menu] = keys;
        },

        save(menu) {
            const items = [...this.keys(menu)];
            const saveId = (lastSaveId[menu] ?? 0) + 1;
            lastSaveId[menu] = saveId;
            pendingSave = pendingSave
                .then(() => fetch(saveUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ menu, items }),
                }))
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    this.saved[menu] = items;
                })
                .catch(() => {
                    // A newer save or a drag in progress has the newer state: keep it.
                    const unchanged = JSON.stringify(this.keys(menu)) === JSON.stringify(items);
                    if (lastSaveId[menu] === saveId && unchanged) {
                        this.menus[menu] = [...(this.saved[menu] ?? [])];
                    }
                    window.toast?.('Could not save the starred menu items.', { type: 'warning' });
                });
        },
    };
}

/**
 * The "Starred" group at the top of one resource menu. It shows the starred
 * pages that this menu has, in the saved order, and lets the user reorder them.
 *
 * @param {string} menu
 * @param {string[]} menuKeys  Route names of the pages that this menu shows
 */
function starredMenuGroup(menu, menuKeys) {
    return {
        dragging: null,

        get store() {
            return window.Alpine.store('starredMenu');
        },

        get starred() {
            return this.store.keys(menu).filter((key) => menuKeys.includes(key));
        },

        position(key) {
            return this.starred.indexOf(key);
        },

        dragStart(event, key) {
            this.dragging = key;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', key);
        },

        dragOver(key) {
            if (this.dragging && this.dragging !== key) {
                this.store.move(menu, this.dragging, key);
            }
        },

        dragEnd() {
            if (this.dragging) {
                this.dragging = null;
                this.store.save(menu);
            }
        },

        /** Keyboard reorder (Alt+Arrow): swaps with the next shown starred page. */
        moveBy(key, offset) {
            const neighbour = this.starred[this.position(key) + offset];
            if (neighbour) {
                this.store.move(menu, key, neighbour);
                this.store.save(menu);
            }
        },
    };
}

/**
 * Command palette items for the resource menu of the current page:
 * star or unstar the current page, and open each starred page.
 *
 * @returns {Array<{key: string, label: string, breadcrumb: string, search_text: string, href: string, navigate?: boolean, action?: Function}>}
 */
export function starredMenuCommands() {
    const element = document.querySelector('[data-starred-menu]');
    const store = window.Alpine?.store('starredMenu');
    if (!element || !store) {
        return [];
    }

    let menu;
    try {
        menu = JSON.parse(element.dataset.starredMenu);
    } catch (e) {
        return [];
    }

    const commands = [];
    const current = menu.items.find((item) => item.active);
    if (current) {
        const starred = store.isStarred(menu.menu, current.key);
        commands.push({
            key: 'starred-toggle',
            label: (starred ? 'Unstar' : 'Star') + ' "' + current.label + '"',
            breadcrumb: starred ? 'Remove the current page from Starred' : 'Add the current page to Starred',
            search_text: 'star unstar starred favorite pin ' + current.label,
            href: current.href,
            action: () => store.toggle(menu.menu, current.key),
        });
    }

    for (const key of store.keys(menu.menu)) {
        const item = menu.items.find((menuItem) => menuItem.key === key);
        if (item) {
            commands.push({
                key: 'starred-' + key,
                label: 'Go to Starred: ' + item.label,
                breadcrumb: 'Starred',
                search_text: 'starred ' + item.label,
                href: item.href,
                navigate: item.navigate,
            });
        }
    }

    return commands;
}
