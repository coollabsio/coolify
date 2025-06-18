<x-emails.layout>
Failed to check for package updates on your server {{ $name }}.

## Error Details

- Operating System: {{ ucfirst($osId) }}
- Package Manager: {{ $package_manager }}
- Error: {{ $error }}

---

You can manage your server and view more details in your [Coolify Dashboard]({{ $server_url }}).
</x-emails.layout>
