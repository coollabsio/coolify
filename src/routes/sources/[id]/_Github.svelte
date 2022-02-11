<script lang="ts">
	export let source;
	import { page, session } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	const { id } = $page.params;

	let loading = false;
	async function handleSubmit() {
		loading = true;
		try {
			return await post(`/sources/${id}.json`, { name: source.name });
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

	function newGithubApp() {
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

{#if !source.githubAppId}
	<button on:click={newGithubApp}>Create new GitHub App</button>
{:else if source.githubApp?.installationId}
	<div class="mx-auto max-w-4xl px-6">
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
				<div class="mt-2 grid grid-cols-3 items-center">
					<label for="name">Name</label>
					<div class="col-span-2 ">
						<input name="name" id="name" required bind:value={source.name} />
					</div>
				</div>
			</div>
		</form>
	</div>
{:else}
	<button on:click={() => installRepositories(source)}>Install Repositories</button>
{/if}
