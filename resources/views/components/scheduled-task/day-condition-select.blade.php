<x-forms.select id="dayCondition" label="Day condition" :disabled="$disabled ?? false"
    helper="Optional day-of-week filter combined with frequency using AND logic. Example: frequency <code>0 0 1-7 * *</code> with Thursday only runs on Thursdays that fall on days 1-7 of the month.">
    @foreach (SCHEDULED_TASK_DAY_CONDITIONS as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</x-forms.select>
