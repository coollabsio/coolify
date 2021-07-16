<script>
	export let secret;
	export let readOnly = false;
	function isBuildSet() {
		if (!readOnly) secret.isBuild = !secret.isBuild;
	}
</script>

<button
	id="buildVariable"
	type="button"
	aria-pressed="false"
	on:click={isBuildSet}
	class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-100"
	class:bg-green-600={secret.isBuild}
	class:bg-warmGray-700={!secret.isBuild}
    class:opacity-50={readOnly}
    class:cursor-not-allowed={readOnly}
>
	<span class="sr-only">Use setting</span>
	<span
		class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
		class:translate-x-5={secret.isBuild}
		class:translate-x-0={!secret.isBuild}
	>
		<span
			class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
			class:opacity-0={secret.isBuild}
			class:opacity-100={!secret.isBuild}
			aria-hidden="true"
		>
			<svg class="bg-white h-3 w-3 text-red-600" fill="none" viewBox="0 0 12 12">
				<path
					d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2"
					stroke="currentColor"
					stroke-width="2"
					stroke-linecap="round"
					stroke-linejoin="round"
				/>
			</svg>
		</span>
		<span
			class="ease-out duration-100 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
			aria-hidden="true"
			class:opacity-100={secret.isBuild}
			class:opacity-0={!secret.isBuild}
		>
			<svg class="bg-white h-3 w-3 text-green-600" fill="currentColor" viewBox="0 0 12 12">
				<path
					d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
				/>
			</svg>
		</span>
	</span>
</button>
