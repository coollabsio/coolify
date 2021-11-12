<script lang="ts">
	export let source;
	import Explainer from '$lib/components/Explainer.svelte';
	import { enhance } from '$lib/form';
	import { page } from '$app/stores';
	const { id } = $page.params;

	let formEl;
	let payload = {
		name: undefined,
		groupName: undefined,
		appId: undefined,
		appSecret: undefined,
		applicationType: 'user'
	};
	function appChange() {}
	function newApp() {
		switch (payload.applicationType) {
			case 'user':
				window.location.assign(`${source.htmlUrl}/-/profile/applications`);
				break;
			case 'group':
				window.location.assign(
					`${source.htmlUrl}/groups/${payload.groupName}/-/settings/applications`
				);
				break;
			case 'instance':
				// TODO: This is not correct
				// window.location.assign(`${source.htmlUrl}/-/profile/applications`);
				break;
			default:
				break;
		}
		console.log(payload.applicationType);
	}
	console.log(source);
</script>

<div class="flex flex-col justify-center pb-8">
	{#if !source.gitlabApp.appId}
		<form
			class="grid grid-flow-row gap-2 py-4"
			bind:this={formEl}
			on:submit|preventDefault={newApp}
		>
			<div class="grid grid-cols-3 items-center">
				<label for="type">GitLab Application Type</label>

				<div class="col-span-2">
					<select
						name="type"
						id="type"
						class="w-96"
						bind:value={payload.applicationType}
						on:change={appChange}
					>
						<option value="user">User owned application</option>
						<option value="group">Group owned application</option>
						{#if source.htmlUrl !== 'https://gitlab.com'}
							<option value="instance">Instance-wide application (self-hosted)</option>
						{/if}
					</select>
				</div>
			</div>
			{#if payload.applicationType === 'group'}
				<div class="grid grid-cols-3 items-center">
					<label for="groupName">Group Name</label>
					<div class="col-span-2">
						<input name="groupName" id="groupName" required bind:value={payload.groupName} />
					</div>
				</div>
			{/if}

			<div class="w-full pt-10 text-center">
				<button class="bg-orange-600 hover:bg-orange-500 w-96" type="submit"
					>Add new application on GitLab</button
				>
				<div class="text-warmGray-400 text-xs pt-1">
					You need to create the GitLab application manually before you can configure it here. <br
					/>GitLab does not have a way to create it through API.
				</div>
			</div>
		</form>
		<form
			action={`/sources/${id}.json`}
			method="post"
			use:enhance={{
				result: async (res) => {
					const { id } = await res.json();
					console.log(id);
				}
			}}
			class="grid grid-flow-row gap-2 py-4 pt-10"
		>
			<div class="flex space-x-2 h-8 items-center">
				<div class="font-bold text-xl text-white">Configuration</div>
				<button type="submit" class="bg-orange-600 hover:bg-orange-500">Save</button>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="name">Name</label>
				<div class="col-span-2">
					<input
						name="name"
						id="name"
						placeholder="coolify-app"
						required
						bind:value={payload.name}
					/>
				</div>
			</div>
			{#if payload.applicationType === 'group'}
				<div class="grid grid-cols-3 items-center">
					<label for="groupName">Group Name</label>
					<div class="col-span-2">
						<input name="groupName" id="groupName" required bind:value={payload.groupName} />
					</div>
				</div>
			{/if}
			<div class="grid grid-cols-3 items-center">
				<label for="appId">Application Id</label>
				<div class="col-span-2">
					<input name="appId" id="appId" required bind:value={payload.appId} />
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="appSecret">Secret</label>
				<div class="col-span-2">
					<input
						name="appSecret"
						id="appSecret"
						type="password"
						required
						bind:value={payload.appSecret}
					/>
				</div>
			</div>
		</form>
	{:else}
		<div>{source.gitlabApp.name}</div>
	{/if}
</div>
