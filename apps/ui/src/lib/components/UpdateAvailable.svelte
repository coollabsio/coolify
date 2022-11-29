<script lang="ts">
	import { dev } from '$app/env';
	import { get, post } from '$lib/api';
	import {
		addToast,
		appSession,
		features,
		updateLoading,
		isUpdateAvailable,
		latestVersion
	} from '$lib/store';
	import { asyncSleep, errorNotification } from '$lib/common';
	import { onMount } from 'svelte';
	import Tooltip from './Tooltip.svelte';

	let updateStatus: any = {
		found: false,
		loading: false,
		success: null
	};
	async function update() {
		updateStatus.loading = true;
		try {
			if (dev) {
				localStorage.setItem('lastVersion', $appSession.version);
				await asyncSleep(1000);
				updateStatus.loading = false;
				return window.location.reload();
			} else {
				localStorage.setItem('lastVersion', $appSession.version);
				await post(`/update`, { type: 'update', latestVersion: $latestVersion });
				addToast({
					message: 'Update completed.<br><br>Waiting for the new version to start...',
					type: 'success'
				});

				let reachable = false;
				let tries = 0;
				do {
					await asyncSleep(4000);
					try {
						await get(`/undead`);
						reachable = true;
					} catch (error) {
						reachable = false;
					}
					if (reachable) break;
					tries++;
				} while (!reachable || tries < 120);
				addToast({
					message: 'New version reachable. Reloading...',
					type: 'success'
				});
				updateStatus.loading = false;
				updateStatus.success = true;
				await asyncSleep(3000);
				return window.location.reload();
			}
		} catch (error) {
			updateStatus.success = false;
			updateStatus.loading = false;
			return errorNotification(error);
		}
	}
	onMount(async () => {
		if ($appSession.userId) {
			const overrideVersion = $features.latestVersion;
			if ($appSession.teamId === '0') {
				if ($updateLoading === true) return;
				try {
					$updateLoading = true;
					const data = await get(`/update`);
					if (overrideVersion || data?.isUpdateAvailable) {
						$latestVersion = overrideVersion || data.latestVersion;
						if (overrideVersion) {
							$isUpdateAvailable = true;
						} else {
							$isUpdateAvailable = data.isUpdateAvailable;
						}
					}
				} catch (error) {
					return errorNotification(error);
				} finally {
					$updateLoading = false;
				}
			}
		}
	});
</script>

