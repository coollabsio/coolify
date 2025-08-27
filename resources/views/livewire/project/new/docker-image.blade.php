<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy an existing Docker Image from any Registry.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>Docker Image</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="space-y-4">
            <x-forms.textarea 
                id="dockerImage" 
                placeholder="nginx:latest or ghcr.io/benjaminehowe/rail-disruptions:sha256-59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0"
                helper="Enter a Docker image with tag or SHA256 hash. Examples:<br>• nginx:latest<br>• ghcr.io/user/app:v1.2.3<br>• sha256-59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0"
                rows="3"
            />
        </div>
    </form>
</div>
