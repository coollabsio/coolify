 <div wire:poll.2000ms="getDeployments" wire:init='getDeployments'>
     @forelse ($deploymentsPerTagPerServer as $server_name => $deployments)
         <h4 class="py-4">{{ $server_name }}</h4>
         <div class="grid grid-cols-1 gap-2">
             @foreach ($deployments as $deployment)
                 <a wire:navigate href="{{ data_get($deployment, 'deployment_url') }}" @class([
                     'box-without-bg-without-border dark:bg-coolgray-100 bg-white gap-2 cursor-pointer group border-l-2',
                     'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                     'dark:border-yellow-500' =>
                         data_get($deployment, 'status') === 'in_progress',
                 ])>
                     <div class="flex flex-col mx-6">
                         <div class="box-title">
                             {{ data_get($deployment, 'application_name') }}
                         </div>
                         <div class="box-description">
                             {{ str(data_get($deployment, 'status'))->headline() }}
                         </div>
                     </div>
                     <div class="flex-1"></div>
                 </a>
             @endforeach
         </div>
     @empty
         <div>No deployments running.</div>
     @endforelse
 </div>
