<script>
	import { browser } from '$app/env';
	import { session } from '$app/stores';
	import Loading from '$components/Loading.svelte';
	import { request } from '$lib/request';
	import { toast } from '@zerodevx/svelte-toast';
	import { fade } from 'svelte/transition';
	let settings = {
		allowRegistration: false,
		sendErrors: true
	};

	async function loadSettings() {
		try {
			const { allowRegistration, sendErrors } = await request(`/api/v1/settings`, $session);
			settings.allowRegistration = allowRegistration;
			settings.sendErrors = sendErrors;
		} catch (error) {
			console.log(error);
		}
	}
	async function changeSettings(value) {
		try {
			settings[value] = !settings[value];
			await request(`/api/v1/settings`, $session, {
				body: {
					...settings
				}
			});
			browser && toast.push('Configuration saved.');
		} catch (error) {
			console.log(error);
		}
	}
</script>

<div class="min-h-full text-white" in:fade={{ duration: 100 }}>
	<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold flex items-center">
		<div>Settings</div>
	</div>
</div>
{#await loadSettings()}
	<Loading />
{:then}
	<div in:fade={{ duration: 100 }}>
		<div class="max-w-4xl mx-auto px-6 pb-4">
			<div>
				<div class="text-2xl font-bold border-gradient w-32 pt-4 text-white">General</div>
				<div class=" pt-4">
					<div class="px-4 sm:px-6">
						<ul class="mt-2 divide-y divide-warmGray-800">
							<li class="py-4 flex items-center justify-between">
								<div class="flex flex-col">
									<p class="text-base font-bold text-warmGray-100">Registration allowed?</p>
									<p class="text-sm font-medium text-warmGray-400">
										Allow further registrations to the application. It's turned off after the first
										registration.
									</p>
								</div>
								<button
									type="button"
									on:click={() => changeSettings('allowRegistration')}
									aria-pressed="false"
									class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200"
									class:bg-green-600={settings.allowRegistration}
									class:bg-warmGray-700={!settings.allowRegistration}
								>
									<span class="sr-only">Use setting</span>
									<span
										class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transform transition ease-in-out duration-200"
										class:translate-x-5={settings.allowRegistration}
										class:translate-x-0={!settings.allowRegistration}
									>
										<span
											class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
											class:opacity-0={settings.allowRegistration}
											class:opacity-100={!settings.allowRegistration}
											aria-hidden="true"
										>
											<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
												<path
													d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
													stroke="currentColor"
													stroke-width="2"
													stroke-linecap="round"
													stroke-linejoin="round"
												/>
											</svg>
										</span>
										<span
											class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
											aria-hidden="true"
											class:opacity-100={settings.allowRegistration}
											class:opacity-0={!settings.allowRegistration}
										>
											<svg
												class="bg-white h-3 w-3 text-green-600"
												fill="currentColor"
												viewBox="0 0 12 12"
											>
												<path
													d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
												/>
											</svg>
										</span>
									</span>
								</button>
							</li>
							<li class="py-4 flex items-center justify-between">
								<div class="flex flex-col">
									<p class="text-base font-bold text-warmGray-100">Send errors automatically?</p>
									<p class="text-sm font-medium text-warmGray-400">
										Allow to send errors automatically to developer(s) at coolLabs (<a
											href="https://twitter.com/andrasbacsai"
											target="_blank"
											class="underline text-white font-bold hover:text-blue-400">Andras Bacsai</a
										>). This will help to fix bugs quicker. 🙏
									</p>
								</div>
								<button
									type="button"
									on:click={() => changeSettings('sendErrors')}
									aria-pressed="true"
									class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200"
									class:bg-green-600={settings.sendErrors}
									class:bg-warmGray-700={!settings.sendErrors}
								>
									<span class="sr-only">Use setting</span>
									<span
										class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transform transition ease-in-out duration-200"
										class:translate-x-5={settings.sendErrors}
										class:translate-x-0={!settings.sendErrors}
									>
										<span
											class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
											class:opacity-0={settings.sendErrors}
											class:opacity-100={!settings.sendErrors}
											aria-hidden="true"
										>
											<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
												<path
													d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
													stroke="currentColor"
													stroke-width="2"
													stroke-linecap="round"
													stroke-linejoin="round"
												/>
											</svg>
										</span>
										<span
											class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
											aria-hidden="true"
											class:opacity-100={settings.sendErrors}
											class:opacity-0={!settings.sendErrors}
										>
											<svg
												class="bg-white h-3 w-3 text-green-600"
												fill="currentColor"
												viewBox="0 0 12 12"
											>
												<path
													d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
												/>
											</svg>
										</span>
									</span>
								</button>
							</li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</div>
{/await}
