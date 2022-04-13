<script lang="ts">
	export let application;
	import Select from 'svelte-select';
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

	async function loadBranchesByPage(page = 0) {
		return await get(`${apiUrl}/repos/${selected.repository}/branches?per_page=100&page=${page}`, {
			Authorization: `token ${$gitTokens.githubToken}`
		});
	}

	let reposSelectOptions;
	let branchSelectOptions;

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
		reposSelectOptions = repositories.map((repo) => ({
			value: repo.full_name,
			label: repo.name
		}));
	}
	async function loadBranches(event) {
		branches = [];
		selected.repository = event.detail.value;
		selected.projectId = repositories.find((repo) => repo.full_name === selected.repository).id;
		let page = 1;
		let branchCount = 0;
		loading.branches = true;
		const loadedBranches = await loadBranchesByPage();
		branches = branches.concat(loadedBranches);
		branchCount = branches.length;
		if (branchCount === 100) {
			while (branchCount === 100) {
				page = page + 1;
				const nextBranches = await loadBranchesByPage(page);
				branches = branches.concat(nextBranches);
				branchCount = nextBranches.length;
			}
		}
		loading.branches = false;
		branchSelectOptions = branches.map((branch) => ({
			value: branch.name,
			label: branch.name
		}));
	}
	async function isBranchAlreadyUsed(event) {
		selected.branch = event.detail.value;
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
	<form on:submit|preventDefault={handleSubmit} class="flex flex-col justify-center text-center">
		<div class="flex-col space-y-3 md:space-y-0 space-x-1">
			<div class="flex-col md:flex gap-4">
				<div class="custom-select-wrapper">
					<Select
						placeholder={loading.repositories
							? 'Loading repositories...'
							: 'Please select a repository'}
						id="repository"
						showIndicator={true}
						isWaiting={loading.repositories}
						on:select={loadBranches}
						items={reposSelectOptions}
						isDisabled={loading.repositories}
						isClearable={false}
					/>
				</div>
				<input class="hidden" bind:value={selected.projectId} name="projectId" />
				<div class="custom-select-wrapper">
					<Select
						placeholder={loading.branches
							? 'Loading branches...'
							: !selected.repository
							? 'Please select a repository first'
							: 'Please select a branch'}
						isWaiting={loading.branches}
						showIndicator={selected.repository}
						id="branches"
						on:select={isBranchAlreadyUsed}
						items={branchSelectOptions}
						isDisabled={loading.branches || !selected.repository}
						isClearable={false}
					/>
				</div>
			</div>
		</div>
		<div class="pt-5 flex-col flex justify-center items-center space-y-4">
			<button
				class="w-40"
				type="submit"
				disabled={!showSave}
				class:bg-orange-600={showSave}
				class:hover:bg-orange-500={showSave}>Save</button
			>
		</div>
	</form>
{/if}
