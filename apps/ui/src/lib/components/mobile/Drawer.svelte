<script>
	import DrawerSide from "./DrawerSide.svelte";
  import { appSession } from '$lib/store';
</script>
<div class="drawer">
	<input id="main-drawer" type="checkbox" class="drawer-toggle" />
	<div class="drawer-content">
		<!--MainNavigation/-->
		<div
			class="navbar lg:hidden space-x-2 flex flex-row items-center bg-coollabs"
			class:hidden={!$appSession.userId}
		>
			<label for="main-drawer" class="drawer-button btn btn-square btn-ghost flex-col">
				<span class="burger bg-white" />
				<span class="burger bg-white" />
				<span class="burger bg-white" />
			</label>
			<div class="prose flex flex-row justify-between space-x-1 w-full items-center pr-3">
				{#if !$appSession.whiteLabeled}
					<h3 class="mb-0 text-white">Coolify</h3>
				{/if}
			</div>
		</div>
		<main>
			<div  class="mx-2">
				<slot name="drawer-content"/>
			</div>
		</main>
	</div>
  <slot name="drawer-side">
    <DrawerSide/>
  </slot>
</div>