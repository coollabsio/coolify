<script>
    export let text;
    export let maxWidthClass = 'max-w-[24rem]'
</script>
<div class="text-xs py-1 text-stone-400 {maxWidthClass}">{@html text}</div>