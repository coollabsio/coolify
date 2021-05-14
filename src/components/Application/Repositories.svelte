<script>
	import { createEventDispatcher } from 'svelte';
	import { application, githubRepositories } from '$store';
	import Select from 'svelte-select';
	import { page } from '$app/stores';
	function handleSelect(event) {
		$application.build.pack = 'static';
		$application.repository.id = parseInt(event.detail.value, 10);
		$application.repository.branch = null
		dispatch('loadBranches');
	}
	const path = $page.path === '/application/new';
	let items = $githubRepositories.map((repo) => ({
		label: `${repo.owner.login}/${repo.name}`,
		value: repo.id.toString()
	}));

	const selectedValue =
		!path && `${$application.repository.organization}/${$application.repository.name}`;

	const dispatch = createEventDispatcher();
	const modifyGithubAppConfig = () => dispatch('modifyGithubAppConfig');
</script>

<div class="grid grid-cols-1 pt-4">
	{#if $githubRepositories.length !== 0}
		<label for="repository">Organization / Repository</label>
		<div class="grid grid-cols-3 ">
			<div class="repository-select-search col-span-2">
				<Select
					isFocused="{true}"
					containerClasses="w-full border-none bg-transparent"
					on:select={handleSelect}
					{selectedValue}
					isClearable={false}
					{items}
					showIndicator={path}
					noOptionsMessage="No Repositories found"
					placeholder="Select a Repository"
					isDisabled={!path}
				/>
			</div>
			<button
				class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white"
				on:click={modifyGithubAppConfig}>Configure on Github</button
			>
		</div>
	{:else}
		<button
			class="button col-span-1 ml-2 bg-warmGray-800 hover:bg-warmGray-700 text-white py-2"
			on:click={modifyGithubAppConfig}>Add repositories on Github</button
		>
	{/if}
</div>
