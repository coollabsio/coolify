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
        // Optional client-side filter (sidebars that render a search box).
        search: '',
        labels: Array.isArray(config.labels) ? config.labels : [],
        get searching() {
            return this.search.trim() !== '';
        },
        matches(label) {
            if (!this.searching) {
                return true;
            }
            return String(label).toLowerCase().includes(this.search.trim().toLowerCase());
        },
        get hasResults() {
            return !this.searching || this.labels.some((label) => this.matches(label));
        },
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
            // The current page must stay visible, even when this group was
            // previously stored as collapsed on another page.
            if (group === this.activeGroup) {
                return true;
            }

            if (Object.prototype.hasOwnProperty.call(this.groups, group)) {
                return this.groups[group];
            }
            return false;
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