<div class="py-0 lg:py-2">
	{#if $appSession.teamId === '0'}
		{#if $isUpdateAvailable}
			<button
				id="update"
				disabled={updateStatus.success === false}
				on:click={update}
				class="icons bg-coollabs-gradient text-white duration-75 hover:scale-105 w-full"
			>
				{#if updateStatus.loading}
					<svg
						xmlns="http://www.w3.org/2000/svg"
						class="lds-heart h-8 w-8 mx-auto"
						viewBox="0 0 24 24"
						stroke-width="1.5"
						stroke="currentColor"
						fill="none"
						stroke-linecap="round"
						stroke-linejoin="round"
					>
						<path stroke="none" d="M0 0h24v24H0z" fill="none" />
						<path
							d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572"
						/>
					</svg>
				{:else if updateStatus.success === null}
					<div class="flex items-center justify-center space-x-2">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-8 w-8"
							viewBox="0 0 24 24"
							stroke-width="1.5"
							stroke="currentColor"
							fill="none"
							stroke-linecap="round"
							stroke-linejoin="round"
						>
							<path stroke="none" d="M0 0h24v24H0z" fill="none" />
							<circle cx="12" cy="12" r="9" />
							<line x1="12" y1="8" x2="8" y2="12" />
							<line x1="12" y1="8" x2="12" y2="16" />
							<line x1="16" y1="12" x2="12" y2="8" />
						</svg>
						<span class="flex lg:hidden">Update available</span>
					</div>
				{:else if updateStatus.success}
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" class="h-8 w-8"
						><path
							fill="#DD2E44"
							d="M11.626 7.488c-.112.112-.197.247-.268.395l-.008-.008L.134 33.141l.011.011c-.208.403.14 1.223.853 1.937.713.713 1.533 1.061 1.936.853l.01.01L28.21 24.735l-.008-.009c.147-.07.282-.155.395-.269 1.562-1.562-.971-6.627-5.656-11.313-4.687-4.686-9.752-7.218-11.315-5.656z"
						/><path
							fill="#EA596E"
							d="M13 12L.416 32.506l-.282.635.011.011c-.208.403.14 1.223.853 1.937.232.232.473.408.709.557L17 17l-4-5z"
						/><path
							fill="#A0041E"
							d="M23.012 13.066c4.67 4.672 7.263 9.652 5.789 11.124-1.473 1.474-6.453-1.118-11.126-5.788-4.671-4.672-7.263-9.654-5.79-11.127 1.474-1.473 6.454 1.119 11.127 5.791z"
						/><path
							fill="#AA8DD8"
							d="M18.59 13.609c-.199.161-.459.245-.734.215-.868-.094-1.598-.396-2.109-.873-.541-.505-.808-1.183-.735-1.862.128-1.192 1.324-2.286 3.363-2.066.793.085 1.147-.17 1.159-.292.014-.121-.277-.446-1.07-.532-.868-.094-1.598-.396-2.11-.873-.541-.505-.809-1.183-.735-1.862.13-1.192 1.325-2.286 3.362-2.065.578.062.883-.057 1.012-.134.103-.063.144-.123.148-.158.012-.121-.275-.446-1.07-.532-.549-.06-.947-.552-.886-1.102.059-.549.55-.946 1.101-.886 2.037.219 2.973 1.542 2.844 2.735-.13 1.194-1.325 2.286-3.364 2.067-.578-.063-.88.057-1.01.134-.103.062-.145.123-.149.157-.013.122.276.446 1.071.532 2.037.22 2.973 1.542 2.844 2.735-.129 1.192-1.324 2.286-3.362 2.065-.578-.062-.882.058-1.012.134-.104.064-.144.124-.148.158-.013.121.276.446 1.07.532.548.06.947.553.886 1.102-.028.274-.167.511-.366.671z"
						/><path
							fill="#77B255"
							d="M30.661 22.857c1.973-.557 3.334.323 3.658 1.478.324 1.154-.378 2.615-2.35 3.17-.77.216-1.001.584-.97.701.034.118.425.312 1.193.095 1.972-.555 3.333.325 3.657 1.479.326 1.155-.378 2.614-2.351 3.17-.769.216-1.001.585-.967.702.033.117.423.311 1.192.095.53-.149 1.084.16 1.233.691.148.532-.161 1.084-.693 1.234-1.971.555-3.333-.323-3.659-1.479-.324-1.154.379-2.613 2.353-3.169.77-.217 1.001-.584.967-.702-.032-.117-.422-.312-1.19-.096-1.974.556-3.334-.322-3.659-1.479-.325-1.154.378-2.613 2.351-3.17.768-.215.999-.585.967-.701-.034-.118-.423-.312-1.192-.096-.532.15-1.083-.16-1.233-.691-.149-.53.161-1.082.693-1.232z"
						/><path
							fill="#AA8DD8"
							d="M23.001 20.16c-.294 0-.584-.129-.782-.375-.345-.432-.274-1.061.156-1.406.218-.175 5.418-4.259 12.767-3.208.547.078.927.584.849 1.131-.078.546-.58.93-1.132.848-6.493-.922-11.187 2.754-11.233 2.791-.186.148-.406.219-.625.219z"
						/><path
							fill="#77B255"
							d="M5.754 16c-.095 0-.192-.014-.288-.042-.529-.159-.829-.716-.67-1.245 1.133-3.773 2.16-9.794.898-11.364-.141-.178-.354-.353-.842-.316-.938.072-.849 2.051-.848 2.071.042.551-.372 1.031-.922 1.072-.559.034-1.031-.372-1.072-.923-.103-1.379.326-4.035 2.692-4.214 1.056-.08 1.933.287 2.552 1.057 2.371 2.951-.036 11.506-.542 13.192-.13.433-.528.712-.958.712z"
						/><circle fill="#5C913B" cx="25.5" cy="9.5" r="1.5" /><circle
							fill="#9266CC"
							cx="2"
							cy="18"
							r="2"
						/><circle fill="#5C913B" cx="32.5" cy="19.5" r="1.5" /><circle
							fill="#5C913B"
							cx="23.5"
							cy="31.5"
							r="1.5"
						/><circle fill="#FFCC4D" cx="28" cy="4" r="2" /><circle
							fill="#FFCC4D"
							cx="32.5"
							cy="8.5"
							r="1.5"
						/><circle fill="#FFCC4D" cx="29.5" cy="12.5" r="1.5" /><circle
							fill="#FFCC4D"
							cx="7.5"
							cy="23.5"
							r="1.5"
						/></svg
					>
				{:else}
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" class="h-9 w-8"
						><path
							fill="#FFCC4D"
							d="M36 18c0 9.941-8.059 18-18 18S0 27.941 0 18 8.059 0 18 0s18 8.059 18 18"
						/><path
							fill="#664500"
							d="M22 27c0 2.763-1.791 3-4 3-2.21 0-4-.237-4-3 0-2.761 1.79-6 4-6 2.209 0 4 3.239 4 6zm8-12c-.124 0-.25-.023-.371-.072-5.229-2.091-7.372-5.241-7.461-5.374-.307-.46-.183-1.081.277-1.387.459-.306 1.077-.184 1.385.274.019.027 1.93 2.785 6.541 4.629.513.206.763.787.558 1.3-.157.392-.533.63-.929.63zM6 15c-.397 0-.772-.238-.929-.629-.205-.513.044-1.095.557-1.3 4.612-1.844 6.523-4.602 6.542-4.629.308-.456.929-.577 1.387-.27.457.308.581.925.275 1.383-.089.133-2.232 3.283-7.46 5.374C6.25 14.977 6.124 15 6 15z"
						/><path fill="#5DADEC" d="M24 16h4v19l-4-.046V16zM8 35l4-.046V16H8v19z" /><path
							fill="#664500"
							d="M14.999 18c-.15 0-.303-.034-.446-.105-3.512-1.756-7.07-.018-7.105 0-.495.249-1.095.046-1.342-.447-.247-.494-.047-1.095.447-1.342.182-.09 4.498-2.197 8.895 0 .494.247.694.848.447 1.342-.176.35-.529.552-.896.552zm14 0c-.15 0-.303-.034-.446-.105-3.513-1.756-7.07-.018-7.105 0-.494.248-1.094.047-1.342-.447-.247-.494-.047-1.095.447-1.342.182-.09 4.501-2.196 8.895 0 .494.247.694.848.447 1.342-.176.35-.529.552-.896.552z"
						/><ellipse fill="#5DADEC" cx="18" cy="34" rx="18" ry="2" /><ellipse
							fill="#E75A70"
							cx="18"
							cy="27"
							rx="3"
							ry="2"
						/></svg
					>
				{/if}
			</button>
			<Tooltip triggeredBy="#update" placement="right" color="bg-coolgray-200 text-white"
				>New Version Available!</Tooltip
			>
		{/if}
	{/if}
</div>
