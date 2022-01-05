<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, url, stuff }) => {
		const { database } = stuff;
		if (database?.type && !url.searchParams.get('from')) {
			return {
				status: 302,
				redirect: `/databases/${params.id}`
			};
		}
		const endpoint = `/databases/${params.id}/configuration/type.json`;
		const res = await fetch(endpoint);

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
	import type Prisma from '@prisma/client';

	import { page } from '$app/stores';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	export let types;
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Select a Database type</div>
</div>

<div class="flex justify-center">
	{#each types as type}
		<div class="p-2">
			<form
				action="/databases/{id}/configuration/type.json"
				method="post"
				use:enhance={{
					result: async () => {
						window.location.assign(from || `/databases/${id}`);
					}
				}}
			>
            <input class="hidden" name="type" value={type.name} />
            <button
                type="submit"
                class="box-selection text-xl font-bold"
                class:border-green-500={type.name === 'node'}
                class:border-sky-500={type.name === 'docker'}
                class:border-red-500={type.name === 'static'}
                class:hover:border-green-500={type.name === 'node'}
                class:hover:border-red-500={type.name === 'static'}
                class:hover:border-sky-500={type.name === 'docker'}
                >{type.name}
            </button>
            </form>

		</div>
	{/each}
</div>
