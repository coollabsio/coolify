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
	export let sshKeys: any;
	import { del, post } from '$lib/api';
	import { t } from '$lib/translations';
	import { errorNotification } from '$lib/common';
	import Menu from './_Menu.svelte';

	let loading = {
		save: false
	};
	let isModalActive = false;

	let newSSHKey = {
		name: null,
		privateKey: null
	};

	async function handleSubmit() {
		try {
			await post(`/settings/sshKey`, { ...newSSHKey });
			return window.location.reload();
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
				await del(`/settings/sshKey`, { id });
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
		<div class="title font-bold pb-3 pr-4">SSH Keys</div>
		<label for="my-modal" class="btn btn-sm btn-primary" on:click={() => (isModalActive = true)}
			>Add SSH Key</label
		>
	</div>
	{#if sshKeys.length === 0}
		<div class="text-sm">No SSH keys found</div>
	{:else}
		<div class="mx-auto w-full">
			<table class="table w-full">
				<thead>
					<tr>
						<th>Name</th>
						<th>CreatedAt</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					{#each sshKeys as key}
						<tr>
							<td>{key.name}</td>
							<td>{key.createdAt}</td>
							<td
								><button on:click={() => deleteSSHKey(key.id)} class="btn btn-sm btn-error"
									>Delete</button
								></td
							>
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
			<h3 class="font-bold text-lg">Add a new SSH Key to Coolify</h3>
			<p class="py-4">
				SSH Keys can be used to authenticate & execute commands on remote servers.
				<br /><br />You can generate a new public/private key using the following command:
				<br />
				<br />
				<code class="bg-coolgray-100 p-2 rounded">ssh-keygen -t rsa -b 4096</code>
			</p>
			<div class="modal-action">
				<form on:submit|preventDefault={handleSubmit}>
					<label for="name" class="">Name</label>
					<input id="name" required bind:value={newSSHKey.name} class="w-full bg-coolgray-100" />
					<label for="privateKey" class="pt-4">Private Key</label>
					<textarea
						id="privateKey"
						placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
						required
						bind:value={newSSHKey.privateKey}
						class="w-full bg-coolgray-100"
						rows={15}
					/>
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
