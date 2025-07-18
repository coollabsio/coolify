<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('confirmModal', () => ({
            open: false,
            confirmAction: null,
            message: 'Are you sure?',
            toggleConfirmModal(customMessage, confirmAction) {
                this.confirmAction = confirmAction
                this.message = customMessage
                this.open = !this.open
            },
            confirmed() {
                this.open = false
                this.$dispatch(this.confirmAction)
            }
        }))
    })
</script>
<div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 bg-slate-900/75"></div>
<div x-cloak x-show="open" x-transition class="fixed inset-0 z-50 flex pt-10">
    <div @click.away="open = false" class="w-screen h-20 max-w-xl mx-auto bg-black rounded-lg">
        <div class="flex flex-col items-center justify-center h-full">
            <div class="pb-5 dark:text-white" x-text="message"></div>
            <div>
                <x-forms.button x-on:click='confirmed()'>Confirm</x-forms.button>
                <x-forms.button x-on:click="open = false">Cancel</x-forms.button>
            </div>
        </div>
    </div>
</div>
