@props(['problematicVariables' => []])

<template x-data="{
    problematicVars: @js($problematicVariables),
    get showWarning() {
        const currentKey = $wire.key;
        const currentValue = $wire.value;
        const isBuildtime = $wire.is_buildtime;

        if (!isBuildtime || !currentKey) return false;
        if (!this.problematicVars.hasOwnProperty(currentKey)) return false;

        const config = this.problematicVars[currentKey];
        if (!config || !config.problematic_values) return false;

        // Check if current value matches any problematic values
        const lowerValue = String(currentValue).toLowerCase();
        return config.problematic_values.some(pv => pv.toLowerCase() === lowerValue);
    },
    get warningMessage() {
        if (!this.showWarning) return null;
        const config = this.problematicVars[$wire.key];
        if (!config) return null;
        return config.issue;
    },
    get recommendation() {
        if (!this.showWarning) return null;
        const config = this.problematicVars[$wire.key];
        if (!config) return null;
        return `Recommendation: ${config.recommendation}`;
    }
}" x-if="showWarning">
    <x-callout type="warning" title="Caution">
        <div class="text-sm" x-text="warningMessage"></div>
        <div class="text-sm" x-text="recommendation"></div>
    </x-callout>
</template>
