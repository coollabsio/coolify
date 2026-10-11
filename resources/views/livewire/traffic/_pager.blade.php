{{--
    Client-side "top 10" pager footer for traffic lists. Expects an enclosing
    Alpine scope that defines reactive `page` (0-based), `per` (page size) and
    `total` (row count). Rows themselves are toggled with x-show on their index;
    this partial only renders the summary + prev/next controls, and hides itself
    when everything fits on one page.
--}}
<div x-show="total > per" x-cloak
    class="flex items-center justify-between gap-3 border-t border-neutral-200 px-4 py-2 dark:border-white/[0.07]">
    <span class="text-[11px] text-neutral-500 dark:text-fg-faint"
        x-text="`${page * per + 1}–${Math.min((page + 1) * per, total)} of ${total.toLocaleString()}`"></span>
    <div class="flex items-center gap-1">
        <button type="button" @click="page = Math.max(0, page - 1)" :disabled="page === 0"
            class="flex h-6 items-center rounded-md px-2 text-[11px] font-medium text-neutral-500 transition-colors hover:text-black disabled:cursor-not-allowed disabled:opacity-30 dark:text-fg-faint dark:hover:text-fg">
            Prev
        </button>
        <button type="button" @click="page = Math.min(Math.ceil(total / per) - 1, page + 1)"
            :disabled="(page + 1) * per >= total"
            class="flex h-6 items-center rounded-md px-2 text-[11px] font-medium text-neutral-500 transition-colors hover:text-black disabled:cursor-not-allowed disabled:opacity-30 dark:text-fg-faint dark:hover:text-fg">
            Next
        </button>
    </div>
</div>
