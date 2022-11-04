<script lang="ts">
	export let application: any;
	export let appId: any;
	export let settings: any;
	//@ts-ignore
	import cuid from 'cuid';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';
	import { dev } from '$app/env';
	import { goto } from '$app/navigation';
	import { del, get, getAPIUrl, getWebhookUrl, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import { appSession } from '$lib/store';
	import Select from 'svelte-select';

	const { id } = $page.params;
	const from = $page.url.searchParams.get('from');

	let url = settings?.fqdn ? settings.fqdn : window.location.origin;
	if (dev) url = getAPIUrl();

	const updateDeployKeyIdUrl = `/applications/${id}/configuration/deploykey`;

	let loading = {
		base: true,
		projects: false,
		branches: false,
		save: false
	};
	let tryAgain = false;

	let htmlUrl = application.gitSource.htmlUrl;
	let apiUrl = application.gitSource.apiUrl;

	let username: any = null;
	let groups: any = [];
	let projects: any = [];
	let branches: any = [];
	let showSave = false;
	let autodeploy = application.settings.autodeploy || true;

	let selected: any = {
		group: undefined,
		project: undefined,
		branch: undefined
	};

	onMount(async () => {
		if (!$appSession.tokens.gitlab) {
			await getGitlabToken();
		}
		loading.base = true;
		try {
			const user = await get(`${apiUrl}/v4/user`, {
				Authorization: `Bearer ${$appSession.tokens.gitlab}`
			});
			username = user.username;
			await loadGroups();
		} catch (error) {
			loading.base = false;
			tryAgain = true;
		}
	});
	function selectGroup(event: any) {
		selected.group = event.detail;
		selected.project = null;
		selected.branch = null;
		showSave = false;

		// Clear out projects
		projects = [];
		loadProjects();
	}
	function selectProject(event: any) {
		selected.project = event.detail;
		selected.branch = null;
		showSave = false;
		loadBranches();
	}
	async function getGitlabToken() {
		return await new Promise<void>((resolve, reject) => {
			const left = screen.width / 2 - 1020 / 2;
			const top = screen.height / 2 - 618 / 2;
			const newWindow = open(
				`${htmlUrl}/oauth/authorize?client_id=${application.gitSource.gitlabApp.appId}&redirect_uri=${url}/webhooks/gitlab&response_type=code&scope=api+email+read_repository&state=${$page.params.id}`,
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
					$appSession.tokens.gitlab = localStorage.getItem('gitLabToken');
					// localStorage.removeItem('gitLabToken');
					resolve();
				}
			}, 100);
		});
	}
	async function loadGroups(page: number = 1) {
		let perPage = 100;
		//@ts-ignore
		const params: any = new URLSearchParams({
			page,
			per_page: perPage
		});
		loading.base = true;
		try {
			const newGroups = await get(`${apiUrl}/v4/groups?${params}`, {
				Authorization: `Bearer ${$appSession.tokens.gitlab}`
			});
			groups = groups.concat(newGroups);
			if (newGroups.length === perPage) {
				await loadGroups(page + 1);
			}
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.base = false;
		}
	}
	async function loadProjects(page: number = 1) {
		let perPage = 100;
		//@ts-ignore
		const params: any = new URLSearchParams({
			page,
			per_page: perPage,
			archived: false
		});
		loading.projects = true;
		if (username === selected.group.name) {
			try {
				params.append('min_access_level', 40);
				const newProjects = await get(
					`${apiUrl}/v4/users/${selected.group.name}/projects?${params}`,
					{
						Authorization: `Bearer ${$appSession.tokens.gitlab}`
					}
				);
				projects = projects.concat(newProjects);
				if (newProjects.length === perPage) {
					await loadProjects(page + 1);
				}
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.projects = false;
			}
		} else {
			try {
				const newProjects = await get(
					`${apiUrl}/v4/groups/${selected.group.id}/projects?${params}`,
					{
						Authorization: `Bearer ${$appSession.tokens.gitlab}`
					}
				);
				projects = projects.concat(newProjects);
				if (newProjects.length === perPage) {
					await loadProjects(page + 1);
				}
			} catch (error) {
				return errorNotification(error);
			} finally {
				loading.projects = false;
			}
		}
	}
	async function loadBranches(page: number = 1) {
		let perPage = 100;
		//@ts-ignore
		const params = new URLSearchParams({
			page,
			per_page: perPage
		});
		loading.branches = true;
		try {
			const newBranches = await get(
				`${apiUrl}/v4/projects/${selected.project.id}/repository/branches?${params}`,
				{
					Authorization: `Bearer ${$appSession.tokens.gitlab}`
				}
			);
			branches = branches.concat(newBranches);
			if (newBranches.length === perPage) {
				await loadBranches(page + 1);
			}
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.branches = false;
		}
	}

	async function selectBranch(event: any) {
		selected.branch = event.detail;
		showSave = true;
	}

	async function checkSSHKey(sshkeyUrl: any) {
		try {
			return await post(sshkeyUrl, {});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function setWebhook(url: any, webhookToken: any) {
		const host = dev ? getWebhookUrl('gitlab') : `${window.location.origin}/webhooks/gitlab/events`;
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
					Authorization: `Bearer ${$appSession.tokens.gitlab}`
				}
			);
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function save() {
		loading.save = true;
		let privateSshKey = application.gitSource.gitlabApp.privateSshKey;
		let publicSshKey = application.gitSource.gitlabApp.publicSshKey;

		const deployKeyUrl = `${apiUrl}/v4/projects/${selected.project.id}/deploy_keys`;
		const sshkeyUrl = `/applications/${id}/configuration/sshkey`;
		const webhookUrl = `${apiUrl}/v4/projects/${selected.project.id}/hooks`;
		const webhookToken = cuid();

		try {
			if (!privateSshKey || !publicSshKey) {
				const data: any = await checkSSHKey(sshkeyUrl);
				publicSshKey = data.publicKey;
			}
			const deployKeys = await get(deployKeyUrl, {
				Authorization: `Bearer ${$appSession.tokens.gitlab}`
			});
			const deployKeyFound = deployKeys.filter(
				(dk: any) => dk.title === `${appId}-coolify-deploy-key`
			);
			if (deployKeyFound.length > 0) {
				for (const deployKey of deployKeyFound) {
					await del(
						`${deployKeyUrl}/${deployKey.id}`,
						{},
						{
							Authorization: `Bearer ${$appSession.tokens.gitlab}`
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
					Authorization: `Bearer ${$appSession.tokens.gitlab}`
				}
			);
			await post(updateDeployKeyIdUrl, { deployKeyId: id });
		} catch (error) {
			loading.save = false;
			return errorNotification(error);
		}

		try {
			await setWebhook(webhookUrl, webhookToken);
		} catch (error) {
			loading.save = false;
			return errorNotification(error);
		}

		const url = `/applications/${id}/configuration/repository`;
		try {
			const repository = selected.project.path_with_namespace;
			await post(url, {
				repository,
				branch: selected.branch.name,
				projectId: selected.project.id,
				autodeploy,
				webhookToken
			});
			loading.save = false;
			return await goto(from || `/applications/${id}/configuration/buildpack`);
		} catch (error) {
			loading.save = false;
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			await post(`/applications/${id}/configuration/repository`, { ...selected });
			return await goto(from || `/applications/${id}/configuration/destination`);
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<form on:submit={handleSubmit}>
	<div
		class="flex lg:flex-row flex-col lg:space-y-0 space-y-2 space-x-0 lg:space-x-2 items-center lg:justify-center lg:px-0 px-8"
	>
		<div class="custom-select-wrapper w-full">
			<label for="groups" class="pb-1">Groups</label>
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
		<div class="custom-select-wrapper w-full">
			<label for="projects" class="pb-1">Projects</label>
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
				labelIdentifier="name"
				optionIdentifier="id"
				isSearchable={true}
			/>
		</div>
		<div class="custom-select-wrapper w-full">
			<label for="branches" class="pb-1">Branches</label>
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
				isSearchable={true}
				labelIdentifier="name"
				optionIdentifier="web_url"
			/>
		</div>
	</div>
	<div class="flex flex-col items-center justify-center space-y-4 pt-5">
		<button
			on:click|preventDefault={save}
			class="btn btn-wide"
			type="submit"
			disabled={!showSave || loading.save}
			>{loading.save ? $t('forms.saving') : $t('forms.save')}</button
		>
		{#if tryAgain}
			<div class="p-5">
				An error occured during authenticating with GitLab. Please check your GitLab Source
				configuration <a href={`/sources/${application.gitSource.id}`}>here.</a>
			</div>
			<button
				class="btn btn-sm w-40 btn-primary"
				on:click|stopPropagation|preventDefault={() => window.location.reload()}
			>
				Try again
			</button>
		{/if}
	</div>
</form>
