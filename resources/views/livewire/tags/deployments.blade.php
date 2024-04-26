 <div wire:poll.1000ms="get_deployments" class="grid grid-cols-1">
     @forelse ($deployments_per_tag_per_server as $server_name => $deployments)
         <h4 class="py-4">{{ $server_name }}</h4>
         <div class="grid grid-cols-1 gap-2 lg:grid-cols-3">
             @foreach ($deployments as $deployment)
                 <div @class([
                     'box-without-bg dark:bg-coolgray-100 bg-white gap-2 cursor-pointer group border-l-2 border-dotted',
                     'dark:border-coolgray-300' => data_get($deployment, 'status') === 'queued',
                     'border-yellow-500' => data_get($deployment, 'status') === 'in_progress',
                 ])>
                     <a href="{{ data_get($deployment, 'deployment_url') }}">
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
                 </div>
             @endforeach
         </div>
     @empty
         <div>No deployments running.</div>
     @endforelse
 </div>
