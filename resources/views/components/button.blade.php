  <button wire:loading.remove {{ $attributes }} class="btn btn-primary rounded-none btn-xs no-animation"> {{ $slot }} </button>

  <button wire:loading class="btn btn-disabled rounded-none btn-xs no-animation"> {{ $slot }}</button>
