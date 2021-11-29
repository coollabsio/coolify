<script lang="ts">
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';
	import { enhance, errorNotification } from '$lib/form';
	import { onMount } from 'svelte';
	let loading = false;
	let passwordEl;
	let emailEl;
	if (browser && $session.token) {
		goto('/');
	}
	onMount(() => {
		emailEl.focus();
	});
</script>

<div class="h-screen flex flex-col justify-center items-center">
	{#if $session.token}
		<div class="flex justify-center px-4 text-xl font-bold">Already logged in...</div>
	{:else}
		<div class="flex justify-center px-4">
			<form
				action="/login.json"
				method="post"
				use:enhance={{
					result: async () => {
						window.location.replace('/');
					},
					pending: async () => {
						loading = true;
					},
					error: async (res) => {
						const { message } = await res.json();
						errorNotification(message);
						passwordEl.value = '';
						loading = false;
					}
				}}
				class="flex flex-col py-4 space-y-2"
			>
				<div class="text-6xl font-bold mb-4 border-gradient w-48 mx-auto border-b-4">Coolify</div>
				<input type="text" name="email" placeholder="Email" required bind:this={emailEl} />
				<input type="password" name="password" placeholder="Password" bind:this={passwordEl} required />

				<div class="flex space-x-2 h-8 items-center justify-center pt-14">
					<button
						type="submit"
						disabled={loading}
						class="hover:opacity-90 text-white"
						class:bg-transparent={loading}
						class:text-warmGray-600={loading}
						class:bg-coollabs={!loading}>{loading ? 'Authenticating...' : 'Login'}</button
					>
				</div>
			</form>
		</div>
	{/if}
</div>
