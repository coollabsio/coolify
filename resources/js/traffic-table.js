// Client-side sort and paging for the traffic analytics lists. Each row carries
// its sort values as JSON in `data-row`, so a Livewire refresh needs no re-init:
// a MutationObserver ranks the rows again. Rows keep their DOM order; the rank is
// applied with CSS `order`, so Livewire can still morph them.
function compareValues(a, b) {
    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

function trafficTable({ per = 10, key = 'requests', dir = 'desc' } = {}) {
    const ranks = new WeakMap();
    let observer = null;

    return {
        page: 0,
        per,
        total: 0,
        key,
        dir,
        tick: 0,

        init() {
            this.resort();
            observer = new MutationObserver(() => this.resort());
            observer.observe(this.$root, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['data-row'],
            });
        },

        destroy() {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
        },

        // Text columns start ascending, number columns descending.
        sortBy(next, firstDir = 'desc') {
            if (this.key === next) {
                this.dir = this.dir === 'desc' ? 'asc' : 'desc';
            } else {
                this.key = next;
                this.dir = firstDir;
            }
            this.page = 0;
            this.resort();
        },

        resort() {
            const sign = this.dir === 'asc' ? 1 : -1;
            const rows = [...this.$root.querySelectorAll('[data-row]')].map((el, index) => {
                let values = {};
                try {
                    values = JSON.parse(el.dataset.row) || {};
                } catch {
                    values = {};
                }

                return { el, index, pinned: 'pinned' in el.dataset ? 1 : 0, value: values[this.key] ?? '' };
            });

            // Pinned rows (the "Other" remainder) stay last in each sort.
            rows.sort((a, b) => a.pinned - b.pinned || compareValues(a.value, b.value) * sign || a.index - b.index);
            rows.forEach((row, rank) => ranks.set(row.el, rank));

            this.total = rows.length;
            this.page = Math.min(this.page, Math.max(0, Math.ceil(this.total / this.per) - 1));
            this.tick++;
        },

        rank(el) {
            this.tick;

            return ranks.get(el) ?? 0;
        },

        shown(el) {
            const rank = this.rank(el);

            return rank >= this.page * this.per && rank < (this.page + 1) * this.per;
        },

        // 'asc', 'desc', or null when the list is sorted by another column.
        sorted(column) {
            return this.key === column ? this.dir : null;
        },
    };
}

window.trafficTable = trafficTable;
