<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	import Databases from './_Databases/_Databases.svelte';
	import DatabaseLoad from '$lib/components/DatabaseLoad.svelte';
	export let database: any;
	export let privatePort: any;
</script>

<DatabaseLoad/>
<Databases bind:database {privatePort} />
