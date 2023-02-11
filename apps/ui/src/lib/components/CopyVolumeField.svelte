<script>
	import { addToast } from '$lib/store';

	export let value = "";
	let isHttps = window.location.protocol === 'https:';

	function copyToClipboard() {
		if (isHttps && navigator.clipboard) {
			navigator.clipboard.writeText(value);
			addToast({
				message: 'Copied to clipboard.',
				type: 'success'
			});
		}
	}
</script>
<style>
	.box {
		position: relative;
		border: 1px dashed #202020;
		border-radius: 5px;
		padding: 5px;
	}
	
</style>

<div class="w-full relative box">
	<p class="text-white  p-2">{value}</p>
    <div class="absolute top-0 right-0 flex justify-center items-center h-full cursor-pointer text-stone-600 mr-3">
		<div on:click={copyToClipboard}>
            
            <svg width="24px" height="24px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" class="stroke-gray-700 hover:stroke-white">
                <path fill="none" stroke-width="2" d="M9,15 L17,15 L9,15 Z M9,11 L19,11 L9,11 Z M9,7 L13,7 L9,7 Z M16,1 L16,7 L22,7 M6,5 L2,5 L2,23 L18,23 L18,19 M22,19 L6,19 L6,1 L17,1 L22,6 L22,19 L22,19 Z"/>
              </svg>
              
		</div>
	</div>
</div>
