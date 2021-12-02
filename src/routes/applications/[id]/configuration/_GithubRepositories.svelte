<script lang="ts">
	export let githubToken;
	export let application;

	import { page } from '$app/stores';
	import { enhance, errorNotification } from '$lib/form';
	import { onMount } from 'svelte';

	const { id } = $page.params;
	const from = $page.query.get('from');

	let htmlUrl = application.gitSource.htmlUrl;
	let apiUrl = application.gitSource.apiUrl;

	let loading = {
		repositories: true,
		branches: false
	};
	let repositories = [];
	let branches = [];

	let selected = {
		repository: undefined,
		branch: undefined
	};
	let showSave = false;
	let token = null;

	async function getGithubToken(): Promise<void> {
		const response = await fetch(
			`${apiUrl}/app/installations/${application.gitSource.githubApp.installationId}/access_tokens`,
			{
				method: 'POST',
				headers: {
					Authorization: `Bearer ${githubToken}`
				}
			}
		);
		if (!response.ok) {
			throw new Error('Git Source not configured.');
		}
		const data = await response.json();
		token = data.token;
	}
	async function loadRepositoriesByPage(page = 0) {
		const response = await fetch(`${apiUrl}/installation/repositories?per_page=100&page=${page}`, {
			headers: {
				Authorization: `token ${token}`
			}
		});
		return await response.json();
	}
	async function loadRepositories() {
		await getGithubToken();
		let page = 1;
		let reposCount = 0;
		const loadedRepos = await loadRepositoriesByPage();
		repositories = repositories.concat(loadedRepos.repositories);
		reposCount = loadedRepos.total_count;
		if (reposCount > repositories.length) {
			while (reposCount > repositories.length) {
				page = page + 1;
				const repos = await loadRepositoriesByPage(page);
				repositories = repositories.concat(repos.repositories);
			}
		}
		loading.repositories = false;
	}
	async function loadBranches() {
		loading.branches = true;
		const response = await fetch(`${apiUrl}/repos/${selected.repository}/branches`, {
			headers: {
				Authorization: `token ${token}`
			}
		});
		branches = await response.json();
		loading.branches = false;
	}
	async function isBranchAlreadyUsed() {
		const url = `/applications/${id}/configuration/repository.json?repository=${selected.repository}&branch=${selected.branch}`;
		const res = await fetch(url);
		if (res.ok) {
			errorNotification('Branch already configured');
			return;
		}
		showSave = true;
	}

	onMount(async () => {
		await loadRepositories();
	});
</script>

{#if repositories.length === 0 && loading.repositories === false}
	<div class="flex-col text-center">
		<div class="pb-4">No repositories configured for your Git Application.</div>
		<a href={`/sources/${application.gitSource.id}`}><button>Configure it now</button></a>
	</div>
{:else}
	<form
		action="/applications/{id}/configuration/repository.json"
		method="post"
		use:enhance={{
			result: async () => {
				window.location.assign(from || `/applications/${id}/configuration/buildpack`);
			}
		}}
	>
		<div>
			{#if loading.repositories}
				<select name="repository" disabled class="w-96">
					<option selected value="">Loading repositories...</option>
				</select>
			{:else}
				<select
					name="repository"
					class="w-96"
					bind:value={selected.repository}
					on:change={loadBranches}
				>
					<option value="" disabled selected>Please select a repository</option>
					{#each repositories as repository}
						<option value={repository.full_name}>{repository.name}</option>
					{/each}
				</select>
			{/if}
			{#if loading.branches}
				<select name="branch" disabled class="w-96">
					<option selected value="">Loading branches...</option>
				</select>
			{:else}
				<select
					name="branch"
					class="w-96"
					disabled={!selected.repository}
					bind:value={selected.branch}
					on:change={isBranchAlreadyUsed}
				>
					{#if !selected.repository}
						<option value="" disabled selected>Select a repository first</option>
					{:else}
						<option value="" disabled selected>Please select a branch</option>
					{/if}

					{#each branches as branch}
						<option value={branch.name}>{branch.name}</option>
					{/each}
				</select>
			{/if}
		</div>
		<div class="pt-5 flex-col flex justify-center items-center space-y-4">
			<button
				class="w-40"
				type="submit"
				disabled={!showSave}
				class:bg-orange-600={showSave}
				class:hover:bg-orange-500={showSave}>Save</button
			>
			<button class="w-40"
				><a
					class="no-underline"
					href="{apiUrl}/apps/{application.gitSource.githubApp.name}/installations/new"
					>Modify Repositories</a
				></button
			>
		</div>
	</form>
{/if}
