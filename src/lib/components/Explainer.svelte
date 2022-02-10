<script>
	export let text;
	export let maxWidthClass = 'max-w-[24rem]';
</script>

<div class="py-1 text-xs text-stone-400 {maxWidthClass}">{@html text}</div>
