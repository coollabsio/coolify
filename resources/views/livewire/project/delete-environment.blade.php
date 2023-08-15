<div>
    <x-modal yesOrNo modalId="deleteEnvironment" modalTitle="Delete Environment">
        <x-slot:modalBody>
            <p>This environment will be deleted. It is not reversible. <br>Please think again.</p>
        </x-slot:modalBody>
    </x-modal>
    <x-forms.button isError isModal modalId="deleteEnvironment"> Delete Environment</x-forms.button>
</div>
