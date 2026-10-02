@if (count($detectedEnvFiles) > 0 && $envImported && count($envExampleVars) > 0)
    <button type="button" @click="envModalOpen = true"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-success/30 dark:border-success/30 hover:dark:border-success transition-colors cursor-pointer">
        <span class="badge badge-success"></span>
        {{ $selectedEnvFile }}
        <span class="text-success text-xs">({{ count($envExampleVars) }} imported)</span>
    </button>
@elseif (count($detectedEnvFiles) > 0 && count($envExampleVars) > 0)
    <button type="button" @click="envModalOpen = true"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300 hover:dark:border-warning transition-colors cursor-pointer">
        <span class="badge badge-success"></span>
        {{ count($detectedEnvFiles) > 1 ? 'Env Files (' . count($detectedEnvFiles) . ')' : $detectedEnvFiles[0] }}
        <span class="dark:text-warning text-xs">(click to import)</span>
    </button>
@elseif (count($detectedEnvFiles) > 0)
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300">
        <span class="badge badge-success"></span>
        {{ count($detectedEnvFiles) > 1 ? 'Env Files (' . count($detectedEnvFiles) . ')' : $detectedEnvFiles[0] }}
    </span>
@endif
