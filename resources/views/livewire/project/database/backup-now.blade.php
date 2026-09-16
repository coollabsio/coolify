<x-forms.button wire:click="backupNow"
    :disabled="! str($backup->database->status)->startsWith('running')"
    :tooltip="! str($backup->database->status)->startsWith('running') ? 'The database must be running to start a backup.' : null">Back up now</x-forms.button>
