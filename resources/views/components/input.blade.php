<label for={{ $name }}>{{ $name }}</label>
<input id={{ $name }} wire:model={{ $name }} type="text" name={{ $name }}
    {{ $required }} />
@error($name)
    <span class="text-red-500">{{ $message }}</span>
@enderror
