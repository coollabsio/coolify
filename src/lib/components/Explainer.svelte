<script>
    export let text;
    export let maxWidthClass = 'max-w-[24rem]'
</script>
<div class="text-xs py-1 px-1 text-warmGray-400 {maxWidthClass}">{@html text}</div>