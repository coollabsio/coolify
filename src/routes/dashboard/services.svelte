<script>
	import { goto } from '$app/navigation';
	import { dashboard } from '$store';
	import { fade } from 'svelte/transition';
</script>

<div
	in:fade={{ duration: 100 }}
	class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center"
>
	<div>Services</div>
	<button class="icon p-1 ml-4 bg-blue-500 hover:bg-blue-400" on:click={() => goto('/service/new')}>
		<svg
			class="w-6"
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
		>
			<path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M12 6v6m0 0v6m0-6h6m-6 0H6"
			/>
		</svg>
	</button>
</div>
<div in:fade={{ duration: 100 }}>
	{#if $dashboard?.services?.deployed.length > 0}
		<div class="px-4 mx-auto py-5">
			<div class="flex items-center justify-center flex-wrap">
				{#each $dashboard?.services?.deployed as service}
					<div
						in:fade={{ duration: 200 }}
						class="px-4 pb-4"
						on:click={() => goto(`/service/${service.serviceName}/configuration`)}
					>
						<div
							class="relative rounded-xl p-6 bg-warmGray-800 border-2 border-dashed border-transparent hover:border-blue-500 text-white shadow-md cursor-pointer ease-in-out transform hover:scale-105 duration-100 group"
						>
							<div class="flex items-center">
								{#if service.serviceName == 'plausible'}
									<div>
										<img
											alt="plausible logo"
											class="w-10 absolute top-0 left-0 -m-6"
											src="https://cdn.coollabs.io/assets/coolify/services/plausible/logo_sm.png"
										/>
										<div class="text-white font-bold">Plausible Analytics</div>
									</div>
								{/if}
							</div>
						</div>
					</div>
				{/each}
			</div>
		</div>
	{:else}
		<div class="text-2xl font-bold text-center">No services found</div>
	{/if}
</div>
