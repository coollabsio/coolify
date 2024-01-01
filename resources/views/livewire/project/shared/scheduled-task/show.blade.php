<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Scheduled Task">
        <x-slot:modalBody>
            <p>Are you sure you want to delete this scheduled task <span
                    class="font-bold text-warning">({{ $task->name }})</span>?</p>
        </x-slot:modalBody>
    </x-modal>
    <form wire:submit='submit'
        class="flex flex-col gap-2 p-4 m-2 border lg:items-center border-coolgray-300 lg:m-0 lg:p-0 lg:border-0 lg:flex-row">
        <x-forms.input id="task.name" />
        <x-forms.input id="task.command" />
        <div class="flex gap-2">
            <x-forms.button type="submit">
                Update
            </x-forms.button>
            <x-forms.button isError isModal modalId="{{ $modalId }}">
                Delete
            </x-forms.button>
        </div>
    </form>
</div>
