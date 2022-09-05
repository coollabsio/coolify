<script lang="ts">
	import { browser } from '$app/env';
	import Cookies from 'js-cookie';
	import { goto } from '$app/navigation';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { appSession, loginEmail } from '$lib/store';
	import { t } from '$lib/translations';
	import { onMount } from 'svelte';
	let loading = false;
	let emailEl: HTMLInputElement;
	let email: string, password: string;

	onMount(async () => {
		if ($appSession.userId) {
			return await goto('/');
		}
		emailEl.focus();
	});
	async function handleSubmit() {
		loading = true;
		try {
			const { token, payload } = await post(`/login`, {
				email: email.toLowerCase(),
				password,
				isLogin: true
			});
			Cookies.set('token', token, {
				path: '/'
			});
			$appSession.teamId = payload.teamId;
			$appSession.userId = payload.userId;
			$appSession.permission = payload.permission;
			$appSession.isAdmin = payload.isAdmin;
			return await goto('/');
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
	async function gotoRegister() {
		$loginEmail = email?.toLowerCase();
		return await goto('/register');
	}
</script>

<svelt:head>
	<title>{$t('login.login')}</title>
</svelt:head>

<div class="flex h-screen flex-col items-center justify-center">
	<div class="flex justify-center px-4">
		<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-2">
			{#if $appSession.whiteLabeledDetails.icon}
				<img
					class="w-32 mx-auto pb-8"
					src={$appSession.whiteLabeledDetails.icon}
					alt="Icon for white labeled version of Coolify"
				/>
			{:else}
				<div class="text-6xl font-bold border-gradient w-48 mx-auto border-b-4 mb-8">Coolify</div>
			{/if}
			<input
				type="email"
				name="email"
				placeholder={$t('forms.email')}
				autocomplete="off"
				required
				bind:this={emailEl}
				bind:value={email}
				class="w-full"
			/>
			<input
				type="password"
				name="password"
				placeholder={$t('forms.password')}
				bind:value={password}
				required
				class="w-full"
			/>

			<div class="flex flex-col space-y-2 items-center justify-center pt-8">
				<button
					type="submit"
					disabled={loading}
					class="btn btn-sm w-full hover:bg-coollabs-100"
					class:loading
					class:bg-coollabs={!loading}
					>{loading ? $t('login.authenticating') : $t('login.login')}</button
				>
				{#if $appSession.registrationEnabled}
					<button on:click|preventDefault={gotoRegister} class="btn w-full"
						>{$t('register.register')}</button
					>
				{:else}
					<div class="text-stone-600 text-xs">
						Registration is disabled. Please ask an admin to activate it.
					</div>
				{/if}
			</div>
		</form>
	</div>
	{#if browser && window.location.host === 'demo.coolify.io'}
		<div class="pt-5 font-bold">
			Registration is <span class="text-pink-500">open</span>, just fill in an email (does not need
			to be live email address for the demo instance) and a password.
		</div>
		<div class="pt-5 font-bold">
			All users gets an <span class="text-pink-500">own namespace</span>, so you won't be able to
			access other users data.
		</div>
	{/if}
</div>
