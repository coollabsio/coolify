<script>
	export let secrets;
	export let refreshSecrets;
	export let id;

	import { saveSecret } from './utils';
	import pLimit from 'p-limit';
	import { createEventDispatcher } from 'svelte';
	const dispatch = createEventDispatcher();

	let batchSecrets = '';
	function setBatchValue(event) {
		batchSecrets = event.target?.value;
	}
	const limit = pLimit(1);
	async function getValues(e) {
		e.preventDefault();
		const eachValuePair = batchSecrets.split('\n');
		const batchSecretsPairs = eachValuePair
			.filter((secret) => !secret.startsWith('#') && secret)
			.map((secret) => {
				const [name, value] = secret.split('=');
				const cleanValue = value?.replaceAll('"', '') || '';
				return {
					name,
					value: cleanValue,
					isNew: !secrets.find((secret) => name === secret.name)
				};
			});

		await Promise.all(
			batchSecretsPairs.map(({ name, value, isNew }) =>
				limit(() => saveSecret({ name, value, applicationId: id, isNew }))
			)
		);
		batchSecrets = '';
		refreshSecrets();
	}
</script>

<h2 class="title my-6 font-bold">Paste .env file</h2>
<form on:submit|preventDefault={getValues} class="mb-12 w-full">
	<textarea bind:value={batchSecrets} class="mb-2 min-h-[200px] w-full" />
	<button
		class="bg-green-600 hover:bg-green-500 disabled:text-white disabled:opacity-40"
		type="submit">Batch add secrets</button
	>
</form>
