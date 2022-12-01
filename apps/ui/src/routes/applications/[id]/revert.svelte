<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff, url }) => {
		try {
			const response = await get(`/applications/${params.id}/images`);
			return {
				props: {
					application: stuff.application,
					...response
				}
			};
		} catch (error) {
			return {
				status: 500,
				error: new Error(`Could not load ${url}`)
			};
		}
	};
</script>

<script lang="ts">
	export let application: any;
	export let imagesAvailables: any;
	export let runningImage: any;
	import { page } from '$app/stores';
	import { get, post } from '$lib/api';
	import { status, addToast } from '$lib/store';
	import { errorNotification } from '$lib/common';
	import Explainer from '$lib/components/Explainer.svelte';

	const { id } = $page.params;
	let remoteImage: any = null;

	async function revertToLocal(image: any) {
		const sure = confirm(`Are you sure you want to revert to ${image.tag} ?`);
		if (sure) {
			try {
				$status.application.initialLoading = true;
				$status.application.loading = true;
				const imageId = `${image.repository}:${image.tag}`;
				await post(`/applications/${id}/restart`, { imageId });
				addToast({
					type: 'success',
					message: 'Revert successful.'
				});
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.application.initialLoading = false;
				$status.application.loading = false;
			}
		}
	}
	async function revertToRemote() {
		const sure = confirm(`Are you sure you want to revert to ${remoteImage} ?`);
		if (sure) {
			try {
				$status.application.initialLoading = true;
				$status.application.loading = true;
				$status.application.restarting = true;
				await post(`/applications/${id}/restart`, { imageId: remoteImage });
				addToast({
					type: 'success',
					message: 'Revert successful.'
				});
			} catch (error) {
				return errorNotification(error);
			} finally {
				$status.application.initialLoading = false;
				$status.application.loading = false;
				$status.application.restarting = false;
			}
		}
	}
</script>

<div class="w-full">
	<div class="mx-auto w-full">
		<div class="flex flex-row border-b border-coolgray-500 mb-6  space-x-2">
			<div class="title font-bold pb-3">
				Revert <Explainer
					position="dropdown-bottom"
					explanation="You can revert application to a previously built image. Currently only locally stored images
				supported."
				/>
			</div>
		</div>
		<div class="pb-4 text-xs">
			If you do not want the next commit to overwrite the reverted application, temporary disable <span
				class="text-yellow-400 font-bold">Automatic Deployment</span
			>
			feature <a href={`/applications/${id}/features`}>here</a>.
		</div>
		{#if imagesAvailables.length > 0}
			<div class="text-xl font-bold pb-3">Local Images</div>
			<div
				class="px-4 lg:pb-10 pb-6 flex flex-wrap items-center justify-center lg:justify-start gap-8"
			>
				{#each imagesAvailables as image}
					<div class="gap-2 py-4 m-2">
						<div class="flex flex-col justify-center items-center">
							<div class="text-xl font-bold">
								{image.tag}
							</div>
							<div>
								<a
									class="flex no-underline text-xs my-4"
									href="{application.gitSource.htmlUrl}/{application.repository}/commit/{image.tag}"
									target="_blank noreferrer"
								>
									<button class="btn btn-sm">
										Check Commit
										<svg
											xmlns="http://www.w3.org/2000/svg"
											fill="currentColor"
											viewBox="0 0 24 24"
											stroke-width="3"
											stroke="currentColor"
											class="w-3 h-3 text-white ml-2"
										>
											<path
												stroke-linecap="round"
												stroke-linejoin="round"
												d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"
											/>
										</svg>
									</button></a
								>
								{#if image.repository + ':' + image.tag !== runningImage}
									<button
										class="btn btn-sm btn-primary w-full"
										on:click={() => revertToLocal(image)}>Revert Now</button
									>
								{:else}
									<button
										class="btn btn-sm btn-primary w-full btn-disabled bg-transparent underline"
										>Currently Used</button
									>
								{/if}
							</div>
						</div>
					</div>
				{/each}
			</div>
		{:else}
			<div class="flex flex-col pb-10">
				<div class="text-xl font-bold">No Local images available</div>
			</div>
		{/if}
		<div class="text-xl font-bold pb-3">
			Remote Images (Docker Registry) <Explainer
				position="dropdown-bottom"
				explanation="If the image is not available or you are unauthorized to access it, you will not be able to revert to it."
			/>
		</div>
		<form on:submit|preventDefault={revertToRemote}>
			<input
				id="dockerImage"
				name="dockerImage"
				required
				placeholder="coollabsio/coolify:0.0.1"
				bind:value={remoteImage}
			/>
			<button class="btn btn-sm btn-primary" type="submit">Revert Now</button>
		</form>
	</div>
</div>
