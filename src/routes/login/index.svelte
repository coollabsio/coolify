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

	if (browser && $session.uid) {
		goto('/');
	}
	onMount(() => {
		emailEl.focus();
	});
	async function handleSubmit() {
		loading = true;
		try {
			const { teamId } = await post(`/login.json`, { email, password });
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
	{#if $session.uid}
		<div class="flex justify-center px-4 text-xl font-bold">Already logged in...</div>
	{:else}
		<div class="flex justify-center px-4">
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-2">
				<div class="text-6xl font-bold border-gradient w-48 mx-auto border-b-4">Coolify</div>
				<div class="text-xs text-center font-bold pb-10">v{$session.version}</div>
				<input
					type="email"
					name="email"
					placeholder="Email"
					autocomplete="off"
					required
					bind:this={emailEl}
					bind:value={email} />
				<input
					type="password"
					name="password"
					placeholder="Password"
					bind:value={password}
					required />

				<div class="flex space-x-2 h-8 items-center justify-center pt-14">
					<button
						type="submit"
						disabled={loading}
						class="hover:opacity-90 text-white"
						class:bg-transparent={loading}
						class:text-stone-600={loading}
						class:bg-coollabs={!loading}>{loading ? 'Authenticating...' : 'Login'}</button>
				</div>
			</form>
		</div>
	{/if}
</div>
