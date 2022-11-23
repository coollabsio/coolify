<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
		try {
			return {
				props: {
					...stuff
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let registries: any;
	import { del, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	const publicRegistries = registries.public;
	const privateRegistries = registries.private;

	let isModalActive = false;

	let newRegistry = {
		name: null,
		username: null,
		password: null,
		url: null,
		isSystemWide: false
	};

	async function handleSubmit() {
		try {
			console.log(newRegistry);
			// await post(`/settings/sshKey`, { ...newSSHKey });
			// return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function deleteSSHKey(id: string) {
		const sure = confirm('Are you sure you would like to delete this SSH key?');
		if (sure) {
			try {
				if (!id) return;
				// await del(`/settings/sshKey`, { id });
				return window.location.reload();
			} catch (error) {
				errorNotification(error);
				return false;
			}
		}
	}
</script>

<div class="w-full">
	<div class="flex border-b border-coolgray-500 mb-6">
		<div class="title font-bold pb-3 pr-4">Docker Registries</div>
		<!-- svelte-ignore a11y-click-events-have-key-events -->
		<label for="my-modal" class="btn btn-sm btn-primary" on:click={() => (isModalActive = true)}
			>Add Docker Registry</label
		>
	</div>

	<div class="mx-auto w-full">
		<table class="table w-full">
			<thead>
				<tr>
					<th>Name</th>
					<th>Public</th>
					<th>Username</th>
					<th>Password</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				{#each publicRegistries as registry}
					<tr>
						<td>{registry.name}</td>
						<td>{(registry.isSystemWide && 'Yes') || 'No'}</td>
						<td>{registry.username ?? 'N/A'}</td>
						<td>{registry.password ?? 'N/A'}</td>

						<td>
							{#if !registry.isSystemWide}
								<button on:click={() => deleteSSHKey(registry.id)} class="btn btn-sm btn-error"
									>Delete</button
								>
							{/if}
						</td>
					</tr>
				{/each}
				{#each privateRegistries as registry}
				<tr>
					<td>{registry.name}</td>
					<td>{(registry.isSystemWide && 'Yes') || 'No'}</td>
					<td>{registry.username ?? 'N/A'}</td>
					<td>{registry.password ?? 'N/A'}</td>

					<td>
						{#if !registry.isSystemWide}
							<button on:click={() => deleteSSHKey(registry.id)} class="btn btn-sm btn-error"
								>Delete</button
							>
						{/if}
					</td>
				</tr>
			{/each}
			</tbody>
		</table>
	</div>
</div>

{#if isModalActive}
	<input type="checkbox" id="my-modal" class="modal-toggle" />
	<div class="modal modal-bottom sm:modal-middle">
		<div class="modal-box rounded bg-coolgray-300">
			<h3 class="font-bold text-lg">Add a Docker Registry to Coolify</h3>
			<div >
				<form on:submit|preventDefault={handleSubmit}>
					<label for="name" class="label">
						<span class="label-text">Name</span>
					</label>
					<input
						id="name"
						type="text"
						bind:value={newRegistry.name}
						placeholder="Docker Registry Name"
						class="input input-primary w-full bg-coolgray-100"
						required
					/>
					<label for="url" class="label">
						<span class="label-text">URL</span>
					</label>
					<input
						id="url"
						type="text"
						bind:value={newRegistry.url}
						placeholder="Docker Registry URL"
						class="input input-primary w-full bg-coolgray-100"
						required
					/>
					<label for="Username" class="label">
						<span class="label-text">Username</span>
					</label>
					<input
						id="Username"
						type="text"
						bind:value={newRegistry.username}
						placeholder="Username"
						class="input input-primary w-full bg-coolgray-100"
					/>
					<label for="Password" class="label">
						<span class="label-text">Password</span>
					</label>
					<input
						id="Password"
						type="text"
						bind:value={newRegistry.password}
						placeholder="Password"
						class="input input-primary w-full  bg-coolgray-100"
					/>
					<div class="flex items-center">
						<label for="systemwide" class="label">
							<span class="label-text">System Wide</span>
						</label>
						<input
							id="systemwide"
							type="checkbox"
							bind:checked={newRegistry.isSystemWide}
							class="checkbox checkbox-primary"
						/>
					</div>
					<label for="my-modal">
						<button type="submit" class="btn btn-sm btn-primary mt-4">Save</button></label
					>
					<button
						on:click={() => (isModalActive = false)}
						type="button"
						class="btn btn-sm btn-error">Cancel</button
					>
				</form>
			</div>
		</div>
	</div>
{/if}
