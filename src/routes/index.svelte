<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, session }) => {
		const url = `/index.json`;
		const res = await fetch(url);

		if (res.ok) {
			return {
				props: {
					...(await res.json())
				}
			};
		}

		return {
			status: res.status,
			error: new Error(`Could not load ${url}`)
		};
	};
</script>

<script lang="ts">
	export let applicationsCount: number;
	export let sourcesCount: number;
	export let destinationsCount: number;
	export let teamsCount: number;
	export let databasesCount: number;
	export let servicesCount: number;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Dashboard</div>
</div>

<div class="mt-10 pb-12 sm:pb-16 tracking-tight">
	<div class="relative">
		<div class="absolute inset-0 h-1/2" />
		<div class="relative mx-auto px-4 sm:px-6 lg:px-8">
			<div class="max-w-4xl mx-auto">
				<dl class="sm:grid sm:grid-cols-3 gap-5 gap-y-16">
					<a
						href="/applications"
						sveltekit:prefetch
						class="flex rounded flex-col p-6 text-center hover:bg-green-500 hover:text-white text-green-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">
							Applications
						</dt>
						<dd class="order-1 text-5xl font-extrabold ">
							{applicationsCount}
						</dd>
					</a>
					<a
						href="/destinations"
						sveltekit:prefetch
						class="flex rounded flex-col p-6 text-center hover:bg-sky-500 hover:text-white text-sky-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">
							Destinations
						</dt>
						<dd class="order-1 text-5xl font-extrabold ">
							{destinationsCount}
						</dd>
					</a>
					<a
						href="/sources"
						sveltekit:prefetch
						class="flex rounded flex-col p-6 text-center hover:bg-orange-500 hover:text-white text-orange-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">
							Git Sources
						</dt>
						<dd class="order-1 text-5xl font-extrabold ">
							{sourcesCount}
						</dd>
					</a>
					<a
						href="/databases"
						sveltekit:prefetch
						class="flex flex-col rounded p-6 text-center hover:bg-purple-500 hover:text-white text-purple-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">Databases</dt>
						<dd class="order-1 text-5xl font-extrabold ">{databasesCount}</dd>
					</a>
					<a
						href="/services"
						sveltekit:prefetch
						class="flex flex-col rounded p-6 text-center hover:bg-pink-500 hover:text-white text-pink-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">Services</dt>
						<dd class="order-1 text-5xl font-extrabold ">{servicesCount}</dd>
					</a>

					<a
						href="/teams"
						sveltekit:prefetch
						class="flex rounded flex-col p-6 text-center hover:bg-cyan-500 hover:text-white text-cyan-500 transition duration-150 cursor-pointer no-underline"
					>
						<dt class="order-2 mt-2 text-sm leading-6 font-bold text-white uppercase">Teams</dt>
						<dd class="order-1 text-5xl font-extrabold ">
							{teamsCount}
						</dd>
					</a>
				</dl>
			</div>
		</div>
	</div>
</div>
