<x-emails.layout>
Pull request #{{ $pull_request_id }} of {{ $name }} comes from a fork. Commit {{ $commit }} waits for an admin to approve it before Coolify deploys it.

[Review the pull request]({{ $pull_request_url }})

[Approve on the Preview Deployments page]({{ $previews_url }})
</x-emails.layout>
