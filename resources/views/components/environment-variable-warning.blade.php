@props(['problematicVariables' => []])

<template x-data="{
    problematicVars: @js($problematicVariables),
    get showWarning() {
        const currentKey = $wire.key;
        const isBuildtime = $wire.is_buildtime;

        if (!isBuildtime || !currentKey) return false;
        if (!this.problematicVars.hasOwnProperty(currentKey)) return false;

        // Always show warning for known problematic variables when set as buildtime
        return true;
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
    <div class="p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800">
        <div class="text-sm text-yellow-700 dark:text-yellow-300" x-text="warningMessage"></div>
        <div class="text-sm text-yellow-700 dark:text-yellow-300" x-text="recommendation"></div>
    </div>
</template>
