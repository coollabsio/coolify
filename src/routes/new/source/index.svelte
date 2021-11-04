<script lang="ts">
	import { enhance } from '$lib/form';
	import Explainer from '$lib/components/Explainer.svelte';
	import { onMount } from 'svelte';
	let organizationEl;
	let nameEl;
	let payload: NewGitSource = {
		name: undefined,
		type: 'github',
		htmlUrl: undefined,
		apiUrl: undefined,
		organization: undefined
	};
	onMount(() => {
		nameEl.focus();
	});
	function setPredefined(type) {
		if (type === 'github') {
			payload = {
				name: 'GitHub.com',
				type,
				htmlUrl: 'https://github.com',
				apiUrl: 'https://api.github.com'
			};
		}
		if (type === 'gitlab') {
			payload = {
				name: 'GitLab.com',
				type,
				htmlUrl: 'https://gitlab.com',
				apiUrl: 'https://gitlab.com/api'
			};
		}
		if (type === 'bitbucket') {
			payload = {
				name: 'BitBucket.com',
				type,
				htmlUrl: 'https://bitbucket.com',
				apiUrl: 'https://bitbucket.com'
			};
		}
		organizationEl.focus()
	}
</script>

<div class="font-bold flex space-x-1 py-5 px-6">
	<div class="text-2xl tracking-tight mr-4">Add New Git Source</div>
</div>

<div class="flex justify-center pb-8">
	<form
		action="/new/source.json"
		method="post"
		use:enhance={{
			result: async (res) => {
				const { id } = await res.json();
				window.location.assign(`/sources/${id}`);
			}
		}}
		class="grid grid-flow-row gap-2 py-4"
	>
		<div class="flex space-x-2 h-8 items-center">
			<div class="font-bold text-xl text-white">Configuration</div>
			<button type="submit" class="bg-orange-600 hover:bg-orange-500">Save</button>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="name">Name</label>
			<div class="col-span-2">
				<input
					name="name"
					placeholder="GitHub.com"
					required
					bind:this={nameEl}
					bind:value={payload.name}
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="type">Type</label>

			<div class="col-span-2">
				<select name="type" class="w-96" bind:value={payload.type}>
					<option value="github">GitHub</option>
					<option value="gitlab">GitLab</option>
					<option value="bitbucket">BitBucket</option>
				</select>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="htmlUrl">HTML URL</label>
			<div class="col-span-2">
				<input
					type="url"
					name="htmlUrl"
					placeholder="eg: https://github.com"
					required
					bind:value={payload.htmlUrl}
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 items-center">
			<label for="apiUrl">API URL</label>
			<div class="col-span-2">
				<input
					name="apiUrl"
					type="url"
					placeholder="eg: https://api.github.com"
					required
					bind:value={payload.apiUrl}
				/>
			</div>
		</div>
		<div class="grid grid-cols-3 ">
			<label for="organization">Organization</label>
			<div class="col-span-2">
				<input
					name="organization"
					placeholder="eg: coollabsio"
					bind:value={payload.organization}
					bind:this={organizationEl}
				/>
				<Explainer
					text="Fill it if you would like to use an organization's as your Git Source. Otherwise your
				user will be used."
				/>
			</div>
		</div>
	</form>
</div>

<div class="flex-col text-center space-y-2">
	<div class="font-bold text-xl text-white">Offical providers</div>
	<div class="flex space-x-2 justify-center">
		<button class="w-32" on:click={() => setPredefined('github')}>GitHub.com</button>
		<button class="w-32" on:click={() => setPredefined('gitlab')}>GitLab.com</button>
		<button class="w-32" on:click={() => setPredefined('bitbucket')}>Bitbucket.com</button>
	</div>
</div>
