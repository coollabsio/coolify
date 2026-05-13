<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Project Invitation</title>
</head>
<body style="font-family: sans-serif; padding: 20px;">
    <h1>You've been invited to a project</h1>
    <p>
        You have been invited to join the project <strong>{{ $project->name }}</strong>
        as a <strong>{{ $role }}</strong>.
    </p>
    <p>
        <a href="{{ $acceptUrl }}" style="background:#2563eb;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
            Accept Invitation
        </a>
    </p>
    <p>This invitation expires on {{ $expiresAt->format('M d, Y') }}.</p>
</body>
</html>
