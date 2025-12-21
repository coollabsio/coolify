<div>
    <h1>{{ __('application.create_new_application') }}</h1>
    <div class="pb-4">{{ __('application.simple_dockerfile_desc') }}</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pb-1">
            <h2>{{ __('application.dockerfile') }}</h2>
            <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
        </div>
        <x-forms.textarea useMonacoEditor monacoEditorLanguage="dockerfile" rows="20" id="dockerfile" autofocus
            placeholder='FROM nginx
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
'></x-forms.textarea>
    </form>
</div>
