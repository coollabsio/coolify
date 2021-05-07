<script>
	import { browser } from '$app/env';

	import { goto } from '$app/navigation';
	import { session } from '$app/stores';

	function login() {
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
			if (newWindow.closed) {
				clearInterval(timer);
				const coolToken = new URL(newWindow.document.URL).searchParams.get('coolToken');
				const ghToken = new URL(newWindow.document.URL).searchParams.get('ghToken');
				if (ghToken) {
					$session.ghToken = ghToken;
				}
				if (coolToken) {
					$session.isLoggedIn = true;
					$session.coolToken = coolToken;
					browser && goto('/dashboard/applications');
				}
			}
		}, 100);
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
			<h2 class="text-2xl md:text-3xl font-extrabold text-white">
				An open-source, hassle-free, self-hostable<br />
				<span class="text-indigo-400">Heroku</span>
				& <span class="text-green-400">Netlify</span> alternative
			</h2>
			<div class="text-center py-10">
				{#if !$session.isLoggedIn}
					<button
						class="text-white bg-warmGray-800 hover:bg-warmGray-700 rounded p-2 px-10 font-bold"
						on:click={login}>Login with Github</button
					>
				{:else}
					<button
						class="text-white bg-warmGray-800 hover:bg-warmGray-700 rounded p-2 px-10 font-bold"
						on:click={() => goto('/dashboard/applications')}>Get Started</button
					>
				{/if}
			</div>
		</div>
	</div>
</div>
