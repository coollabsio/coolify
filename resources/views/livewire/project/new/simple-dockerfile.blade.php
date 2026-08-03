<div class="mt-8 w-full max-w-[1180px] lg:mt-3">
    <form wire:submit="submit">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Dockerfile</h2>
                    <p>Create an application directly from a Dockerfile without connecting a Git repository.</p>
                </div>
                <x-forms.button type="submit" isHighlighted>Create application</x-forms.button>
            </div>
            <div class="application-settings-section-body p-0!">
                <x-forms.textarea useMonacoEditor monacoEditorLanguage="dockerfile" rows="20"
                    id="dockerfile" autofocus placeholder='FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
'></x-forms.textarea>
            </div>
        </section>
    </form>
</div>
