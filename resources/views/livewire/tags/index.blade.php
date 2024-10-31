<div>
    <h1>Tags</h1>
    <div class="flex flex-col pb-6">
        <div class="subtitle">Tags help you to perform actions on multiple resources.</div>
        <div class="">
            @if ($tags->count() === 0)
                <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
            @else
                <x-forms.datalist wire:model="tag" onUpdate='tagUpdated'>
                    @foreach ($tags as $oneTag)
                        <option value="{{ $oneTag->name }}">{{ $oneTag->name }}</option>
                    @endforeach
                </x-forms.datalist>
                @if ($tag)
                    <div class="pt-5">
                        <div class="flex items-end gap-2 ">
                            <div class="w-[500px]">
                                <x-forms.input readonly label="Deploy Webhook URL" id="webhook" />
                            </div>
                            <x-modal-confirmation title="Redeploy all resources with this tag?" isHighlighted
                                buttonTitle="Redeploy All" submitAction="redeployAll" :actions="[
                                    'All resources with this tag will be redeployed.',
                                    'During redeploy resources will be temporarily unavailable.',
                                ]"
                                confirmationText="{{ $tag }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Tag Name below"
                                shortConfirmationLabel="Tag Name" :confirmWithPassword="false" step2ButtonText="Redeploy All" />
                        </div>
                        <div class="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                            @foreach ($applications as $application)
                                <a href="{{ $application->link() }}"class="box group">
                                    <div class="flex flex-col">
                                        <div class="box-title">{{ $application->name }}</div>
                                        <div class="box-description">
                                            {{ $application->project()->name }}/{{ $application->environment->name }}
                                        </div>
                                        <div class="box-description">{{ $application->description }}</div>
                                    </div>
                                </a>
                            @endforeach
                            @foreach ($services as $service)
                                <a href="{{ $service->link() }}" class="box group">
                                    <div class="flex flex-col ">
                                        <div class="box-title">{{ $service->name }}</div>
                                        <div class="box-description">
                                            {{ $service->project()->name }}/{{ $service->environment->name }}</div>
                                        <div class="box-description">{{ $service->description }}</div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            <h3 class="py-4">Deployments</h3>
                            @if (count($deploymentsPerTagPerServer) > 0)
                                <x-loading />
                            @endif
                        </div>
                        <livewire:tags.deployments :deploymentsPerTagPerServer="$deploymentsPerTagPerServer" :resourceIds="$applications->pluck('id')" />
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
