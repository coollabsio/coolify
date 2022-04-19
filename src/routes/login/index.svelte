<script lang="ts">
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { onMount } from 'svelte';
	let loading = false;
	let emailEl;
	let email, password;

	if (browser && $session.userId) {
		goto('/');
	}
	onMount(() => {
		emailEl.focus();
	});
	async function handleSubmit() {
		loading = true;
		try {
			const { teamId } = await post(`/login.json`, {
				email: email.toLowerCase(),
				password,
				isLogin: true
			});
			if (teamId === '0') {
				window.location.replace('/settings');
			} else {
				window.location.replace('/');
			}
			return;
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="flex h-screen flex-col items-center justify-center">
	{#if $session.userId}
		<div class="flex justify-center px-4 text-xl font-bold">Already logged in...</div>
	{:else}
		<div class="flex justify-center px-4">
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-2">
				{#if $session.whiteLabelDetails.icon}
					<img
						class="w-32 mx-auto pb-8"
						src={$session.whiteLabelDetails.icon}
						alt="Icon for white labeled version of Coolify"
					/>
				{:else}
					<div class="text-6xl font-bold border-gradient w-48 mx-auto border-b-4 mb-8">Coolify</div>
				{/if}
				<input
					type="email"
					name="email"
					placeholder="Email"
					autocomplete="off"
					required
					bind:this={emailEl}
					bind:value={email}
				/>
				<input
					type="password"
					name="password"
					placeholder="Password"
					bind:value={password}
					required
				/>

				<div class="flex space-x-2 h-8 items-center justify-center pt-8">
					<button
						type="submit"
						disabled={loading}
						class="hover:opacity-90 text-white"
						class:bg-transparent={loading}
						class:text-stone-600={loading}
						class:bg-coollabs={!loading}>{loading ? 'Authenticating...' : 'Login'}</button
					>

					<button
						on:click|preventDefault={() => goto('/register')}
						class="bg-transparent hover:bg-coolgray-300	text-white ">Register</button
					>
				</div>
			</form>
		</div>
		{#if browser && window.location.host === 'demo.coolify.io'}
			<div class="pt-5 font-bold">
				Registration is <span class="text-pink-500">open</span>, just fill in an email (does not
				need to be live email address for the demo instance) and a password.
			</div>
			<div class="pt-5 font-bold">
				All users gets an <span class="text-pink-500">own namespace</span>, so you won't be able to
				access other users data.
			</div>
		{/if}
	{/if}
</div>
