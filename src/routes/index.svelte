<script>
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';
	import { toast } from '@zerodevx/svelte-toast';
	import PasswordField from '$components/PasswordField.svelte';
	import { request } from '$lib/request';
	import { settings } from '$store';
	import Loading from '$components/Loading.svelte';
	let loading = false;
	let email = null;
	let password = null;
	async function login() {
		const left = screen.width / 2 - 1020 / 2;
		const top = screen.height / 2 - 618 / 2;
		const newWindow = open(
			`https://github.com/login/oauth/authorize?client_id=${
				import.meta.env.VITE_GITHUB_APP_CLIENTID
			}`,
			'Authenticate',
			'resizable=1, scrollbars=1, fullscreen=0, height=618, width=1020,top=' +
				top +
				', left=' +
				left +
				', toolbar=0, menubar=0, status=0'
		);
		const timer = setInterval(() => {
			if (newWindow?.closed) {
				clearInterval(timer);
				browser && location.reload();
			}
		}, 100);
	}
	async function loginWithEmail() {
		try {
			loading = true;
			const { message } = await request('/api/v1/login/email', $session, {
				body: {
					email,
					password
				}
			});
			toast.push(message);
			setTimeout(() => {
				browser && window.location.replace('/')
			}, 1000);
		} catch (error) {
			loading = false;
			browser && toast.push(error.error || error || 'Ooops something went wrong.');
		}
	}
</script>

<div class="flex justify-center items-center h-screen w-full bg-warmGray-900">
	<div class="max-w-7xl mx-auto px-4 sm:py-24 sm:px-6 lg:px-8">
		<div class="text-center">
			<p
				class="mt-1 pb-8 font-extrabold text-white text-5xl sm:tracking-tight lg:text-6xl text-center"
			>
				<span class="border-gradient">Coolify</span>
			</p>
			<h2 class="text-2xl md:text-3xl font-extrabold text-white py-10">
				An open-source, hassle-free, self-hostable<br />
				<span class="text-indigo-400">Heroku</span>
				& <span class="text-green-400">Netlify</span> alternative
			</h2>
			{#if loading}
				<Loading fullscreen={false} />
			{:else}
				<div class="text-center py-10 max-w-7xl">
					{$session.isLoggedIn}
					{#if !$session.isLoggedIn}
						{#if $settings.clientId}
							<button
								class="text-white bg-warmGray-800 hover:bg-warmGray-700 rounded p-2 px-10 font-bold"
								on:click={login}>Login with GitHub</button
							>
						{:else}
							<div>
								<div class="grid grid-flow-row gap-2 items-center pb-6">
									<div class="grid grid-flow-row">
										<label for="Email" class="">Email address</label>
										<input
											class="border-2"
											id="Email"
											bind:value={email}
											placeholder="hi@coollabs.io"
										/>
									</div>
									<div class="grid grid-flow-row">
										<label for="Password" class="">Password</label>
										<PasswordField bind:value={password} isEditable />
									</div>
								</div>
								<div class="space-x-4 pt-10">
									<button
										class="text-white bg-warmGray-800 hover:bg-warmGray-700 rounded p-2 px-10 font-bold"
										on:click={loginWithEmail}>Login with Email</button
									>
								</div>
							</div>
						{/if}
					{:else}
						<button
							class="text-white bg-warmGray-800 hover:bg-warmGray-700 rounded p-2 px-10 font-bold"
							on:click={() =>
								$settings.clientId ? goto('/dashboard/applications') : goto('/dashboard/services')}
							>Get Started</button
						>
					{/if}
				</div>
			{/if}
		</div>
	</div>
</div>
