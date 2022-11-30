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
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { addToast } from '$lib/store';
	let isModalActive = false;

	let newRegistry = {
		name: '',
		username: '',
		password: '',
		url: ''
	};

	async function handleSubmit() {
		try {
			await post(`/settings/registry/new`, newRegistry);
			return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function setRegistry(registry: any) {
		try {
			await post(`/settings/registry`, registry);
			return addToast({
				message: 'Registry updated successfully.',
				type: 'success'
			});
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function deleteDockerRegistry(id: string) {
		const sure = confirm(
			'Are you sure you would like to delete this Docker Registry? All dependent resources will be affected and fails to redeploy.'
		);
		if (sure) {
			try {
				if (!id) return;
				await del(`/settings/registry`, { id });
				return window.location.reload();
			} catch (error) {
				errorNotification(error);
				return false;
			}
		}
	}
	async function addRegistry(type: string) {
		switch (type) {
			case 'dockerhub':
				newRegistry = {
					name: 'Docker Hub',
					username: '',
					password: '',
					url: 'https://index.docker.io/v1/'
				};
				await handleSubmit();
				break;
			case 'gcrio':
				newRegistry = {
					name: 'Google Container Registry',
					username: '',
					password: '',
					url: 'https://gcr.io'
				};
				await handleSubmit();
				break;
			case 'github':
				newRegistry = {
					name: 'GitHub Container Registry',
					username: '',
					password: '',
					url: 'https://ghcr.io'
				};
				await handleSubmit();
				break;
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
	<div class="flex items-center pb-4 gap-2">
		<div class="text-xs">Quick Action</div>
		<button class="btn btn-sm text-xs" on:click={() => addRegistry('dockerhub')}>DockerHub</button>
		<button class="btn btn-sm text-xs" on:click={() => addRegistry('gcrio')}
			>Google Container Registry (gcr.io)</button
		>
		<button class="btn btn-sm text-xs" on:click={() => addRegistry('github')}
			>GitHub Container Registry (ghcr.io)</button
		>
	</div>
	{#if registries.length > 0}
	<div class="mx-auto w-full">
		<table class="table w-full">
			<thead>
				<tr>
					<th>Name</th>
					<th>Username</th>
					<th>Password</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				{#each registries as registry}
					<tr>
						<td
							>{registry.name}
							<div class="text-xs">{registry.url}</div></td
						>
						<td>
							<CopyPasswordField
								name="username"
								id="Username"
								bind:value={registry.username}
								placeholder="Username"
							/></td
						>
						<td
							><CopyPasswordField
								isPasswordField={true}
								name="Password"
								id="Password"
								bind:value={registry.password}
								placeholder="Password"
							/></td
						>

						<td>
							<button on:click={() => setRegistry(registry)} class="btn btn-sm btn-primary"
								>Set</button
							>
							{#if registry.id !== '0'}
								<button
									on:click={() => deleteDockerRegistry(registry.id)}
									class="btn btn-sm btn-error">Delete</button
								>
							{/if}
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
	{/if}
</div>

{#if isModalActive}
	<input type="checkbox" id="my-modal" class="modal-toggle" />
	<div class="modal modal-bottom sm:modal-middle">
		<div class="modal-box rounded bg-coolgray-300">
			<h3 class="font-bold text-lg">Add a Docker Registry to Coolify</h3>
			<div>
				<form on:submit|preventDefault={handleSubmit}>
					<label for="name" class="label">
						<span class="label-text">Name</span>
					</label>
					<CopyPasswordField
						id="name"
						name="name"
						bind:value={newRegistry.name}
						placeholder="Docker Registry Name"
						required
					/>
					<label for="url" class="label">
						<span class="label-text">URL</span>
					</label>
					<CopyPasswordField
						id="url"
						name="url"
						bind:value={newRegistry.url}
						placeholder="Docker Registry URL"
						required
					/>
					<label for="Username" class="label">
						<span class="label-text">Username</span>
					</label>
					<CopyPasswordField
						id="Username"
						name="username"
						bind:value={newRegistry.username}
						placeholder="Username"
					/>
					<label for="Password" class="label">
						<span class="label-text">Password</span>
					</label>
					<CopyPasswordField
						isPasswordField={true}
						id="Password"
						name="password"
						bind:value={newRegistry.password}
						placeholder="Password"
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
