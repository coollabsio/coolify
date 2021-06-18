<script>
	import { fade } from 'svelte/transition';

	import { toast } from '@zerodevx/svelte-toast';
	import { newService, newWordpressService } from '$store';
	import { page, session } from '$app/stores';
	import { request } from '$lib/request';
	import { goto } from '$app/navigation';
	import Loading from '$components/Loading.svelte';
	import TooltipInfo from '$components/TooltipInfo.svelte';
	import { browser } from '$app/env';

	$: deployablePlausible =
		$newService.baseURL === '' ||
		$newService.baseURL === null ||
		$newService.email === '' ||
		$newService.email === null ||
		$newService.userName === '' ||
		$newService.userName === null ||
		$newService.userPassword === '' ||
		$newService.userPassword === null ||
		$newService.userPassword.length <= 6 ||
		$newService.userPassword !== $newService.userPasswordAgain;

	$: deployableNocoDB = $newService.baseURL === '' || $newService.baseURL === null;
	$: deployableCodeServer = $newService.baseURL === '' || $newService.baseURL === null;
	$: deployableMinIO = $newService.baseURL === '' || $newService.baseURL === null;
	$: deployableWordpress = false
	let loading = false;
	async function deployPlausible() {
		try {
			loading = true;
			const payload = $newService;
			delete payload.userPasswordAgain;
			await request(`/api/v1/services/deploy/${$page.params.type}`, $session, {
				body: payload
			});
			if (browser) {
				toast.push(
					'Service deployment queued.<br><br><br>It could take 2-5 minutes to be ready, be patient and grab a coffee/tea!',
					{ duration: 4000 }
				);
				goto(`/dashboard/services`, { replaceState: true });
			}
		} catch (error) {
			console.log(error);
			browser && toast.push('Oops something went wrong. See console.log.');
		} finally {
			loading = false;
		}
	}
	async function deployNocodb() {
		try {
			loading = true;
			await request(`/api/v1/services/deploy/${$page.params.type}`, $session, {
				body: {
					baseURL: $newService.baseURL
				}
			});
			if (browser) {
				toast.push(
					'Service deployment queued.<br><br><br>It could take 2-5 minutes to be ready, be patient and grab a coffee/tea!',
					{ duration: 4000 }
				);
				goto(`/dashboard/services`, { replaceState: true });
			}
		} catch (error) {
			console.log(error);
			browser && toast.push('Oops something went wrong. See console.log.');
		} finally {
			loading = false;
		}
	}
	async function deployCodeServer() {
		try {
			loading = true;
			await request(`/api/v1/services/deploy/${$page.params.type}`, $session, {
				body: {
					baseURL: $newService.baseURL
				}
			});
			if (browser) {
				toast.push(
					'Service deployment queued.<br><br><br>It could take 2-5 minutes to be ready, be patient and grab a coffee/tea!',
					{ duration: 4000 }
				);
				goto(`/dashboard/services`, { replaceState: true });
			}
		} catch (error) {
			console.log(error);
			browser && toast.push('Oops something went wrong. See console.log.');
		} finally {
			loading = false;
		}
	}
	async function deployMinIO() {
		try {
			loading = true;
			await request(`/api/v1/services/deploy/${$page.params.type}`, $session, {
				body: {
					baseURL: $newService.baseURL
				}
			});
			if (browser) {
				toast.push(
					'Service deployment queued.<br><br><br>It could take 2-5 minutes to be ready, be patient and grab a coffee/tea!',
					{ duration: 4000 }
				);
				goto(`/dashboard/services`, { replaceState: true });
			}
		} catch (error) {
			console.log(error);
			browser && toast.push('Oops something went wrong. See console.log.');
		} finally {
			loading = false;
		}
	}
	async function deployWordpress() {
		try {
			loading = true;
			await request(`/api/v1/services/deploy/${$page.params.type}`, $session, {
				body: {
					...$newWordpressService
				}
			});
			if (browser) {
				toast.push(
					'Service deployment queued.<br><br><br>It could take 2-5 minutes to be ready, be patient and grab a coffee/tea!',
					{ duration: 4000 }
				);
				goto(`/dashboard/services`, { replaceState: true });
			}
		} catch (error) {
			console.log(error);
			browser && toast.push('Oops something went wrong. See console.log.');
		} finally {
			loading = false;
		}
	}

