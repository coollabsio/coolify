<script lang="ts">
	export let application;
	export let appId;
	import Select from 'svelte-select';
	import { page, session } from '$app/stores';
	import { onMount } from 'svelte';
	import { errorNotification } from '$lib/form';
	import { dev } from '$app/env';
	import cuid from 'cuid';
	import { goto } from '$app/navigation';
	import { del, get, post, put } from '$lib/api';
	import { gitTokens } from '$lib/store';
	import { t } from '$lib/translations';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	const updateDeployKeyIdUrl = `/applications/${id}/configuration/deploykey.json`;

	let loading = {
		base: true,
		projects: false,
		branches: false,
		save: false
	};

	let htmlUrl = application.gitSource.htmlUrl;
	let apiUrl = application.gitSource.apiUrl;

	let username = null;
	let groups = [];
	let projects = [];
	let branches = [];
	let showSave = false;
	let autodeploy = application.settings.autodeploy || true;

	let search = {
		project: '',
		branch: ''
	};
	let selected = {
		group: undefined,
		project: undefined,
		branch: undefined
	};
	onMount(async () => {
		if (!$gitTokens.gitlabToken) {
			getGitlabToken();
		} else {
			loading.base = true;
			try {
				const user = await get(`${apiUrl}/v4/user`, {
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				});
				username = user.username;
			} catch (error) {
				return getGitlabToken();
			}
			try {
				groups = await get(`${apiUrl}/v4/groups?per_page=5000`, {
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				});
			} catch (error) {
				errorNotification(error);
				throw new Error(error);
			} finally {
				loading.base = false;
			}
		}
	});

	function getGitlabToken() {
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

	function selectGroup(event) {
		selected.group = event.detail;
		selected.project = null;
		selected.branch = null;
		showSave = false;
		loadProjects();
	}

	async function searchProjects(searchText) {
		if (!selected.group) {
			return;
		}

		search.project = searchText;
		await loadProjects();
		return projects;
	}

	function selectProject(event) {
		selected.project = event.detail;
		selected.branch = null;
		showSave = false;
		loadBranches();
	}

	async function loadProjects() {
		const params = new URLSearchParams({
			page: 1,
			per_page: 25,
			archived: false
		});

		if (search.project) {
			params.append('search', search.project);
		}

		loading.projects = true;
		if (username === selected.group.name) {
			try {
				params.append('min_access_level', 40);
				projects = await get(`${apiUrl}/v4/users/${selected.group.name}/projects?${params}`, {
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				});
			} catch (error) {
				errorNotification(error);
				throw new Error(error);
			} finally {
				loading.projects = false;
			}
		} else {
			try {
				projects = await get(`${apiUrl}/v4/groups/${selected.group.id}/projects?${params}`, {
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				});
			} catch (error) {
				errorNotification(error);
				throw new Error(error);
			} finally {
				loading.projects = false;
			}
		}
	}

	async function searchBranches(searchText) {
		if (!selected.project) {
			return;
		}

		search.branch = searchText;
		await loadBranches();
		return branches;
	}

	function selectBranch(event) {
		selected.branch = event.detail;
		isBranchAlreadyUsed();
	}

	async function loadBranches() {
		const params = new URLSearchParams({
			page: 1,
			per_page: 100
		});

		if (search.branch) {
			params.append('search', search.branch);
		}

		loading.branches = true;
		try {
			branches = await get(
				`${apiUrl}/v4/projects/${selected.project.id}/repository/branches?${params}`,
				{
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				}
			);
		} catch (error) {
			errorNotification(error);
			throw new Error(error);
		} finally {
			loading.branches = false;
		}
	}

	async function isBranchAlreadyUsed() {
		try {
			const data = await get(
				`/applications/${id}/configuration/repository.json?repository=${selected.project.path_with_namespace}&branch=${selected.branch.name}`
			);
			if (data.used) {
				const sure = confirm($t('application.configuration.branch_already_in_use'));
				if (sure) {
					autodeploy = false;
					showSave = true;
					return true;
				}
				showSave = false;
				return true;
			}
			showSave = true;
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function checkSSHKey(sshkeyUrl) {
		try {
			return await post(sshkeyUrl, {});
		} catch (error) {
			errorNotification(error);
			throw new Error(error);
		}
	}
	async function setWebhook(url, webhookToken) {
		const host = dev
			? 'https://webhook.site/0e5beb2c-4e9b-40e2-a89e-32295e570c21'
			: `${window.location.origin}/webhooks/gitlab/events`;
		try {
			await post(
				url,
				{
					id: selected.project.id,
					url: host,
					token: webhookToken,
					push_events: true,
					enable_ssl_verification: true,
					merge_requests_events: true
				},
				{
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				}
			);
		} catch (error) {
			errorNotification(error);
			throw error;
		}
	}
	async function save() {
		loading.save = true;
		let privateSshKey = application.gitSource.gitlabApp.privateSshKey;
		let publicSshKey = application.gitSource.gitlabApp.publicSshKey;

		const deployKeyUrl = `${apiUrl}/v4/projects/${selected.project.id}/deploy_keys`;
		const sshkeyUrl = `/applications/${id}/configuration/sshkey.json`;
		const webhookUrl = `${apiUrl}/v4/projects/${selected.project.id}/hooks`;
		const webhookToken = cuid();

		try {
			if (!privateSshKey || !publicSshKey) {
				const { publicKey } = await checkSSHKey(sshkeyUrl);
				publicSshKey = publicKey;
			}
			const deployKeys = await get(deployKeyUrl, {
				Authorization: `Bearer ${$gitTokens.gitlabToken}`
			});
			const deployKeyFound = deployKeys.filter((dk) => dk.title === `${appId}-coolify-deploy-key`);
			if (deployKeyFound.length > 0) {
				for (const deployKey of deployKeyFound) {
					await del(
						`${deployKeyUrl}/${deployKey.id}`,
						{},
						{
							Authorization: `Bearer ${$gitTokens.gitlabToken}`
						}
					);
				}
			}
			const { id } = await post(
				deployKeyUrl,
				{
					title: `${appId}-coolify-deploy-key`,
					key: publicSshKey,
					can_push: false
				},
				{
					Authorization: `Bearer ${$gitTokens.gitlabToken}`
				}
			);
			await post(updateDeployKeyIdUrl, { deployKeyId: id });
		} catch (error) {
			console.log(error);
			throw new Error(error);
		}

		try {
			await setWebhook(webhookUrl, webhookToken);
		} catch (err) {
			console.log(err);
			if (!dev) throw new Error(err);
		}

		const url = `/applications/${id}/configuration/repository.json`;
		try {
			const repository = selected.project.path_with_namespace;
			await post(url, {
				repository,
				branch: selected.branch.name,
				projectId: selected.project.id,
				autodeploy,
				webhookToken
			});
			return await goto(from || `/applications/${id}/configuration/buildpack`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/applications/{id}/configuration/repository.json`, { ...selected });
			return await goto(from || `/applications/${id}/configuration/destination`);
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
</script>

<form on:submit={handleSubmit}>
	<div class="flex flex-col space-y-2 px-4 xl:flex-row xl:space-y-0 xl:space-x-2 ">
		<div class="custom-select-wrapper">
			<Select
				placeholder={loading.base
					? $t('application.configuration.loading_groups')
					: $t('application.configuration.select_a_group')}
				id="group"
				showIndicator={!loading.base}
				isWaiting={loading.base}
				on:select={selectGroup}
				on:clear={() => {
					showSave = false;
					projects = [];
					branches = [];
					selected.group = null;
					selected.project = null;
					selected.branch = null;
				}}
				value={selected.group}
				isDisabled={loading.base}
				isClearable={false}
				items={groups}
				labelIdentifier="full_name"
				optionIdentifier="id"
			/>
		</div>
		<div class="custom-select-wrapper">
			<Select
				placeholder={loading.projects
					? $t('application.configuration.loading_projects')
					: $t('application.configuration.select_a_project')}
				noOptionsMessage={$t('application.configuration.no_projects_found')}
				id="project"
				showIndicator={!loading.projects}
				isWaiting={loading.projects}
				isDisabled={loading.projects || !selected.group}
				on:select={selectProject}
				on:clear={() => {
					showSave = false;
					branches = [];
					selected.project = null;
					selected.branch = null;
				}}
				value={selected.project}
				isClearable={false}
				items={projects}
				loadOptions={searchProjects}
				labelIdentifier="name"
				optionIdentifier="id"
			/>
		</div>
		<div class="custom-select-wrapper">
			<Select
				placeholder={loading.branches
					? $t('application.configuration.loading_branches')
					: $t('application.configuration.select_a_branch')}
				noOptionsMessage={$t('application.configuration.no_branches_found')}
				id="branch"
				showIndicator={!loading.branches}
				isWaiting={loading.branches}
				isDisabled={loading.branches || !selected.project}
				on:select={selectBranch}
				on:clear={() => {
					showSave = false;
					selected.branch = null;
				}}
				value={selected.branch}
				isClearable={false}
				items={branches}
				loadOptions={searchBranches}
				labelIdentifier="name"
				optionIdentifier="web_url"
			/>
		</div>
	</div>
	<div class="flex flex-col items-center justify-center space-y-4 pt-5">
		<button
			on:click|preventDefault={save}
			class="w-40"
			type="submit"
			disabled={!showSave || loading.save}
			class:bg-orange-600={showSave && !loading.save}
			class:hover:bg-orange-500={showSave && !loading.save}
			>{loading.save ? $t('forms.saving') : $t('forms.save')}</button
		>
	</div>
</form>
