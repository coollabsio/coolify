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
									{:else if service.serviceName == 'nocodb'}
									<div>
										<img
											alt="nocodedb"
											class="w-10 absolute top-0 left-0 -m-6"
											src="https://cdn.coollabs.io/assets/coolify/services/nocodb/nocodb.png"
										/>
										<div class="text-white font-bold">NocoDB</div>
									</div>
									{:else if service.serviceName == 'code-server'}
									<div>
										<svg class="w-10 absolute top-0 left-0 -m-6" viewBox="0 0 128 128">
											<path d="M3.656 45.043s-3.027-2.191.61-5.113l8.468-7.594s2.426-2.559 4.989-.328l78.175 59.328v28.45s-.039 4.468-5.757 3.976zm0 0" fill="#2489ca"></path><path d="M23.809 63.379L3.656 81.742s-2.07 1.543 0 4.305l9.356 8.527s2.222 2.395 5.508-.328l21.359-16.238zm0 0" fill="#1070b3"></path><path d="M59.184 63.531l36.953-28.285-.239-28.297S94.32.773 89.055 3.99L39.879 48.851zm0 0" fill="#0877b9"></path><path d="M90.14 123.797c2.145 2.203 4.747 1.48 4.747 1.48l28.797-14.222c3.687-2.52 3.171-5.645 3.171-5.645V20.465c0-3.735-3.812-5.024-3.812-5.024L98.082 3.38c-5.453-3.379-9.027.61-9.027.61s4.593-3.317 6.843 2.96v112.317c0 .773-.164 1.53-.492 2.214-.656 1.332-2.086 2.57-5.504 2.051zm0 0" fill="#3c99d4"></path>
											</svg> 

										<div class="text-white font-bold">VSCode Server</div>
									</div>
									{:else if service.serviceName == 'minio'}
									<div>
										<img
											alt="minio"
											class="w-7 absolute top-0 left-0 -my-7 -mx-3"
											src="https://cdn.coollabs.io/assets/coolify/services/minio/MINIO_Bird.png"
										/>

										<div class="text-white font-bold">MinIO</div>
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
