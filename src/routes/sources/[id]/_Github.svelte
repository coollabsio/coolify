<script lang="ts">
	export let source;

	import { page } from '$app/stores';

	const { id } = $page.params;

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
	<button class="box-selection truncate text-center text-xl font-bold" on:click={newGithubApp}
		>Create new GitHub App</button
	>
{:else if source.githubApp?.installationId}
	<button
		class="box-selection font-bold text-xl text-center truncate"
		on:click={() => installRepositories(source)}>Change GitHub App Settings</button
	>
{:else}
	<button
		class="box-selection font-bold text-xl text-center truncate"
		on:click={() => installRepositories(source)}>Install Repositories</button
	>
{/if}
