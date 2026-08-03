<div class="mt-8 w-full max-w-[1180px] lg:mt-3">
    <form wire:submit="submit">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Docker Compose</h2>
                    <p>Create a multi-container service directly from a Compose file.</p>
                </div>
                <x-forms.button type="submit" isHighlighted>Create service</x-forms.button>
            </div>
            <div class="application-settings-section-body p-0!">
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
