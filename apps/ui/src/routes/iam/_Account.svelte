<script lang="ts">
	export let account: any;
	export let accounts: any = [];
	import { del, get, post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { addToast, appSession } from '$lib/store';
	async function resetPassword(id: any) {
		const sure = window.confirm('Are you sure you want to reset the password?');
		if (!sure) {
			return;
		}
		try {
			await post(`/iam/user/password`, { id });
			return addToast({
				message: 'Password reset successfully. Please relogin to reset it.',
				type: 'success'
			});
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function deleteAccount(id: any) {
		if (id === $appSession.userId || account.id === '0') return;
		const sure = window.confirm('Are you sure you want to delete this user?');
		if (!sure) {
			return;
		}
		try {
			await del(`/iam/user/remove`, { id });
			addToast({
				message: 'Account deleted.',
				type: 'success'
			});
			const data = await get('/iam');
			accounts = data.accounts;
		} catch (error) {
			return errorNotification(error);
		}
	}
</script>

<div class="flex flex-col lg:flex-row lg:space-y-0 space-y-2 lg:space-x-4">
	<input
		disabled
		class="input w-full text-white"
		readonly
		placeholder="email"
		value={account.email}
	/>
	<div class="flex flex-row items-center justify-center space-x-2 w-full lg:w-96">
		<div class="flex items-center justify-center">
			<button class="btn btn-sm btn-primary" on:click={() => resetPassword(account.id)}
				>Reset Password</button
			>
		</div>
		<div class="flex justify-center">
			<button
				class="btn btn-sm btn-error"
				disabled={account.id === $appSession.userId || account.id === '0'}
				on:click={() => deleteAccount(account.id)}>Delete Account</button
			>
		</div>
	</div>
</div>
