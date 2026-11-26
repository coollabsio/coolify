<div class="mt-8 w-full max-w-none lg:mt-3">
    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Static Site (Upload)</h2>
                <p>Upload the built files of a static site. Coolify creates the application and deploys the files with nginx.</p>
            </div>
        </div>
        <div class="application-settings-section-body flex flex-col gap-3">
            <x-static-archive-dropzone action="createFromUpload" prepareS3Action="prepareS3Upload" />
            <p class="text-xs text-neutral-500 dark:text-fg-dim">The archive can have the files at the top level or in one folder. Links and special files are not allowed. The maximum size is 1 GiB.</p>
            <div wire:loading wire:target="createFromUpload">
                <x-loading text="Creating the application..." />
            </div>
        </div>
    </section>
</div>
