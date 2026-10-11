<div class="grid gap-4 sm:grid-cols-2">
    @if ($resourceType === 'application')
        @if (str($serviceApplication->image)->contains('pocketbase'))
            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                helper="PocketBase keeps compression disabled so server-sent events continue to work."
                :disabled="true" :options="[
                    ['value' => true, 'label' => 'Enabled'],
                    ['value' => false, 'label' => 'Disabled'],
                ]" />
        @else
            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                :options="[
                    ['value' => true, 'label' => 'Enabled'],
                    ['value' => false, 'label' => 'Disabled'],
                ]" />
        @endif
        <x-forms.listbox id="isStripprefixEnabled" label="Path prefixes"
            :options="[
                ['value' => true, 'label' => 'Strip prefixes'],
                ['value' => false, 'label' => 'Keep prefixes'],
            ]" />
        <x-forms.listbox id="excludeFromStatus" label="Service status"
            :options="[
                ['value' => false, 'label' => 'Include in status'],
                ['value' => true, 'label' => 'Exclude from status'],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
            :options="[
                ['value' => true, 'label' => 'Send logs to drain'],
                ['value' => false, 'label' => 'Do not drain logs'],
            ]" />
        <x-forms.input type="number" min="0" id="maxRestartCount" label="Max restart count"
            helper="Maximum number of Docker restarts before Coolify stops this container. Set to 0 to disable the limit. Docker counts expected and unexpected restarts."
            canGate="update" :canResource="$serviceApplication" />
    @else
        <x-forms.listbox id="excludeFromStatus" label="Service status"
            :options="[
                ['value' => false, 'label' => 'Include in status'],
                ['value' => true, 'label' => 'Exclude from status'],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
            :options="[
                ['value' => true, 'label' => 'Send logs to drain'],
                ['value' => false, 'label' => 'Do not drain logs'],
            ]" />
    @endif
</div>
