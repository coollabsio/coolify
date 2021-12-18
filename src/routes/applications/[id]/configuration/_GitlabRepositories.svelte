<script lang="ts">
	export let application;
	export let gitlabToken;
	import { page } from '$app/stores';
	import { onMount } from 'svelte';

	import { enhance, errorNotification } from '$lib/form';
	import { goto } from '$app/navigation';
	import { dev } from '$app/env';

	const { id } = $page.params;
	const from = $page.query.get('from');

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
		if (!gitlabToken) {
			getGitlabToken();
		} else {
			// https://gitlab.com/api/v4/user
			// https://gitlab.com/api/v4/groups?per_page=5000
			// https://gitlab.com/api/v4/users/andrasbacsai/projects?min_access_level=40&page=1&per_page=25 or https://gitlab.com/api/v4/groups/3086145/projects?page=1&per_page=25
			// https://gitlab.com/api/v4/projects/7260661/repository/branches?per_page=100&page=1

			// https://gitlab.com/api/v4/projects/coollabsio%2FcoolLabs.io-frontend-v1/repository/tree?per_page=100&ref=master
			// https://gitlab.com/api/v4/projects/7260661/repository/files/package.json?ref=master

			// https://gitlab.com/api/v4/projects/7260661/deploy_keys - Create deploy keys with ssh-keys?
			// TODO: this is not working!!!! https://gitlab.com/api/v4/projects/7260661/hooks - set webhook for project
			loading.base = true;

			let response = await fetch(`${apiUrl}/v4/user`, {
				method: 'GET',
				headers: {
					Authorization: `Bearer ${gitlabToken}`
				}
			});

			if (response.ok) {
				const user = await response.json();
				username = user.username;
			} else {
				if (response.status === 401) {
					getGitlabToken();
				} else {
					errorNotification(response);
					throw new Error(response.statusText);
				}
			}

			response = await fetch(`${apiUrl}/v4/groups?per_page=5000`, {
				method: 'GET',
				headers: {
					Authorization: `Bearer ${gitlabToken}`
				}
			});
			if (response.ok) {
				const data = await response.json();
				groups = data;
			} else {
				console.error(response);
				throw new Error(response.statusText);
			}
			loading.base = false;
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
			const response = await fetch(
				`${apiUrl}/v4/users/${selected.group.name}/projects?min_access_level=40&page=1&per_page=25&archived=false`,
				{
					method: 'GET',
					headers: {
						Authorization: `Bearer ${gitlabToken}`
					}
				}
			);

			if (response.ok) {
				const data = await response.json();
				projects = data;
			} else {
				console.error(response);
				throw new Error(response.statusText);
			}
		} else {
			const response = await fetch(
				`${apiUrl}/v4/groups/${selected.group.id}/projects?page=1&per_page=25&archived=false`,
				{
					method: 'GET',
					headers: {
						Authorization: `Bearer ${gitlabToken}`
					}
				}
			);

			if (response.ok) {
				const data = await response.json();
				projects = data;
			} else {
				console.error(response);
				loading.projects = false;
				throw new Error(response.statusText);
			}
		}
		loading.projects = false;
	}

	async function loadBranches() {
		loading.branches = true;
		const response = await fetch(
			`${apiUrl}/v4/projects/${selected.project.id}/repository/branches?per_page=100&page=1`,
			{
				method: 'GET',
				headers: {
					Authorization: `Bearer ${gitlabToken}`
				}
			}
		);

		if (response.ok) {
			const data = await response.json();
			branches = data;
		} else {
			console.error(response);
			loading.branches = false;
			throw new Error(response.statusText);
		}
		loading.branches = false;
	}

	async function isBranchAlreadyUsed() {
		const url = `/applications/${id}/configuration/repository.json?repository=${selected.project.name}&branch=${selected.branch.name}`;
		const res = await fetch(url);
		if (res.ok) {
			errorNotification('Branch already configured');
			return;
		}
		showSave = true;
	}
	async function checkDeployKey(deployKeyUrl, updateDeployKeyIdUrl) {
		const response = await fetch(deployKeyUrl, {
			method: 'GET',
			headers: {
				Authorization: `Bearer ${gitlabToken}`
			}
		});
		if (response.ok) {
			const deployKeys = await response.json();
			const deployKey = deployKeys.find((key) => key.title === 'coolify-deploy-key');
			console.log(deployKey);
			if (deployKey) {
				return await saveDeployKey(updateDeployKeyIdUrl, deployKey.id);
			}
		}
		return;
	}
	async function saveDeployKey(updateDeployKeyIdUrl, deployKeyId) {
		const form = new FormData();
		form.append('deployKeyId', deployKeyId);

		const response = await fetch(updateDeployKeyIdUrl, {
			method: 'POST',
			body: form
		});
		if (!response.ok) {
			throw new Error(response.statusText);
		}
		return;
	}
	async function checkSSHKey(sshkeyUrl, deployKeyUrl, updateDeployKeyIdUrl) {
		let response = await fetch(sshkeyUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				accept: 'application/json'
			},
			body: JSON.stringify({})
		});
		if (!response.ok) {
			throw new Error(response.statusText);
		}
		const { publicKey } = await response.json();
		response = await fetch(deployKeyUrl, {
			method: 'POST',
			body: JSON.stringify({
				title: 'coolify-deploy-key',
				key: publicKey,
				can_push: false
			}),
			headers: {
				Authorization: `Bearer ${gitlabToken}`,
				'Content-Type': 'application/json'
			}
		});
		if (!response.ok) {
			throw new Error(response.statusText);
		}
		const { id } = await response.json();
		if (!id) {
			throw new Error('No id');
		}
		return await saveDeployKey(updateDeployKeyIdUrl, id);
	}
	async function setWebhook(url) {
		const host = window.location.origin;
		const response = await fetch(url, {
			method: 'GET',
			headers: {
				Authorization: `Bearer ${gitlabToken}`,
				'Content-Type': 'application/json'
			}
		});
		const urls = await response.json();
		const found = urls.find((url) => url.url.startsWith(host));
		if (!found) {
			const response = await fetch(url, {
				method: 'POST',
				headers: {
					Authorization: `Bearer ${gitlabToken}`,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					id: selected.project.id,
					url: `${host}/webhooks/gitlab/events`,
					push_events: true,
					enable_ssl_verification: true,
					merge_requests_events: true
				})
			});
			if (!response.ok) {
				const error = await response.json();
				errorNotification(error);
				throw error;
			}
		}
	}
	async function save() {
		loading.save = true;
		let privateSshKey = application.gitSource.gitlabApp.privateSshKey;

		const deployKeyUrl = `${apiUrl}/v4/projects/${selected.project.id}/deploy_keys`;
		const updateDeployKeyIdUrl = `/applications/${id}/configuration/deploykey.json`;
		const sshkeyUrl = `/applications/${id}/configuration/sshkey.json`;
		const webhookUrl = `${apiUrl}/v4/projects/${selected.project.id}/hooks`;

		try {
			if (!privateSshKey) {
				await checkSSHKey(sshkeyUrl, deployKeyUrl, updateDeployKeyIdUrl);
			}
		} catch (error) {
			console.log(error);
			throw new Error(error);
		}

		try {
			await setWebhook(webhookUrl);
		} catch (err) {
			console.log(err);
			if (!dev) throw new Error(err);
		}

		const url = `/applications/${id}/configuration/repository.json`;
		const form = new FormData();
		form.append('repository', `${selected.group.full_path}/${selected.project.name}`);
		form.append('branch', selected.branch.name);
		form.append('projectId', selected.project.id);

		const response = await fetch(url, {
			method: 'POST',
			headers: {
				accept: 'application/json'
			},
			body: form
		});
		if (response.ok) {
			loading.save = false;
			window.location.replace(from || `/applications/${id}/configuration/buildpack`);
		}
	}
</script>

<form
	action="/applications/{id}/configuration/repository.json"
	method="post"
	use:enhance={{
		result: async () => {
			loading.save = false;
			window.location.assign(from || `/applications/${id}/configuration/buildpack`);
		},
		pending: async () => {
			loading.save = true;
		}
	}}
>
	<div class="px-4 space-y-2 xl:space-y-0 flex xl:flex-row flex-col xl:space-x-2 ">
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
	<div class="pt-5 flex-col flex justify-center items-center space-y-4">
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
