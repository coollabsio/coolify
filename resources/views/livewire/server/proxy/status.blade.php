<div class="flex gap-2" x-init="$wire.getProxyStatus">
    @if ($server->proxy->status === 'running')
        <x-status.running text="Proxy Running" />
    @elseif ($server->proxy->status === 'restarting')
        <x-status.restarting text="Proxy Restarting" />
    @else
        <x-status.stopped text="Proxy Stopped" />
    @endif
    <button wire:loading.remove.delay.longer wire:click.prevent='getProxyStatusWithNoti'>
        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <g fill="#FCD44F">
                <path
                    d="M12.079 3v-.75V3Zm-8.4 8.333h-.75h.75Zm0 1.667l-.527.532a.75.75 0 0 0 1.056 0L3.68 13Zm2.209-1.134A.75.75 0 1 0 4.83 10.8l1.057 1.065ZM2.528 10.8a.75.75 0 0 0-1.056 1.065L2.528 10.8Zm16.088-3.408a.75.75 0 1 0 1.277-.786l-1.277.786ZM12.079 2.25c-5.047 0-9.15 4.061-9.15 9.083h1.5c0-4.182 3.42-7.583 7.65-7.583v-1.5Zm-9.15 9.083V13h1.5v-1.667h-1.5Zm1.28 2.2l1.679-1.667L4.83 10.8l-1.68 1.667l1.057 1.064Zm0-1.065L2.528 10.8l-1.057 1.065l1.68 1.666l1.056-1.064Zm15.684-5.86A9.158 9.158 0 0 0 12.08 2.25v1.5a7.658 7.658 0 0 1 6.537 3.643l1.277-.786Z" />
                <path fill="#fff"
                    d="M11.883 21v.75V21Zm8.43-8.333h.75h-.75Zm0-1.667l.528-.533a.75.75 0 0 0-1.055 0l.528.533ZM18.1 12.133a.75.75 0 1 0 1.055 1.067L18.1 12.133Zm3.373 1.067a.75.75 0 1 0 1.054-1.067L21.473 13.2ZM5.318 16.606a.75.75 0 1 0-1.277.788l1.277-.788Zm6.565 5.144c5.062 0 9.18-4.058 9.18-9.083h-1.5c0 4.18-3.43 7.583-7.68 7.583v1.5Zm9.18-9.083V11h-1.5v1.667h1.5Zm-1.277-2.2L18.1 12.133l1.055 1.067l1.686-1.667l-1.055-1.066Zm0 1.066l1.687 1.667l1.054-1.067l-1.686-1.666l-1.055 1.066Zm-15.745 5.86a9.197 9.197 0 0 0 7.841 4.357v-1.5a7.697 7.697 0 0 1-6.564-3.644l-1.277.788Z"
                    opacity=".5" />
            </g>
        </svg></button>
</div>
