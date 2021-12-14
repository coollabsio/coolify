<script>
	export let name;
	export let value;
    let setting = false
</script>


<div class="max-w-3xl mx-auto pt-4">
	<div class="flex space-x-4">
		<div class="grid grid-flow-row">
			<label for="secretName">Secret Name</label>
			<input
				id="secretName"
				bind:value={name}
				placeholder="Name"
				class="w-64 border-2 border-transparent"
			/>
		</div>
		<div class="grid grid-flow-row">
			<label for="secretValue">Secret Value</label>
			<input
				id="secretValue"
				bind:value={value}
				placeholder="Value"
				class="w-64 border-2 border-transparent"
			/>
		</div>

		<div class="grid grid-flow-row text-center">
			<label for="buildVariable">Is build variable?</label>
			<div class="mt-2 w-full">
                <ul class="divide-y divide-warmGray-800">
                    <li>
                        <div
                        type="button"
                        on:click={() => (setting = !setting)}
                        aria-pressed="false"
                        class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 mx-20"
                        class:bg-green-600={setting}
                        class:bg-warmGray-700={!setting}
                    >
                        <span class="sr-only">Use setting</span>
                        <span
                            class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
                            class:translate-x-5={setting}
                            class:translate-x-0={!setting}
                        >
                            <span
                                class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
                                class:opacity-0={setting}
                                class:opacity-100={!setting}
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
                                class:opacity-100={setting}
                                class:opacity-0={!setting}
                            >
                                <svg class="bg-white h-3 w-3 text-green-600" fill="currentColor" viewBox="0 0 12 12">
                                    <path
                                        d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
                                    />
                                </svg>
                            </span>
                        </span>
                    </div>
                    </li>
                </ul>
			</div>
		</div>
		<div class="mt-6">
			<button class="icon hover:text-green-500">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					class="h-6 w-6"
					fill="none"
					viewBox="0 0 24 24"
					stroke="currentColor"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M12 6v6m0 0v6m0-6h6m-6 0H6"
					/>
				</svg>
			</button>
		</div>
	</div>
</div>
<!-- 
<div class="flex-col space-x-2 items-end justify-start">
    <div class="flex">
        <div>NAme</div>
        <div>VAlue</div>
        <div>Build env</div>
    </div>
    <div>
        <input id={name} value={name} />
        <input id={value} {value} />
        <ul class="divide-y divide-warmGray-800">
			<li>
                <div
                type="button"
                on:click={() => (setting = !setting)}
                aria-pressed="false"
                class="relative inline-flex flex-shrink-0 h-6 w-11 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 mx-20"
                class:bg-green-600={setting}
                class:bg-warmGray-700={!setting}
            >
                <span class="sr-only">Use setting</span>
                <span
                    class="pointer-events-none relative inline-block h-5 w-5 rounded-full bg-white shadow transition ease-in-out duration-200 transform"
                    class:translate-x-5={setting}
                    class:translate-x-0={!setting}
                >
                    <span
                        class=" ease-in duration-200 absolute inset-0 h-full w-full flex items-center justify-center transition-opacity"
                        class:opacity-0={setting}
                        class:opacity-100={!setting}
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
                        class:opacity-100={setting}
                        class:opacity-0={!setting}
                    >
                        <svg class="bg-white h-3 w-3 text-green-600" fill="currentColor" viewBox="0 0 12 12">
                            <path
                                d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"
                            />
                        </svg>
                    </span>
                </span>
            </div>
            </li>
		</ul>
    </div>
   
</div> -->
