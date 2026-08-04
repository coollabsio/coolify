<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })"
    class="mt-8 w-full max-w-[920px] lg:mt-3">
    <form wire:submit="submit">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Docker image</h2>
                    <p>Deploy an existing image from Docker Hub or another OCI registry.</p>
                </div>
                <x-forms.button type="submit" isHighlighted>Create application</x-forms.button>
            </div>
            <div class="application-settings-section-body space-y-4">
                <x-forms.input id="imageName" label="Image name"
                    placeholder="nginx, ghcr.io/user/app:v1.2.3, or nginx:stable@sha256:…"
                    helper="Paste a complete image reference, or enter a name and use one of the optional fields below."
                    required autofocus />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.input id="imageTag" label="Tag" placeholder="latest"
                        helper="Use a mutable tag such as latest or v1.2.3." />
                    <x-forms.input id="imageSha256" label="SHA256 digest"
                        placeholder="59e02939b1bf39f16c93138a28727aec…"
                        helper="Use the 64-character digest without the sha256: prefix." />
                </div>
            </div>
        </section>
    </form>
</div>
