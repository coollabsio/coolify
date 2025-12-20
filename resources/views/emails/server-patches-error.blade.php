<x-emails.layout>
{{ __('email.server_patches_error.body', ['name' => $name]) }}

## {{ __('email.server_patches_error.details') }}

- {{ __('email.server_patches_error.os', ['os' => ucfirst($osId)]) }}
- {{ __('email.server_patches_error.package_manager', ['manager' => $package_manager]) }}
- {{ __('email.server_patches_error.error', ['error' => $error]) }}

---

{{ __('email.server_patches_error.action', ['url' => $server_url]) }}
</x-emails.layout>
