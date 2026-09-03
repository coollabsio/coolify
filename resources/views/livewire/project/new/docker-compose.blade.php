<div class="mt-8 w-full lg:mt-3" x-data="{ creating: false }"
    @compose-create-finished.window="creating = false" @error.window="creating = false">
    <form wire:submit="submit">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Docker Compose</h2>
                    <p>Create a multi-container service directly from a Compose file.</p>
                </div>
                <x-forms.button type="submit" wire:target="submit" x-bind:disabled="creating"
                    @click="if (creating) { $event.preventDefault(); return }; creating = true" isHighlighted>
                    <x-loading-on-button x-show="creating" x-cloak />
                    Create service
                </x-forms.button>
            </div>
            <div class="application-settings-section-body">
                <x-forms.textarea useMonacoEditor monacoEditorLanguage="yaml" label="Docker Compose file"
                    rows="20" id="dockerComposeRaw" autofocus placeholder='services:
  app:
    image: nginx:alpine
    ports:
      - "80"
'></x-forms.textarea>
            </div>
        </section>
    </form>
</div>