</script>

<div class="min-h-full text-white">
	<div class="py-5 text-left px-6 text-3xl tracking-tight font-bold">
		Deploy new
		{#if $page.params.type === 'plausible'}
			<span class="text-blue-500 px-2 capitalize">Plausible Analytics</span>
		{:else if $page.params.type === 'nocodb'}
			<span class="text-blue-500 px-2 capitalize">NocoDB</span>
		{:else if $page.params.type === 'code-server'}
			<span class="text-blue-500 px-2 capitalize">VSCode Server</span>
		{:else if $page.params.type === 'minio'}
			<span class="text-blue-500 px-2 capitalize">MinIO</span>
		{:else if $page.params.type === 'wordpress'}
			<span class="text-blue-500 px-2 capitalize">Wordpress</span>
		{/if}
	</div>
</div>
{#if loading}
	<Loading />
{:else if $page.params.type === 'plausible'}
	<div class="space-y-2 max-w-4xl mx-auto px-6 flex-col text-center" in:fade={{ duration: 100 }}>
		<div class="grid grid-flow-row">
			<label for="Domain"
				>Domain <TooltipInfo
					position="right"
					label={`You could reach your Plausible Analytics instance here.`}
				/></label
			>
			<input
				id="Domain"
				class:border-red-500={$newService.baseURL == null || $newService.baseURL == ''}
				bind:value={$newService.baseURL}
				placeholder="analytics.coollabs.io"
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="Email">Email</label>
			<input
				id="Email"
				class:border-red-500={$newService.email == null || $newService.email == ''}
				bind:value={$newService.email}
				placeholder="hi@coollabs.io"
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="Username">Username </label>
			<input
				id="Username"
				class:border-red-500={$newService.userName == null || $newService.userName == ''}
				bind:value={$newService.userName}
				placeholder="admin"
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="Password"
				>Password <TooltipInfo position="right" label={`Must be at least 7 characters.`} /></label
			>
			<input
				id="Password"
				type="password"
				class:border-red-500={$newService.userPassword == null ||
					$newService.userPassword == '' ||
					$newService.userPassword.length <= 6}
				bind:value={$newService.userPassword}
			/>
		</div>
		<div class="grid grid-flow-row pb-5">
			<label for="PasswordAgain">Password again </label>
			<input
				id="PasswordAgain"
				type="password"
				class:placeholder-red-500={$newService.userPassword !== $newService.userPasswordAgain}
				class:border-red-500={$newService.userPassword !== $newService.userPasswordAgain}
				bind:value={$newService.userPasswordAgain}
			/>
		</div>
		<button
			disabled={deployablePlausible}
			class:cursor-not-allowed={deployablePlausible}
			class:bg-blue-500={!deployablePlausible}
			class:hover:bg-blue-400={!deployablePlausible}
			class:hover:bg-transparent={deployablePlausible}
			class:text-warmGray-700={deployablePlausible}
			class:text-white={!deployablePlausible}
			class="button p-2"
			on:click={deployPlausible}
		>
			Deploy
		</button>
	</div>
{:else if $page.params.type === 'nocodb'}
	<div class="space-y-2 max-w-xl mx-auto px-6 flex-col text-center" in:fade={{ duration: 100 }}>
		<div class="grid grid-flow-row pb-5">
			<label for="Domain"
				>Domain <TooltipInfo
					position="right"
					label={`You could reach your NocoDB instance here.`}
				/></label
			>
			<input
				id="Domain"
				class:border-red-500={$newService.baseURL == null || $newService.baseURL == ''}
				bind:value={$newService.baseURL}
				placeholder="nocodb.coollabs.io"
			/>
		</div>

		<button
			disabled={deployableNocoDB}
			class:cursor-not-allowed={deployableNocoDB}
			class:bg-blue-500={!deployableNocoDB}
			class:hover:bg-blue-400={!deployableNocoDB}
			class:hover:bg-transparent={deployableNocoDB}
			class:text-warmGray-700={deployableNocoDB}
			class:text-white={!deployableNocoDB}
			class="button p-2 w-64 bg-blue-500 hover:bg-blue-400 text-white"
			on:click={deployNocodb}
		>
			Deploy
		</button>
	</div>
{:else if $page.params.type === 'code-server'}
	<div class="space-y-2 max-w-xl mx-auto px-6 flex-col text-center" in:fade={{ duration: 100 }}>
		<div class="grid grid-flow-row pb-5">
			<label for="Domain"
				>Domain <TooltipInfo
					position="right"
					label={`You could reach your Code Server instance here.`}
				/></label
			>
			<input
				id="Domain"
				class:border-red-500={$newService.baseURL == null || $newService.baseURL == ''}
				bind:value={$newService.baseURL}
				placeholder="code.coollabs.io"
			/>
		</div>

		<button
			disabled={deployableCodeServer}
			class:cursor-not-allowed={deployableCodeServer}
			class:bg-blue-500={!deployableCodeServer}
			class:hover:bg-blue-400={!deployableCodeServer}
			class:hover:bg-transparent={deployableCodeServer}
			class:text-warmGray-700={deployableCodeServer}
			class:text-white={!deployableCodeServer}
			class="button p-2 w-64 bg-blue-500 hover:bg-blue-400 text-white"
			on:click={deployCodeServer}
		>
			Deploy
		</button>
	</div>
{:else if $page.params.type === 'minio'}
	<div class="space-y-2 max-w-xl mx-auto px-6 flex-col text-center" in:fade={{ duration: 100 }}>
		<div class="grid grid-flow-row pb-5">
			<label for="Domain"
				>Domain <TooltipInfo
					position="right"
					label={`You could reach your MinIO instance here.`}
				/></label
			>
			<input
				id="Domain"
				class:border-red-500={$newService.baseURL == null || $newService.baseURL == ''}
				bind:value={$newService.baseURL}
				placeholder="minio.coollabs.io"
			/>
		</div>

		<button
			disabled={deployableMinIO}
			class:cursor-not-allowed={deployableMinIO}
			class:bg-blue-500={!deployableMinIO}
			class:hover:bg-blue-400={!deployableMinIO}
			class:hover:bg-transparent={deployableMinIO}
			class:text-warmGray-700={deployableMinIO}
			class:text-white={!deployableMinIO}
			class="button p-2 w-64 bg-blue-500 hover:bg-blue-400 text-white"
			on:click={deployMinIO}
		>
			Deploy
		</button>
	</div>
{:else if $page.params.type === 'wordpress'}
	<div class="space-y-2 max-w-xl mx-auto px-6 flex-col text-center" in:fade={{ duration: 100 }}>
		<div class="grid grid-flow-row pb-5">
			<label for="Domain"
				>Domain <TooltipInfo
					position="right"
					label={`You could reach your Wordpress instance here.`}
				/></label
			>
			<input
				id="Domain"
				class:border-red-500={$newWordpressService.baseURL == null || $newWordpressService.baseURL == ''}
				bind:value={$newWordpressService.baseURL}
				placeholder="wordpress.coollabs.io"
			/>
		</div>
		<div class="">
			<div class="px-4 sm:px-6">
				<ul class="divide-y divide-warmGray-800">
					<li class="py-4 flex items-center justify-between text-left">
						<div class="flex flex-col">
							<p class="text-base font-bold text-warmGray-100">Use remote MySQL database?</p>
							<p class="text-sm font-medium text-warmGray-400">
								If not, Coolify will create a local database for you.
							</p>
						</div>
						<button
							type="button"
							on:click={() => ($newWordpressService.remoteDB = !$newWordpressService.remoteDB)}
							aria-pressed="true"
							class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200"
							class:bg-green-600={$newWordpressService.remoteDB}
							class:bg-warmGray-700={!$newWordpressService.remoteDB}
						>
							<span class="sr-only">Use setting</span>
							<span
								class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
								class:translate-x-5={$newWordpressService.remoteDB}
								class:translate-x-0={!$newWordpressService.remoteDB}
							>
								<span
									class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
									class:opacity-0={$newWordpressService.remoteDB}
									class:opacity-100={!$newWordpressService.remoteDB}
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
									class:opacity-100={$newWordpressService.remoteDB}
									class:opacity-0={!$newWordpressService.remoteDB}
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
			{#if $newWordpressService.remoteDB}
				<div class="grid grid-flow-row pb-5">
					<label for="database.host"
						>DB Host <TooltipInfo
							position="right"
							label={`IP address of a remote Mysql instance.`}
						/></label
					>
					<input
						id="database.host"
						class:border-red-500={$newWordpressService.database.host == null ||
							$newWordpressService.database.host == ''}
						bind:value={$newWordpressService.database.host}
						placeholder="10.10.10.10:3306"
					/>
				</div>
				<div class="grid grid-flow-row pb-5">
					<label for="database.user"
						>DB User <TooltipInfo position="right" label={`Database user.`} /></label
					>
					<input
						id="database.user"
						class:border-red-500={$newWordpressService.database.user == null ||
							$newWordpressService.database.user == ''}
						bind:value={$newWordpressService.database.user}
						placeholder="wordpressuser"
					/>
				</div>
				<div class="grid grid-flow-row pb-5">
					<label for="database.password"
						>DB Password <TooltipInfo
							position="right"
							label={`Database password for the database user.`}
						/></label
					>
					<input
						id="database.password"
						class:border-red-500={$newWordpressService.database.password == null ||
							$newWordpressService.database.password == ''}
						bind:value={$newWordpressService.database.password}
						placeholder="supersecretuserpasswordforwordpress"
					/>
				</div>
				<div class="grid grid-flow-row pb-5">
					<label for="database.name"
						>DB Name<TooltipInfo
							position="right"
							label={`Database name`}
						/></label
					>
					<input
						id="database.name"
						class:border-red-500={$newWordpressService.database.name == null ||
							$newWordpressService.database.name == ''}
						bind:value={$newWordpressService.database.name}
						placeholder="wordpress"
					/>
				</div>
				<div class="grid grid-flow-row pb-5">
					<label for="database.tablePrefix"
						>DB Table Prefix <TooltipInfo
							position="right"
							label={`Table prefix for wordpress`}
						/></label
					>
					<input
						id="database.tablePrefix"
						class:border-red-500={$newWordpressService.database.tablePrefix == null ||
							$newWordpressService.database.tablePrefix == ''}
						bind:value={$newWordpressService.database.tablePrefix}
						placeholder="wordpress"
					/>
				</div>
			{/if}
			<div class="grid grid-flow-row py-5">
				<label for="wordpressExtraConfiguration"
					>Wordpress Configuration Extra <TooltipInfo
						position="right"
						label={`Database password for the database user.`}
					/></label
				>
				<textarea
					class="h-32"
					id="wordpressExtraConfiguration"
					bind:value={$newWordpressService.wordpressExtraConfiguration}
					placeholder="// Example Extra Configuration
define('WP_ALLOW_MULTISITE', true );
define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);"
				/>
			</div>
		</div>

		<button
			disabled={deployableWordpress}
			class:cursor-not-allowed={deployableWordpress}
			class:bg-blue-500={!deployableWordpress}
			class:hover:bg-blue-400={!deployableWordpress}
			class:hover:bg-transparent={deployableWordpress}
			class:text-warmGray-700={deployableWordpress}
			class:text-white={!deployableWordpress}
			class="button p-2 w-64 bg-blue-500 hover:bg-blue-400 text-white"
			on:click={deployWordpress}
		>
			Deploy
		</button>
	</div>
{/if}
