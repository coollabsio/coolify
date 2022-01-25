<script>
	export let name = '';
	export let value = '';
	export let isBuildSecret = false;
	export let isNewSecret = false;
	import { page } from '$app/stores';
	import { del, post } from '$lib/api';
	import { errorNotification } from '$lib/form';

	if (name) value = 'ENCRYPTED';
	const { id } = $page.params;

	async function removeSecret() {
		try {
			await del(`/applications/${id}/secrets.json`, { name });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function saveSecret() {
		try {
			await post(`/applications/${id}/secrets.json`, { name, value, isBuildSecret });
			return window.location.reload();
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	function setSecretValue() {
		if (isNewSecret) {
			isBuildSecret = !isBuildSecret;
		}
	}
</script>

<div class="max-w-3xl mx-auto pt-4">
	<div class="flex space-x-2">
		<div class="grid grid-flow-row">
			<label for="secretName">Name</label>
			<input
				id="secretName"
				bind:value={name}
				placeholder="EXAMPLE_VARIABLE"
				class="w-64 border-2 border-transparent"
				readonly={!isNewSecret}
				class:hover:bg-coolgray-200={!isNewSecret}
				class:cursor-not-allowed={!isNewSecret}
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="secretValue">Value (will be encrypted)</label>
			<input
				id="secretValue"
				bind:value
				placeholder="J$#@UIO%HO#$U%H"
				class="w-64 border-2 border-transparent"
				class:hover:bg-coolgray-200={!isNewSecret}
				class:cursor-not-allowed={!isNewSecret}
				readonly={!isNewSecret}
			/>
		</div>

		<div class="px-2 text-center w-32">
			<div class="text-xs">Is build variable?</div>

			<div class="mt-2">
				<ul class="divide-y divide-stone-800">
					<li>
						<div
							type="button"
							on:click={setSecretValue}
							aria-pressed="false"
							class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full transition-colors ease-in-out duration-200"
							class:bg-green-600={isBuildSecret}
							class:bg-stone-700={!isBuildSecret}
							class:cursor-not-allowed={!isNewSecret}
							class:cursor-pointer={isNewSecret}
						>
							<span class="sr-only">Use isBuildSecret</span>
							<span
								class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
								class:translate-x-5={isBuildSecret}
								class:translate-x-0={!isBuildSecret}
							>
								<span
									class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
									class:opacity-0={isBuildSecret}
									class:opacity-100={!isBuildSecret}
									aria-hidden="true"
								>
									<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
										<path
											d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
											stroke="currentColor"
											stroke-width="2"
											stroke-linecap="round"
											stroke-linejoin="round"
										/>
									</svg>
								</span>
								<span
									class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
									aria-hidden="true"
									class:opacity-100={isBuildSecret}
									class:opacity-0={!isBuildSecret}
								>
									<svg
										class="bg-white h-3 w-3 text-green-600"
										fill="currentColor"
										viewBox="0 0 12 12"
									>
										<path
											d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
										/>
									</svg>
								</span>
							</span>
						</div>
					</li>
				</ul>
			</div>
		</div>
		{#if isNewSecret}
			<div class="mt-6">
				<button class="w-20 bg-green-600 hover:bg-green-500" on:click={saveSecret}>Add</button>
			</div>
		{:else}
			<div class="mt-6">
				<button class="w-20 bg-red-600 hover:bg-red-500" on:click={removeSecret}>Remove</button>
			</div>
		{/if}
	</div>
</div>
