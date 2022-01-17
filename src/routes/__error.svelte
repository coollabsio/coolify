<script context="module">
	/** @type {import('@sveltejs/kit').ErrorLoad} */
	export function load({ error, status }) {
		console.log(error);
		return {
			props: {
				error,
				status
			}
		};
	}
</script>

<script>
	export let status;
	export let error;
</script>

<div class="flex flex-col justify-center items-center mx-auto h-screen px-4">
	<div class="text-7xl font-bold pb-10">{status}</div>
	<div class="text-3xl font-bold">Ooops you are lost! But don't be afraid!</div>
	<div class="text-xl">
		You can find your way back <a href="/" class="text-sky-400 font-bold uppercase">here</a>
	</div>
	<div class="py-10 text-xs font-bold">
		<pre
			class="text-left text-xs tracking-tighter whitespace-pre-wrap break-words w-full">{error.message} {error.stack}</pre>
	</div>
</div>
