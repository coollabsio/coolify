<script>
	import { appConfiguration } from '$lib/store';
	import Secret from './_Secret.svelte';
	let name, value = ''
</script>

<div class="font-bold flex space-x-1 py-6 px-6">
	<div class="text-2xl tracking-tight mr-4">Secrets for {$appConfiguration.configuration.name}</div>
</div>
<div class="max-w-4xl mx-auto px-6">
	<div class="flex-col justify-start space-y-2">
			<Secret {name} {value} />

	</div>
</div>
