<script>
	import { application } from '$store';
	import BuildEnv from '../BuildEnv.svelte';

	let secret = {
		name: null,
		value: null,
		isBuild: false
	};
	let foundSecret = null;
	async function saveSecret() {
		if (secret.name && secret.value) {
			const found = $application.publish.secrets.find((s) => s.name === secret.name);
			if (!found) {
				$application.publish.secrets = [
					...$application.publish.secrets,
					{
						name: secret.name,
						value: secret.value,
						isBuild: secret.isBuild
					}
				];
				secret = {
					name: null,
					value: null,
					isBuild: false
				};
			} else {
				foundSecret = found;
			}
		}
	}

	async function removeSecret(name) {
		foundSecret = null;
		$application.publish.secrets = [...$application.publish.secrets.filter((s) => s.name !== name)];
	}
</script>

<div class="text-2xl font-bold border-gradient w-24">Secrets</div>
<div class="max-w-3xl mx-auto text-center pt-4">
	<div class="flex space-x-4">
		<div class="grid grid-flow-row">
			<label for="secretName">Secret Name</label>
			<input
				id="secretName"
				bind:value={secret.name}
				placeholder="Name"
				class="w-64 border-2 border-transparent"
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="secretValue">Secret Value</label>
			<input
				id="secretValue"
				bind:value={secret.value}
				placeholder="Value"
				class="w-64 border-2 border-transparent"
			/>
		</div>

		<div class="grid grid-flow-row">
			<label for="buildVariable">Is build variable?</label>
			<div class="mt-2 w-full">
				<BuildEnv {secret} />
			</div>
		</div>
		<div class="mt-6">
			<button class="icon hover:text-green-500" on:click={saveSecret}>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
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
	</div>

	{#if $application.publish.secrets.length > 0}
		<div class="pt-1">
			{#each $application.publish.secrets as secret}
				<div class="flex space-x-4 space-y-2">
					<input
						id={secret.name}
						value={secret.name}
						disabled
						class="border-2 bg-transparent border-transparent w-64 hover:bg-transparent"
						class:border-red-600={foundSecret && foundSecret.name === secret.name}
					/>
					<input
						id={secret.createdAt}
						value="SAVED"
						disabled
						class="border-2 bg-transparent border-transparent w-64 hover:bg-transparent"
					/>
					<div class="flex justify-center items-center px-12">
						<BuildEnv {secret} readOnly />
					</div>
					<button class="icon hover:text-red-500" on:click={() => removeSecret(secret.name)}>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-6 w-6"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
							/>
						</svg>
					</button>
				</div>
			{/each}
		</div>
	{/if}
</div>
