<script>
	import { page } from '$app/stores';
</script>

<div class="mx-auto flex h-screen flex-col items-center justify-center px-4">
	<div class="text-3xl font-bold pb-4">Ooops, are you lost?</div>
	<a href="/" class="btn btn-sm bg-coollabs">Go back</a>
	{#if $page.error.message !== 'Not Found'}
		<div class="py-10 text-xs font-bold">
			<pre class="w-full whitespace-pre-wrap break-words text-left text-xs tracking-tighter">{$page
					.error.message}</pre>
		</div>
	{/if}
</div>
