<script lang="ts">
	export let application;

	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { onMount } from 'svelte';
	import { gitTokens } from '$lib/store';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
	const to = $page.url.searchParams.get('to');

	let htmlUrl = application.gitSource.htmlUrl;
	let apiUrl = application.gitSource.apiUrl;

	let loading = {
		repositories: true,
		branches: false
	};
	let repositories = [];
	let branches = [];

	let selected = {
		projectId: undefined,
		repository: undefined,
		branch: undefined,
		autodeploy: application.settings.autodeploy || true
	};
	let showSave = false;

	async function loadRepositoriesByPage(page = 0) {
		return await get(`${apiUrl}/installation/repositories?per_page=100&page=${page}`, {
			Authorization: `token ${$gitTokens.githubToken}`
		});
	}
	async function loadRepositories() {
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
		selected.branch = undefined;
		selected.projectId = repositories.find((repo) => repo.full_name === selected.repository).id;
		try {
			branches = await get(`${apiUrl}/repos/${selected.repository}/branches`, {
				Authorization: `token ${$gitTokens.githubToken}`
			});
			return;
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading.branches = false;
		}
	}
	async function isBranchAlreadyUsed() {
		try {
			const data = await get(
				`/applications/${id}/configuration/repository.json?repository=${selected.repository}&branch=${selected.branch}`
			);
			if (data.used) {
				const sure = confirm(
					`This branch is already used by another application. Webhooks won't work in this case for both applications. Are you sure you want to use it?`
				);
				if (sure) {
					selected.autodeploy = false;
					showSave = true;
					return true;
				}
				showSave = false;
				return true;
			}
			showSave = true;
		} catch ({ error }) {
			showSave = false;
			return errorNotification(error);
		}
	}

	onMount(async () => {
		try {
			if (!$gitTokens.githubToken) {
				const { token } = await get(`/applications/${id}/configuration/githubToken.json`);
				$gitTokens.githubToken = token;
			}
			await loadRepositories();
		} catch (error) {
			if (
				error.error === 'invalid_token' ||
				error.error_description ===
					'Token is expired. You can either do re-authorization or token refresh.' ||
				error.message === '401 Unauthorized'
			) {
				if (application.gitSource.gitlabAppId) {
					let htmlUrl = application.gitSource.htmlUrl;
					const left = screen.width / 2 - 1020 / 2;
					const top = screen.height / 2 - 618 / 2;
					const newWindow = open(
						`${htmlUrl}/oauth/authorize?client_id=${application.gitSource.gitlabApp.appId}&redirect_uri=${window.location.origin}/webhooks/gitlab&response_type=code&scope=api+email+read_repository&state=${$page.params.id}`,
						'GitLab',
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
			}
			if (error.message === 'Bad credentials') {
				const { token } = await get(`/applications/${id}/configuration/githubToken.json`);
				$gitTokens.githubToken = token;
				return await loadRepositories();
			}
			return errorNotification(error);
		}
	});
	async function handleSubmit() {
		try {
			await post(`/applications/${id}/configuration/repository.json`, { ...selected });
			if (to) {
				return await goto(`${to}?from=${from}`);
			}
			return await goto(from || `/applications/${id}/configuration/destination`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

{#if repositories.length === 0 && loading.repositories === false}
	<div class="flex-col text-center">
		<div class="pb-4">No repositories configured for your Git Application.</div>
		<a href={`/sources/${application.gitSource.id}`}><button>Configure it now</button></a>
	</div>
{:else}
	<form on:submit|preventDefault={handleSubmit}>
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
			<input class="hidden" bind:value={selected.projectId} name="projectId" />
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
			<!-- <button class="w-40"
				><a
					class="no-underline"
					href="{apiUrl}/apps/{application.gitSource.githubApp.name}/installations/new"
					>Modify Repositories</a
				></button
			> -->
		</div>
	</form>
{/if}
