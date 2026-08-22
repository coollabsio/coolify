@php
    $inputId ??= 'testEmailAddress';
@endphp

@if (is_transactional_emails_enabled() && auth()->user()->isAdminFromSession())
    <x-modal-input title="Send Test Email">
        <x-slot:content>
            <button type="button" class="button">
                <x-reicon name="notifications" class="size-3.5" />
                Send test
            </button>
        </x-slot:content>
        <form wire:submit.prevent="sendTestEmail" class="application-settings-form flex flex-col gap-4">
            <x-forms.input wire:model="testEmailAddress" placeholder="test@example.com" :id="$inputId"
                label="Recipient" required />
            <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                <button type="submit" class="button" @click="modalOpen=false">Send email</button>
            </div>
        </form>
    </x-modal-input>
@endif
