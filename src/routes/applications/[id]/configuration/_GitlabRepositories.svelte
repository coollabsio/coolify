<script lang="ts">
	export let application;
	export let appId;
	import { page, session } from '$app/stores';
	import { onMount } from 'svelte';
	import { errorNotification } from '$lib/form';
	import { dev } from '$app/env';
	import cuid from 'cuid';
	import { goto } from '$app/navigation';
	import { del, get, post, put } from '$lib/api';

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

	let selected = {
		group: undefined,
		project: undefined,
		branch: undefined
	};
	onMount(async () => {
		if (!$session.gitlabToken) {
			getGitlabToken();
		} else {
			loading.base = true;
			try {
				const user = await get(`${apiUrl}/v4/user`, {
					Authorization: `Bearer ${$session.gitlabToken}`
				});
				username = user.username;
			} catch (error) {
				return getGitlabToken();
			}
			try {
				groups = await get(`${apiUrl}/v4/groups?per_page=5000`, {
					Authorization: `Bearer ${$session.gitlabToken}`
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

	async function loadProjects() {
		loading.projects = true;
		if (username === selected.group.name) {
			try {
				projects = await get(
					`${apiUrl}/v4/users/${selected.group.name}/projects?min_access_level=40&page=1&per_page=25&archived=false`,
					{
						Authorization: `Bearer ${$session.gitlabToken}`
					}
				);
			} catch (error) {
				errorNotification(error);
				throw new Error(error);
			} finally {
				loading.projects = false;
			}
		} else {
			try {
				projects = await get(
					`${apiUrl}/v4/groups/${selected.group.id}/projects?page=1&per_page=25&archived=false`,
					{
						Authorization: `Bearer ${$session.gitlabToken}`
					}
				);
			} catch (error) {
				errorNotification(error);
				throw new Error(error);
			} finally {
				loading.projects = false;
			}
		}
	}

	async function loadBranches() {
		loading.branches = true;
		try {
			branches = await get(
				`${apiUrl}/v4/projects/${selected.project.id}/repository/branches?per_page=100&page=1`,
				{
					Authorization: `Bearer ${$session.gitlabToken}`
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
		const url = `/applications/${id}/configuration/repository.json?repository=${selected.project.path_with_namespace}&branch=${selected.branch.name}`;

		try {
			await get(url);
			showSave = true;
		} catch (error) {
			showSave = false;
			return errorNotification('Branch already configured');
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
					Authorization: `Bearer ${$session.gitlabToken}`
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
				Authorization: `Bearer ${$session.gitlabToken}`
			});
			const deployKeyFound = deployKeys.filter((dk) => dk.title === `${appId}-coolify-deploy-key`);
			if (deployKeyFound.length > 0) {
				for (const deployKey of deployKeyFound) {
					console.log(`${deployKeyUrl}/${deployKey.id}`);
					await del(
						`${deployKeyUrl}/${deployKey.id}`,
						{},
						{
							Authorization: `Bearer ${$session.gitlabToken}`
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
					Authorization: `Bearer ${$session.gitlabToken}`
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
			await post(url, {
				repository: `${selected.group.full_path}/${selected.project.name}`,
				branch: selected.branch.name,
				projectId: selected.project.id,
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
		{#if loading.base}
			<select name="group" disabled class="w-96">
				<option selected value="">Loading groups...</option>
			</select>
		{:else}
			<select name="group" class="w-96" bind:value={selected.group} on:change={loadProjects}>
				<option value="" disabled selected>Please select a group</option>
				{#each groups as group}
					<option value={group}>{group.full_name}</option>
				{/each}
			</select>
		{/if}
		{#if loading.projects}
			<select name="project" disabled class="w-96">
				<option selected value="">Loading projects...</option>
			</select>
		{:else if !loading.projects && projects.length > 0}
			<select
				name="project"
				class="w-96"
				bind:value={selected.project}
				on:change={loadBranches}
				disabled={!selected.group}
			>
				<option value="" disabled selected>Please select a project</option>
				{#each projects as project}
					<option value={project}>{project.name}</option>
				{/each}
			</select>
		{:else}
			<select name="project" disabled class="w-96">
				<option disabled selected value="">No projects found</option>
			</select>
		{/if}

		{#if loading.branches}
			<select name="branch" disabled class="w-96">
				<option selected value="">Loading branches...</option>
			</select>
		{:else if !loading.branches && branches.length > 0}
			<select
				name="branch"
				class="w-96"
				bind:value={selected.branch}
				on:change={isBranchAlreadyUsed}
				disabled={!selected.project}
			>
				<option value="" disabled selected>Please select a branch</option>
				{#each branches as branch}
					<option value={branch}>{branch.name}</option>
				{/each}
			</select>
		{:else}
			<select name="project" disabled class="w-96">
				<option disabled selected value="">No branches found</option>
			</select>
		{/if}
	</div>
	<div class="flex flex-col items-center justify-center space-y-4 pt-5">
		<button
			on:click|preventDefault={save}
			class="w-40"
			type="submit"
			disabled={!showSave || loading.save}
			class:bg-orange-600={showSave && !loading.save}
			class:hover:bg-orange-500={showSave && !loading.save}
			>{loading.save ? 'Saving...' : 'Save'}</button
		>
	</div>
</form>
