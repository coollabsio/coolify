<div class="h-full">
    <x-slot:title>
        Deploy canvas | Coolify
    </x-slot>
    {{-- The app's icons, cloned by resources/js/canvas/deploy/Icon.svelte. --}}
    <template data-deploy-canvas-icons>
        @foreach (['projects', 'servers', 'sources', 'cloud', 'file-content', 'layers', 'database', 'sliders', 'variables', 'globe', 'play-circle', 'check', 'x', 'plus', 'external-link', 'chevron-down', 'info-circle'] as $iconName)
            <x-reicon :name="$iconName" data-icon="{{ $iconName }}" />
        @endforeach
    </template>
    {{-- One-click service templates for the Service step: [key, name]. --}}
    <script type="application/json" data-deploy-canvas-services>@json($serviceTemplates)</script>
    {{-- The logos of the other source steps: name => versioned URL. --}}
    <script type="application/json" data-deploy-canvas-resource-logos>@json($resourceLogos)</script>
    {{-- Their logos for the Service step icon: key => [logo, CDN fallback]. --}}
    <script type="application/json" data-deploy-canvas-service-logos>@json($serviceLogos)</script>
    <div data-deploy-canvas class="relative h-full" data-team="{{ $teamId }}" data-can-add="{{ $canAddProjects ? '1' : '0' }}" wire:ignore></div>
    {{-- Opened by the canvas Reset button in resources/js/canvas/deploy/DeployCanvas.svelte.
         Out of the page flow: its empty trigger line under the full-height canvas
         made the page scroll, which moved the canvas panels up. --}}
    <div class="absolute">
        <x-modal-confirmation title="Reset the canvas?" submitAction="deploy-canvas-reset" dispatchAction
            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Reset"
            warningMessage="Your steps and their settings are removed from the canvas. Resources that you already deployed stay in Coolify."
            :actions="['Remove every step from the canvas.']">
            <x-slot:trigger>
                <button id="deploy-canvas-reset-trigger" type="button" class="hidden" aria-hidden="true"></button>
            </x-slot:trigger>
        </x-modal-confirmation>
    </div>
</div>
