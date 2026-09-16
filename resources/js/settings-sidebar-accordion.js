// Alpine data provider for the collapsible resource settings sidebar
// (x-data="settingsSidebarAccordion({ activeGroup, storageKey })").
//
// Only the group that contains the current page is open by default; every group
// can be collapsed/expanded and the choice is remembered per resource type. The
// active group is always forced open on load so the current page stays reachable.
export function initializeSettingsSidebarAccordionComponent() {
    window.Alpine.data('settingsSidebarAccordion', (config = {}) => ({
        activeGroup: config.activeGroup || '',
        storageKey: config.storageKey || 'coolify.settings-sidebar',
        groups: {},
        init() {
            let stored = {};
            try {
                stored = JSON.parse(localStorage.getItem(this.storageKey)) || {};
            } catch (e) {
                stored = {};
            }
            this.groups = stored && typeof stored === 'object' ? stored : {};
        },
        isOpen(group) {
            // Explicit user choice wins (so the active group can be collapsed too);
            // otherwise only the active group is open by default.
            if (Object.prototype.hasOwnProperty.call(this.groups, group)) {
                return this.groups[group];
            }
            return group === this.activeGroup;
        },
        toggle(group) {
            this.groups = { ...this.groups, [group]: !this.isOpen(group) };
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(this.groups));
            } catch (e) {
                // ignore storage errors (private mode, quota, etc.)
            }
        },
    }));
}
