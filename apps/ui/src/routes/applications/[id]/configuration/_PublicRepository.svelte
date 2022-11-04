<script lang="ts">
	import { get, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { page } from '$app/stores';

	import Select from 'svelte-select';
	import { goto } from '$app/navigation';
	import { errorNotification } from '$lib/common';

	const { id } = $page.params;

	let publicRepositoryLink: string;
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
		try {
			if (!publicRepositoryLink) return
			loading.branches = true;
			publicRepositoryLink = publicRepositoryLink.trim();
			const protocol = publicRepositoryLink.split(':')[0];
			const gitUrl = publicRepositoryLink.replace('http://', '').replace('https://', '');

			let [host, ...path] = gitUrl.split('/');
			const [owner, repository, ...branch] = path;

			ownerName = owner;
			repositoryName = repository;

			if (host === 'github.com') {
				host = 'api.github.com';
				type = 'github';
				if (branch[0] === 'tree' && branch[1]) {
					branchName = branch[1];
				}
				if (branch.length === 1) {
					branchName = branch[0]
				}
			}
			if (host === 'gitlab.com') {
				host = 'gitlab.com/api/v4';
				type = 'gitlab';
				if (branch[1] === 'tree' && branch[2]) {
					branchName = branch[2];
				}
				if (branch.length === 1) {
					branchName = branch[0]
				}
			}
			const apiUrl = `${protocol}://${host}`;
			if (type === 'github') {
				const repositoryDetails = await get(`${apiUrl}/repos/${ownerName}/${repositoryName}`);
				projectId = repositoryDetails.id.toString();
			}
			if (type === 'gitlab') {
				const repositoryDetails = await get(`${apiUrl}/projects/${ownerName}%2F${repositoryName}`);
				projectId = repositoryDetails.id.toString();
			}
			if (type === 'github' && branchName) {
				try {
					await get(`${apiUrl}/repos/${ownerName}/${repositoryName}/branches/${branchName}`);
					await saveRepository();
					loading.branches = false;
					return;
				} catch (error) {
					errorNotification(error);
				}
			}
			if (type === 'gitlab' && branchName) {
				try {
					await get(
						`${apiUrl}/projects/${ownerName}%2F${repositoryName}/repository/branches/${branchName}`
					);
					await saveRepository();
					loading.branches = false;
					return;
				} catch (error) {
					errorNotification(error);
				}
			}
			let branches: any[] = [];
			let page = 1;
			let branchCount = 0;
			const loadedBranches = await loadBranchesByPage(
				apiUrl,
				ownerName,
				repositoryName,
				page,
				type
			);
			branches = branches.concat(loadedBranches);
			branchCount = branches.length;
			if (branchCount === 100) {
				while (branchCount === 100) {
					page = page + 1;
					const nextBranches = await loadBranchesByPage(
						apiUrl,
						ownerName,
						repositoryName,
						page,
						type
					);
					branches = branches.concat(nextBranches);
					branchCount = nextBranches.length;
				}
			}
			loading.branches = false;
			branchSelectOptions = branches.map((branch: any) => ({
				value: branch.name,
				label: branch.name
			}));
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.branches = false;
		}
	}
	async function loadBranchesByPage(
		apiUrl: string,
		owner: string,
		repository: string,
		page = 1,
		type: string
	) {
		if (type === 'github') {
			return await get(`${apiUrl}/repos/${owner}/${repository}/branches?per_page=100&page=${page}`);
		}
		if (type === 'gitlab') {
			return await get(
				`${apiUrl}/projects/${ownerName}%2F${repositoryName}/repository/branches?page=${page}`
			);
		}
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

<div class="mx-auto max-w-screen-2xl">
	<form class="flex flex-col" on:submit|preventDefault={loadBranches}>
		<div class="flex flex-col space-y-2 w-full">
			<div class="flex flex-row space-x-2"><input
				class="w-full"
				placeholder="eg: https://github.com/coollabsio/nodejs-example/tree/main"
				bind:value={publicRepositoryLink}
			/>
				<button class="btn btn-primary" disabled={loading.branches} type="submit" class:loading={loading.branches}>
					Load Repository
				</button>
			</div>
			
			<div class="custom-select-wrapper">
				<Select
					placeholder={loading.branches
						? $t('application.configuration.loading_branches')
						: branchSelectOptions.length ===0
						? 'Please type a repository link first.'
						: $t('application.configuration.select_a_branch')}
					isWaiting={loading.branches}
					showIndicator={!!publicRepositoryLink && !loading.branches}
					id="branches"
					on:select={saveRepository}
					items={branchSelectOptions}
					isDisabled={loading.branches || !ownerName}
					isClearable={false}
				/>
			</div>
		</div>
	</form>
</div>
