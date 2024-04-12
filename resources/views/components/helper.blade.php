<div {{ $attributes->merge(['class' => 'group']) }}>
    <div class="info-helper">
        @isset($icon)
            {{ $icon }}
        @else
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="w-4 h-4 stroke-current">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        @endisset

    </div>
    <div class="info-helper-popup">
        <div class="p-4">
            {!! $helper !!}
        </div>
    </div>
</div>
