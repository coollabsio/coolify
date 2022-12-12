<script lang="ts">
	import { t } from '$lib/store';
	import { onMount } from 'svelte';
	onMount(async () => {
		const a = await t.api.getConnection.query();
		console.log(a);
	});
</script>

<div>hello</div>
