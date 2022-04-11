<script lang="ts">
	export let source;
	import { page, session } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { toast } from '@zerodevx/svelte-toast';
	const { id } = $page.params;

	let loading = false;
	async function handleSubmit() {
		loading = true;
		try {
			await post(`/sources/${id}.json`, {
				name: source.name,
				htmlUrl: source.htmlUrl.replace(/\/$/, ''),
				apiUrl: source.apiUrl.replace(/\/$/, '')
			});
			toast.push('Settings saved.');
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}

	async function installRepositories(source) {
		const { htmlUrl } = source;
		const left = screen.width / 2 - 1020 / 2;
		const top = screen.height / 2 - 1000 / 2;
		const newWindow = open(
			`${htmlUrl}/apps/${source.githubApp.name}/installations/new`,
			'GitHub',
			'resizable=1, scrollbars=1, fullscreen=0, height=1000, width=1020,top=' +
				top +
				', left=' +
				left +
				', toolbar=0, menubar=0, status=0'
		);
		const timer = setInterval(() => {
			if (newWindow?.closed) {
				clearInterval(timer);
				window.location.reload();
			}
		}, 100);
	}

	async function newGithubApp() {
		loading = true;
		try {
			await post(`/sources/${id}/github.json`, {
				type: 'github',
				name: source.name,
				htmlUrl: source.htmlUrl.replace(/\/$/, ''),
				apiUrl: source.apiUrl.replace(/\/$/, '')
			});
		} catch ({ error }) {
			return errorNotification(error);
		}
		const left = screen.width / 2 - 1020 / 2;
		const top = screen.height / 2 - 618 / 2;
		const newWindow = open(
			`/sources/${id}/newGithubApp`,
			'New Github App',
			'resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=' +
				top +
				', left=' +
				left +
				', toolbar=0, menubar=0, status=0'
		);
		const timer = setInterval(() => {
			if (newWindow?.closed) {
				clearInterval(timer);
				window.location.reload();
			}
		}, 100);
	}
</script>

<div class="mx-auto max-w-4xl px-6">
	{#if !source.githubAppId}
		<form on:submit|preventDefault={newGithubApp} class="py-4">
			<div class="flex space-x-1 pb-5 font-bold">
				<div class="title">General</div>
			</div>
			<div class="grid grid-flow-row gap-2 px-10">
				<div class="grid grid-flow-row gap-2">
					<div class="mt-2 grid grid-cols-2 items-center">
						<label for="name" class="text-base font-bold text-stone-100">Name</label>
						<input name="name" id="name" required bind:value={source.name} />
					</div>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="htmlUrl" class="text-base font-bold text-stone-100">HTML URL</label>
					<input name="htmlUrl" id="htmlUrl" required bind:value={source.htmlUrl} />
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="apiUrl" class="text-base font-bold text-stone-100">API URL</label>
					<input name="apiUrl" id="apiUrl" required bind:value={source.apiUrl} />
				</div>
			</div>
			{#if source.apiUrl && source.htmlUrl && source.name}
				<div class="text-center">
					<button class=" mt-8 bg-orange-600" type="submit">Create new GitHub App</button>
				</div>
			{/if}
		</form>
	{:else if source.githubApp?.installationId}
		<form on:submit|preventDefault={handleSubmit} class="py-4">
			<div class="flex space-x-1 pb-5 font-bold">
				<div class="title">General</div>
				{#if $session.isAdmin}
					<button
						type="submit"
						class:bg-orange-600={!loading}
						class:hover:bg-orange-500={!loading}
						disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
					>
					<button on:click|preventDefault={() => installRepositories(source)}
						>Change GitHub App Settings</button
					>
				{/if}
			</div>
			<div class="grid grid-flow-row gap-2 px-10">
				<div class="grid grid-flow-row gap-2">
					<div class="mt-2 grid grid-cols-2 items-center">
						<label for="name" class="text-base font-bold text-stone-100">Name</label>
						<input name="name" id="name" required bind:value={source.name} />
					</div>
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="htmlUrl" class="text-base font-bold text-stone-100">HTML URL</label>
					<input name="htmlUrl" id="htmlUrl" required bind:value={source.htmlUrl} />
				</div>
				<div class="grid grid-cols-2 items-center">
					<label for="apiUrl" class="text-base font-bold text-stone-100">API URL</label>
					<input name="apiUrl" id="apiUrl" required bind:value={source.apiUrl} />
				</div>
			</div>
		</form>
	{:else}
		<div class="text-center">
			<button class=" bg-orange-600 mt-8" on:click={() => installRepositories(source)}
				>Install Repositories</button
			>
		</div>
	{/if}
</div>
