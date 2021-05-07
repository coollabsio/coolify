<script context="module">
	export function load({ error, status }) {
		return {
			props: {
				error: `${status}: ${error.message}`
			}
		};
	}
</script>

<script>
	export let error;
</script>

<h1 class="text-xl font-bold">{error}</h1>