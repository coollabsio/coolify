<script>
	import { page } from '$app/stores';

	export let loading, branches;
	import { application } from '$store';
	import Select from 'svelte-select';

	const selectedValue = $page.path !== '/application/new' && $application.repository.branch;

	function handleSelect(event) {
		$application.repository.branch = null;
		setTimeout(() => {
			$application.repository.branch = event.detail.value;
		}, 1);
	}
</script>

{#if loading}
	<div class="grid grid-cols-1">
		<label for="branch">Branch</label>
		<div class="repository-select-search col-span-2">
			<Select
				containerClasses="w-full border-none bg-transparent"
				placeholder="Loading branches..."
				isDisabled
			/>
		</div>
	</div>
{:else}
	<div class="grid grid-cols-1">
		<label for="branch">Branch</label>
		<div class="repository-select-search col-span-2">
			<Select
				containerClasses="w-full border-none bg-transparent"
				on:select={handleSelect}
				{selectedValue}
				isClearable={false}
				items={branches.map((b) => ({ label: b.name, value: b.name }))}
				showIndicator={$page.path === '/application/new'}
				noOptionsMessage="No branches found"
				placeholder="Select a branch"
				isDisabled={$page.path !== '/application/new'}
			/>
		</div>
	</div>
{/if}
