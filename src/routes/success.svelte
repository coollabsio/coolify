<script>
	import { browser } from '$app/env';
	if (browser) {
		setTimeout(() => {
			window.close();
		}, 1000);
	}

</script>

<div class="flex w-full h-screen justify-center items-center">
	<div class="text-3xl font-bold">Succesfully logged in! 🎉</div>
</div>
