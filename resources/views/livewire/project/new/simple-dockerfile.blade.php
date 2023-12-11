<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy a simple Dockerfile, without Git.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pb-1">
            <h2>Dockerfile</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.textarea rows="20" id="dockerfile"
            placeholder='FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
'></x-forms.textarea>
    </form>
</div>
