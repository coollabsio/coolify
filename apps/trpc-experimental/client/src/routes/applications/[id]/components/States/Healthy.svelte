<script lang="ts">
	export let id: string;
	export let isComposeBuildPack: boolean = false;
	import * as Buttons from '../Buttons';
</script>

{#if !isComposeBuildPack}
	<Buttons.Restart {id} />
{/if}
<Buttons.ForceDeploy {id} />
<Buttons.Stop {id} />
