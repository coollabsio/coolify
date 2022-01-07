<script lang="ts">
	export let database;
	import { page, session } from '$app/stores';
	import Setting from '$lib/components/Setting.svelte';
	import { enhance } from '$lib/form';

	const { id } = $page.params;
	let loading = false;
	let isPublic = database.settings.isPublic || false;

	async function changeSettings(name) {
		const form = new FormData();
		if (name === 'isPublic') {
			isPublic = !isPublic;
		}

		form.append('isPublic', isPublic.toString());

		try {
			await fetch(`/databases/${id}/settings.json`, {
				method: 'POST',
				body: form
			});
			window.location.reload()
		} catch (e) {
			console.error(e);
		}
	}
</script>

<div class="max-w-4xl mx-auto px-6">
	<form
		action="/databases/{id}.json"
		use:enhance={{
			result: async () => {
				setTimeout(() => {
					loading = false;
					window.location.reload();
				}, 200);
			},
			pending: async () => {
				loading = true;
			},
			final: async () => {
				loading = false;
			}
		}}
		method="post"
		class=" py-4"
	>
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Configurations</div>
			{#if $session.isAdmin}
				<button
					type="submit"
					class:bg-green-600={!loading}
					class:hover:bg-green-500={!loading}
					disabled={loading}>{loading ? 'Saving...' : 'Save'}</button
				>
			{/if}
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="destination">Destination</label>
				<div class="col-span-2">
					{#if database.destinationDockerId}
						<a
							href={$session.isAdmin
								? `/databases/${id}/configuration/destination?from=/databases/${id}`
								: ''}
							class="no-underline"
							><span class="arrow-right-applications">></span><input
								value={database.destinationDocker.name}
								id="destination"
								disabled
								class="bg-transparent hover:bg-coolgray-500 cursor-pointer"
							/></a
						>
					{/if}
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="name">Name</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						name="name"
						id="name"
						value={database.name}
						required
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="domain">Domain</label>
				<div class="col-span-2">
					<input
						readonly={!$session.isAdmin}
						name="domain"
						id="domain"
						pattern="^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
						placeholder="eg: {database.type}.coollabs.io"
						value={database.domain}
						required
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="port">Port</label>
				<div class="col-span-2">
					<input
						readonly
						name="port"
						id="port"
						value={database.port}
					/>
				</div>
			</div>
		</div>

		<div class="grid grid-flow-row gap-2 px-10">
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="version">Version</label>
				<div class="col-span-2 ">
					<select name="version" id="version" bind:value={database.version}>
						<option value="Select a version" disabled selected>Select a version</option>
						<option value="8.0.27">8.0.27</option>
						<option value="5.7.36">5.7.36</option>
					</select>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="defaultDatabase">Default Database Name</label>
				<div class="col-span-2 ">
					<input
						placeholder="generate automatically"
						name="defaultDatabase"
						id="defaultDatabase"
						value={database.defaultDatabase}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="dbUser">User</label>
				<div class="col-span-2 ">
					<input
						placeholder="generate automatically"
						name="dbUser"
						id="dbUser"
						value={database.dbUser}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="dbUserPassword">Password</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						placeholder="generate automatically"
						name="dbUserPassword"
						type="password"
						id="dbUserPassword"
						value={database.dbUserPassword}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center">
				<label for="rootUser">Root User</label>
				<div class="col-span-2 ">
					<input
						placeholder="generate automatically"
						name="rootUser"
						id="rootUser"
						value={database.rootUser}
					/>
				</div>
			</div>
			<div class="grid grid-cols-3 items-center pb-8">
				<label for="rootUserPassword">Root User's Password</label>
				<div class="col-span-2 ">
					<input
						readonly={!$session.isAdmin}
						placeholder="generate automatically"
						type="password"
						name="rootUserPassword"
						id="rootUserPassword"
						value={database.rootUserPassword}
					/>
				</div>
			</div>
		</div>
	</form>
	{#if database.url}
		<div class="font-bold flex space-x-1 pb-5">
			<div class="text-xl tracking-tight mr-4">Features</div>
		</div>
		<div class="px-4 sm:px-6 pb-10">
			<ul class="mt-2 divide-y divide-warmGray-800">
				<Setting
					bind:setting={isPublic}
					on:click={() => changeSettings('isPublic')}
					title="Set it public"
					description="Your database will be reachable over the internet. <br>Take security seriously in this case!"
				/>
			</ul>
		</div>
	{/if}
</div>
