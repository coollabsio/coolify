<div>
    @forelse ($storages as $storage)
        <p>Name:{{ data_get($storage, 'name') }}</p>
        <p>MountPath:{{ data_get($storage, 'mount_path') }}</p>
        <p>HostPath:{{ data_get($storage, 'host_path') }}</p>
        <p>ContainerId:{{ data_get($storage, 'container_id') }}</p>
    @empty
        <p>No storage found.</p>
    @endforelse
</div>
