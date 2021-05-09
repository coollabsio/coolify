<script>
	import { goto } from '$app/navigation';
	import MongoDb from '$components/Database/SVGs/MongoDb.svelte';
	import Postgresql from '$components/Database/SVGs/Postgresql.svelte';
	import Clickhouse from '$components/Database/SVGs/Clickhouse.svelte';
	import CouchDb from '$components/Database/SVGs/CouchDb.svelte';
	import Mysql from '$components/Database/SVGs/Mysql.svelte';
	import { dashboard } from '$store';
	import { fade } from 'svelte/transition';
</script>

<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
	<div in:fade={{ duration: 100 }}>Databases</div>
	<button
		class="icon p-1 ml-4 bg-purple-500 hover:bg-purple-400"
		on:click={() => goto('/database/new')}
	>
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
	{#if $dashboard.databases?.deployed.length > 0}
		<div class="px-4 mx-auto py-5">
			<div class="flex items-center justify-center flex-wrap">
				{#each $dashboard.databases.deployed as database}
					<div
						in:fade={{ duration: 200 }}
						class="px-4 pb-4"
						on:click={() =>
							goto(`/database/${database.configuration.general.deployId}/configuration`)}
					>
						<div
							class="relative rounded-xl p-6 bg-warmGray-800 border-2 border-dashed border-transparent hover:border-purple-500 text-white shadow-md cursor-pointer ease-in-out transform hover:scale-105 duration-100 group"
						>
							<div class="flex items-center">
								{#if database.configuration.general.type == 'mongodb'}
									<MongoDb customClass="w-10 h-10 absolute top-0 left-0 -m-4" />
								{:else if database.configuration.general.type == 'postgresql'}
									<Postgresql customClass="w-10 h-10 absolute top-0 left-0 -m-4" />
								{:else if database.configuration.general.type == 'mysql'}
									<Mysql customClass="w-10 h-10 absolute top-0 left-0 -m-4" />
								{:else if database.configuration.general.type == 'couchdb'}
									<CouchDb
										customClass="w-10 h-10 fill-current text-red-600 absolute top-0 left-0 -m-4"
									/>
								{:else if database.configuration.general.type == 'clickhouse'}
									<Clickhouse
										customClass="w-10 h-10 fill-current text-red-600 absolute top-0 left-0 -m-4"
									/>
								{/if}
								<div class="text-center w-full">
									<div class="text-base font-bold text-white group-hover:text-white">
										{database.configuration.general.nickname}
									</div>
									<div class="text-xs font-bold text-warmGray-300 ">
										({database.configuration.general.type})
									</div>
								</div>
							</div>
						</div>
					</div>
				{/each}
			</div>
		</div>
	{:else}
		<div class="text-2xl font-bold text-center">No databases found</div>
	{/if}
</div>

