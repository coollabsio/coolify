<script>
	import { browser } from '$app/env';
	if (browser) {
		setTimeout(()=> {
			window.close();
		}, 2000)
		
	}
</script>

<div class="text-3xl font-bold">Succesfully logged in!</div>