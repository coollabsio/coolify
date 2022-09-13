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
			return window.location.assign('/');
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

<div class="flex lg:flex-row flex-col h-screen">
	<div class="bg-neutral-focus h-screen lg:flex hidden flex-col justify-end p-20 flex-1">
		<h1 class="title lg:text-6xl mb-5 border-gradient">Coolify</h1>
		<h3 class="title">Made self-hosting simple.</h3>
	</div>
	<div class="flex flex-1 flex-col lg:max-w-2xl">
		<div class="flex flex-row p-8 items-center space-x-3">
			{#if $appSession.whiteLabeledDetails.icon}
				<div class="avatar" style="width: 40px; height: 40px">
					<img
						src={$appSession.whiteLabeledDetails.icon}
						alt="Icon for white labeled version of Coolify"
					/>
				</div>
			{:else}
				<div>
					<div class="avatar" style="width: 40px; height: 40px">
						<img src="favicon.png" alt="Coolify icon" />
					</div>
				</div>
				<div class="prose">
					<h4>Coolify</h4>
				</div>
			{/if}
		</div>
		<div
			class="w-full md:px-20 lg:px-10 xl:px-20 p-6 flex flex-col h-full justify-center items-center"
		>
			<div class="mb-5 w-full prose prose-neutral">
				<h1 class="m-0 white">Welcome back</h1>
				<h5>Please login to continue.</h5>
			</div>
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-3 w-full">
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

				<div class="flex space-y-3 flex-col pt-3">
					<button
						type="submit"
						disabled={loading}
						class="btn"
						class:loading
						class:bg-coollabs={!loading}
						>{loading ? $t('login.authenticating') : $t('login.login')}</button
					>
					{#if $appSession.isRegistrationEnabled}
						<button on:click|preventDefault={gotoRegister} class="btn btn-ghost"
							>{$t('register.register')}</button
						>
					{:else}
						<div class="text-stone-600 text-xs">
							Registration is disabled. Please ask an admin to activate it.
						</div>
					{/if}
				</div>
			</form>
			{#if browser && window.location.host === 'demo.coolify.io'}
				<div class="pt-5 font-bold">
					Registration is <span class="text-pink-500">open</span>, just fill in an email (does not
					need to be live email address for the demo instance) and a password.
				</div>
				<div class="pt-5 font-bold">
					All users gets an <span class="text-pink-500">own namespace</span>, so you won't be able
					to access other users data.
				</div>
			{/if}
		</div>
	</div>
</div>
