<div>
    <div
        class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300 border-neutral-200">
        <div class="flex flex-wrap items-center gap-2">
            <span
                class="px-2 py-0.5 text-xs font-normal rounded dark:bg-coolgray-400/50 bg-neutral-200 dark:text-neutral-400 text-neutral-600">
                Hardcoded env
            </span>
            @if($serviceName)
                <span
                    class="px-2 py-0.5 text-xs font-normal rounded dark:bg-coolgray-400/50 bg-neutral-200 dark:text-neutral-400 text-neutral-600">
                    Service: {{ $serviceName }}
                </span>
            @endif
        </div>
        <div class="flex flex-col w-full gap-2">
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                <x-forms.input disabled id="key" />
                @if($value !== null && $value !== '')
                    <x-forms.input disabled type="password" value="{{ $value }}" />
                @else
                    <x-forms.input disabled value="(inherited from host)" />
                @endif
            </div>
            @if($comment)
                <x-forms.input disabled value="{{ $comment }}" label="Comment"
                    helper="Documentation for this environment variable." />
            @endif
        </div>
    </div>
</div>