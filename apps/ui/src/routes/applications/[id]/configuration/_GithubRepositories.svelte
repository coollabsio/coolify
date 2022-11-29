<script lang="ts">
	export let application: any;
	//@ts-ignore
	import Select from 'svelte-select';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import { onMount } from 'svelte';
	import { appSession } from '$lib/store';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');
	const to = $page.url.searchParams.get('to');

	let htmlUrl = application.gitSource.htmlUrl;
	let apiUrl = application.gitSource.apiUrl;

	let loading = {
		repositories: true,
		branches: false
	};
	let repositories: any = [];
	let branches: any = [];

	let selected = {
		projectId: undefined,
		repository: undefined,
		branch: undefined,
		autodeploy: application.settings.autodeploy || true
	};
	let showSave = false;

	async function loadRepositoriesByPage(page = 0) {
		return await get(`${apiUrl}/installation/repositories?per_page=100&page=${page}`, {
			Authorization: `token ${$appSession.tokens.github}`
		});
	}

	async function loadBranchesByPage(page = 0) {
		return await get(`${apiUrl}/repos/${selected.repository}/branches?per_page=100&page=${page}`, {
			Authorization: `token ${$appSession.tokens.github}`
		});
	}

	let reposSelectOptions: any;
	let branchSelectOptions: any;

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
		reposSelectOptions = repositories.map((repo: any) => ({
			value: repo.full_name,
			label: repo.name
		}));
	}
	async function loadBranches(event: any) {
		branches = [];
		selected.repository = event.detail.value;
		selected.projectId = repositories.find(
			(repo: any) => repo.full_name === selected.repository
		).id;
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
		branchSelectOptions = branches.map((branch: any) => ({
			value: branch.name,
			label: branch.name
		}));
	}
	async function selectBranch(event: any) {
		selected.branch = event.detail.value;
		showSave = true;
	}

	onMount(async () => {
		try {
			if (!$appSession.tokens.github) {
				const { token } = await get(`/applications/${id}/configuration/githubToken`);
				$appSession.tokens.github = token;
			}
			await loadRepositories();
		} catch (error: any) {
			if (error.message === 'Bad credentials') {
				const { token } = await get(`/applications/${id}/configuration/githubToken`);
				$appSession.tokens.github = token;
				return await loadRepositories();
			}
			return errorNotification(error);
		}
	});
	async function handleSubmit() {
		try {
			await post(`/applications/${id}/configuration/repository`, { ...selected });
			if (to) {
				return await goto(`${to}?from=${from}`);
			}
			return await goto(from || `/applications/${id}/configuration/destination`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>
{#if repositories.length === 0 && loading.repositories === false}
	<div class="flex-col text-center">
		<div class="pb-4">{$t('application.configuration.no_repositories_configured')}</div>
		<a href={`/sources/${application.gitSource.id}`}
			><button>{$t('application.configuration.configure_it_now')}</button></a
		>
	</div>
{:else}
	<form on:submit|preventDefault={handleSubmit} class="px-10">
		<div class="flex lg:flex-row flex-col lg:space-y-0 space-y-2 space-x-0 lg:space-x-2 items-center lg:justify-center">
				<div class="custom-select-wrapper w-full"><label for="repository" class="pb-1">Repository</label>
					<Select
						placeholder={loading.repositories
							? $t('application.configuration.loading_repositories')
							: $t('application.configuration.select_a_repository')}
						id="repository"
						showIndicator={!loading.repositories}
						isWaiting={loading.repositories}
						on:select={loadBranches}
						items={reposSelectOptions}
						isDisabled={loading.repositories}
						isClearable={false}
					/>
				</div>
				<input class="hidden" bind:value={selected.projectId} name="projectId" />
				<div class="custom-select-wrapper w-full"><label for="repository" class="pb-1">Branch</label>
					<Select
						placeholder={loading.branches
							? $t('application.configuration.loading_branches')
							: !selected.repository
							? $t('application.configuration.select_a_repository_first')
							: $t('application.configuration.select_a_branch')}
						isWaiting={loading.branches}
						showIndicator={selected.repository && !loading.branches}
						id="branches"
						on:select={selectBranch}
						items={branchSelectOptions}
						isDisabled={loading.branches || !selected.repository}
						isClearable={false}
					/>
				</div></div>
		<div class="pt-5 flex-col flex justify-center items-center space-y-4">
			<button
				class="btn btn-wide btn-primary"
				type="submit"
				disabled={!showSave}
				>{$t('forms.save')}</button
			>
		</div>
	</form>
{/if}
