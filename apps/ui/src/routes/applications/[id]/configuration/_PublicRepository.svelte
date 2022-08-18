<script lang="ts">
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { page } from '$app/stores';

	import Select from 'svelte-select';
	import Explainer from '$lib/components/Explainer.svelte';
	import { goto } from '$app/navigation';
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;

	let publicRepositoryLink: string = 'https://github.com/zekth/fastify-typescript-example';
	let projectId: number;
	let repositoryName: string;
	let branchName: string;
	let ownerName: string;
	let type: string;
	let branchSelectOptions: any = [];
	let loading = {
		branches: false
	};
	async function loadBranches() {
		const protocol = publicRepositoryLink.split(':')[0];
		const gitUrl = publicRepositoryLink.replace('http://', '').replace('https://', '');
		let [host, ...path] = gitUrl.split('/');
		const [owner, repository, ...branch] = path;

		ownerName = owner;
		repositoryName = repository;

		if (branch[0] === 'tree') {
			branchName = branch[1];
			await saveRepository();
			return;
		}
		if (host === 'github.com') {
			host = 'api.github.com';
			type = 'github';
		}
		if (host === 'gitlab.com') {
			host = 'gitlab.com/api/v4';
			type = 'gitlab';
		}
		const apiUrl = `${protocol}://${host}`;

		const repositoryDetails = await get(`${apiUrl}/repos/${ownerName}/${repositoryName}`);
		projectId = repositoryDetails.id.toString();

		let branches: any[] = [];
		let page = 1;
		let branchCount = 0;
		loading.branches = true;
		const loadedBranches = await loadBranchesByPage(apiUrl, ownerName, repositoryName, page);
		branches = branches.concat(loadedBranches);
		branchCount = branches.length;
		if (branchCount === 100) {
			while (branchCount === 100) {
				page = page + 1;
				const nextBranches = await loadBranchesByPage(apiUrl, ownerName, repositoryName, page);
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
	async function loadBranchesByPage(apiUrl: string, owner: string, repository: string, page = 1) {
		return await get(`${apiUrl}/repos/${owner}/${repository}/branches?per_page=100&page=${page}`);
	}
	async function saveRepository(event?: any) {
		try {
			if (event?.detail?.value) {
				branchName = event.detail.value;
			}
			await post(`/applications/${id}/configuration/source`, {
				gitSourceId: null,
				forPublic: true,
				type
			});
			await post(`/applications/${id}/configuration/repository`, {
				repository: `${ownerName}/${repositoryName}`,
				branch: branchName,
				projectId,
				autodeploy: false,
				webhookToken: null,
				isPublicRepository: true
			});
			return await goto(`/applications/${id}/configuration/destination`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="title">Public repository link</div>
<Explainer text="Only works with Github.com and Gitlab.com" />
<div>
	<input bind:value={publicRepositoryLink} />
	<button on:click={loadBranches}>Load</button>
	{#if branchSelectOptions.length > 0}
		<div class="custom-select-wrapper">
			<Select
				placeholder={loading.branches
					? $t('application.configuration.loading_branches')
					: !publicRepositoryLink
					? $t('application.configuration.select_a_repository_first')
					: $t('application.configuration.select_a_branch')}
				isWaiting={loading.branches}
				showIndicator={!!publicRepositoryLink && !loading.branches}
				id="branches"
				on:select={saveRepository}
				items={branchSelectOptions}
				isDisabled={loading.branches || !!!publicRepositoryLink}
				isClearable={false}
			/>
		</div>
	{/if}
</div>
