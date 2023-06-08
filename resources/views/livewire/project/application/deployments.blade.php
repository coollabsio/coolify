 <div class="flex flex-col gap-2" wire:init='load_deployments'
     @if ($skip == 0) wire:poll.5000ms='reload_deployments' @endif>
     <h2 class="pt-4">Deployments <span class="text-xs">({{ $deployments_count }})</span></h2>
     @if (count($deployments) === 0)
         <x-forms.button wire:click="load_deployments({{ $default_take }})">Load Deployments
         </x-forms.button>
     @endif
     @if ($show_next)
         <x-forms.button wire:click="load_deployments({{ $default_take }})">Show More
         </x-forms.button>
     @endif
     @foreach ($deployments as $deployment)
         <a @class([
             'bg-coolgray-200 p-2 border-l border-dashed transition-colors hover:no-underline',
             'cursor-not-allowed hover:bg-coolgray-200' =>
                 data_get($deployment, 'status') === 'queued' ||
                 data_get($deployment, 'status') === 'cancelled by system',
             'border-warning hover:bg-warning hover:text-black' =>
                 data_get($deployment, 'status') === 'in_progress',
             'border-error hover:bg-error' =>
                 data_get($deployment, 'status') === 'error',
             'border-success hover:bg-success' =>
                 data_get($deployment, 'status') === 'finished',
         ]) @if (data_get($deployment, 'status') !== 'cancelled by system' && data_get($deployment, 'status') !== 'queued')
             href="{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}"
     @endif
     class="hover:no-underline">
     <div class="flex flex-col justify-start">
         <div>
             {{ $deployment->id }} <span class="text-sm text-warning">></span> {{ $deployment->deployment_uuid }}
             <span class="text-sm text-warning">></span>
             {{ $deployment->status }}
         </div>
         @if (data_get($deployment, 'pull_request_id'))
             <div>
                 Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                 @if (data_get($deployment, 'is_webhook'))
                     (Webhook)
                 @endif
             </div>
         @elseif (data_get($deployment, 'is_webhook'))
             <div>Webhook (sha
                 @if (data_get($deployment, 'commit'))
                     {{ data_get($deployment, 'commit') }})
                 @else
                     HEAD)
                 @endif
             </div>
         @endif
         <div class="flex flex-col" x-data="elapsedTime('{{ $deployment->deployment_uuid }}', '{{ $deployment->status }}', '{{ $deployment->created_at }}', '{{ $deployment->updated_at }}')">
             <div>
                 @if ($deployment->status !== 'in_progress')
                     Finished <span x-text="measure_since_started()">0s</span> in
                 @else
                     Running for
                 @endif
                 <span class="font-bold" x-text="measure_finished_time()">0s</span>
             </div>
         </div>
     </div>
     </a>
     @endforeach
     <script src="https://cdn.jsdelivr.net/npm/dayjs@1/dayjs.min.js"></script>
     <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/utc.js"></script>
     <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/relativeTime.js"></script>
     <script>
         document.addEventListener('alpine:init', () => {
             let timers = {};

             dayjs.extend(window.dayjs_plugin_utc);
             dayjs.extend(window.dayjs_plugin_relativeTime);

             Alpine.data('elapsedTime', (uuid, status, created_at, updated_at) => ({
                 finished_time: '0s',
                 started_time: '0s',
                 init() {
                     if (timers[uuid]) {
                         clearInterval(timers[uuid]);
                     }
                     if (status === 'in_progress') {
                         timers[uuid] = setInterval(() => {
                             this.finished_time = dayjs().diff(dayjs.utc(created_at),
                                 'second') + 's'
                         }, 1000);
                     } else {
                         let seconds = dayjs.utc(updated_at).diff(dayjs.utc(created_at), 'second')
                         this.finished_time = seconds + 's';
                     }
                 },
                 measure_finished_time() {
                     return this.finished_time;
                 },
                 measure_since_started() {
                     return dayjs.utc(created_at).fromNow();
                 }
             }))
         })
     </script>
 </div>
