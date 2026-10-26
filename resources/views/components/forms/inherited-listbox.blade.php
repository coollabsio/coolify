@props([
    'id',
    'label' => null,
    'helper' => null,
    'options' => [],
    'inheritLabel' => null, // "Use {level} default ({value})"; null at the top level, which has no inherit option
    'onChange' => '$commit', // sends the deferred value, so the component's updated*() hook saves it
    'disabled' => false,
])

{{-- A listbox whose first option inherits the value of the level above. The inherit option has the value '' (stored as null). --}}
<x-forms.listbox :id="$id" :label="$label" :helper="$helper" :onChange="$onChange" :disabled="$disabled"
    :options="$inheritLabel === null ? $options : [['value' => '', 'label' => $inheritLabel], ...$options]" />
