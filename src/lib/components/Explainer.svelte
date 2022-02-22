<script>
	export let text;
	export let customClass = 'max-w-[24rem]';
</script>

<div class="p-2 text-xs text-stone-400 {customClass}">{@html text}</div>
