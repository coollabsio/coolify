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
	export let certificates: any;
	import { del, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import Beta from '$lib/components/Beta.svelte';

	let loading = {
		save: false
	};
	let isModalActive = false;
	let cert: any = null;
	let key: any = null;

	async function handleSubmit() {
		try {
			const formData = new FormData();
			formData.append('cert', cert[0]);
			formData.append('key', key[0]);
			await post('/settings/upload', formData);
			return window.location.reload();
		} catch (error) {
			errorNotification(error);
			return false;
		}
	}
	async function deleteCertificate(id: string) {
		const sure = confirm('Are you sure you would like to delete this SSL Certificate?');
		if (sure) {
			try {
				if (!id) return;
				await del(`/settings/certificate`, { id });
				return window.location.reload();
			} catch (error) {
				errorNotification(error);
				return false;
			}
		}
	}
</script>

<div class="mx-auto w-full">
	<div class="flex border-b border-coolgray-500 mb-6">
		<div class="title font-bold pb-3 pr-4">SSL Certificates <Beta /></div>
		<label for="my-modal" class="btn btn-sm btn-primary" on:click={() => (isModalActive = true)}
			>Add SSL Certificate</label
		>
	</div>
	{#if certificates.length > 0}
		<table class="table w-full">
			<thead>
				<tr>
					<th>Common Name</th>
					<th>CreatedAt</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				{#each certificates as cert}
					<tr>
						<td>{cert.commonName}</td>
						<td>{cert.createdAt}</td>
						<td
							><button on:click={() => deleteCertificate(cert.id)} class="btn btn-sm btn-error"
								>Delete</button
							></td
						>
					</tr>
				{/each}
			</tbody>
		</table>
	{:else}
		<div class="text-sm">No SSL Certificate found</div>
	{/if}
</div>

{#if isModalActive}
	<input type="checkbox" id="my-modal" class="modal-toggle" />
	<div class="modal modal-bottom sm:modal-middle ">
		<div class="modal-box rounded bg-coolgray-300 max-w-2xl">
			<h3 class="font-bold text-lg">Add a new SSL Certificate</h3>
			<p class="py-4">
				SSL Certificates are used to secure your domain and allow you to use HTTPS. <br /><br />Once
				you uploaded your certificate, Coolify will automatically configure it for you in the
				background.
			</p>
			<div class="modal-action">
				<form on:submit|preventDefault={handleSubmit} class="w-full">
					<div class="flex flex-col justify-center">
						<label for="cert">Certificate</label>
						<div class="flex-1" />
						<input
							class="w-full bg-coolgray-100"
							id="cert"
							type="file"
							required
							name="cert"
							bind:files={cert}
						/>
						<label for="key" class="pt-10">Private Key</label>
						<input
							class="w-full bg-coolgray-100"
							id="key"
							type="file"
							required
							name="key"
							bind:files={key}
						/>
					</div>
					<label for="my-modal">
						<button type="submit" class="btn btn-sm btn-primary mt-4">Upload</button></label
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
