<script lang="ts">
	export let text: string;
	export let customClass = 'max-w-[24rem]';
</script>

<div class="p-2 text-xs text-stone-400 {customClass}">{@html text}</div>