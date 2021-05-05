<script lang="ts">
	import { goto } from '$app/navigation';
	import { request } from '$lib/fetch';
	import { page, session } from '$app/stores';
	import { dashboard, githubRepositories, application } from '$store';
	import { onMount } from 'svelte';

	const found = $dashboard.applications.deployed.find(
		(c) =>
			c.configuration.repository.branch === $page.params.branch &&
			c.configuration.repository.organization === $page.params.organization &&
			c.configuration.repository.name === $page.params.name
	)?.configuration;

	if (found) {
		$application = found;
	} else {
		goto('/dashboard/applications');
	}

	let loading = {
		github: false
	};
	onMount(async () => {
		await loadGithubRepositories();
	});
	async function getGithubRepos(id, page) {
		return await request(
			`https://api.github.com/user/installations/${id}/repositories?per_page=100&page=${page}`,
			$session
		);
	}
	async function loadGithubRepositories() {
		if ($githubRepositories.length > 0) {
		} else {
			loading.github = true;
			const { installations } = await request(
				'https://api.github.com/user/installations',
				$session
			);
			if (installations.length === 0) {
				return false;
			}
			$application.github.installation.id = installations[0].id;
			$application.github.app.id = installations[0].app_id;

			let page = 1;
			let userRepos = 0;
			const data = await getGithubRepos($application.github.installation.id, page);
			$githubRepositories = $githubRepositories.concat(data.repositories);
			userRepos = data.total_count;
			if (userRepos > $githubRepositories.length) {
				while (userRepos > $githubRepositories.length) {
					page = page + 1;
					const repos = await getGithubRepos($application.github.installation.id, page);
					$githubRepositories = $githubRepositories.concat(repos.repositories);
				}
			}
			const foundRepositoryOnGithub = $githubRepositories.find(
				(r) =>
					r.full_name === `${$application.repository.organization}/${$application.repository.name}`
			);
			if (foundRepositoryOnGithub) {
				$application.repository.id = foundRepositoryOnGithub.id;
				// await loadBranches();
			}
		}
	}
</script>

<div />
Configuration
{JSON.stringify($application)}
