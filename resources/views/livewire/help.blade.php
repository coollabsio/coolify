<div class="flex w-full flex-col gap-4">
    <div
        class="flex items-start gap-3 rounded-lg bg-neutral-100 px-3 py-2.5 text-sm text-neutral-600 dark:bg-white/[0.04] dark:text-fg-dim">
        <div
            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white text-neutral-500 shadow-[0_0_0_1px_var(--coollabs-hairline)] dark:bg-raised dark:text-fg-dim">
            <x-reicon name="feedback" class="size-4" />
        </div>
        <div class="min-w-0">
            <p class="font-medium text-black dark:text-fg">Tell us what you need</p>
            <p class="mt-0.5 text-xs leading-5">Share a problem, question, or idea. Include enough context for us to
                understand what happened.</p>
        </div>
    </div>

    <form wire:submit="submit" class="flex flex-col gap-4">
        <x-forms.input minlength="3" required id="subject" label="Subject"
            placeholder="A short summary of your feedback" autofocus />
        <x-forms.textarea minlength="10" maxlength="1000" required rows="8" id="description" label="Details"
            class="font-sans" spellcheck
            placeholder="What were you trying to do, what happened, and what would you like to see instead?" />

        <div
            class="flex flex-col-reverse items-stretch justify-between gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-center dark:border-white/[0.06]">
            <p class="text-xs text-neutral-500 dark:text-fg-faint">Replies are sent to your account email.</p>
            <x-forms.button class="justify-center sm:min-w-28" type="submit" isHighlighted>
                Send feedback
            </x-forms.button>
        </div>
    </form>
</div>
