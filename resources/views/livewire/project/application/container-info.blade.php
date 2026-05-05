
<div>
    <div class="flex flex-col gap-4">
        @if($this->containerInfo)
            <div class="card">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-bold">Container Information</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <tbody>
                                <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-2 font-medium">Container ID</td>
                                    <td class="py-2">{{ Str::limit($this->containerInfo['id'], 12) }}</td>
                                </tr>
                                <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-2 font-medium">Docker Image</td>
                                    <td class="py-2">{{ $this->containerInfo['image'] }}</td>
                                </tr>
                                <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-2 font-medium">Image ID</td>
                                    <td class="py-2">{{ Str::limit($this->containerInfo['image_id'], 12) }}</td>
                                </tr>
                                <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-2 font-medium">Created At</td>
                                    <td class="py-2">
                                        {{ $this->containerInfo['created_at'] }}
                                        <span class="text-xs text-coolgray-500 dark:text-coolgray-400">
                                            ({{ $this->containerInfo['created_at_human'] }})
                                        </span>
                                    </td>
                                </tr>
                                <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-2 font-medium">Status</td>
                                    <td class="py-2">
                                        <span class="badge badge-{{ $this->containerInfo['status'] === 'running' ? 'success' : 'warning' }}">
                                            {{ ucfirst($this->containerInfo['status']) }}
                                        </span>
                                    </td>
                                </tr>
                                @if($this->containerInfo['status'] === 'running')
                                    <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                        <td class="py-2 font-medium">Started At</td>
                                        <td class="py-2">{{ $this->containerInfo['started_at'] }}</td>
                                    </tr>
                                    <tr class="border-b border-coolgray-200 dark:border-coolgray-700">
                                        <td class="py-2 font-medium">Uptime</td>
                                        <td class="py-2">{{ $this->containerInfo['uptime'] }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @else
            <div class="card">
                <div class="flex flex-col items-center justify-center gap-2 p-8">
                    <div class="text-center">
                        <h3 class="text-lg font-bold">Container Offline</h3>
                        <p class="text-coolgray-500 dark:text-coolgray-400">
                            Metadata unavailable. The container is not running.
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
