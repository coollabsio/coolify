<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

final class SetWorkspace
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->query->has('workspace')) {
            $workspaceId = $request->query->getString('workspace');

            // Abort if the workspace ID was tampered with, does not exist, or the user is not a member.
            abort_unless($this->isMember($user, $workspaceId), 404);

            $this->persist($workspaceId);

            return $next($request);
        }

        return $this->resolveWorkspace($user, $request);
    }

    private function resolveWorkspace(User $user, Request $request): Response
    {
        $candidate = $request->cookie('workspace');

        if (is_string($candidate) && $this->isMember($user, $candidate)) {
            return $this->redirectWithWorkspace($request, $candidate);
        }

        $candidate = session('workspace');

        if (is_string($candidate) && $this->isMember($user, $candidate)) {
            return $this->redirectWithWorkspace($request, $candidate);
        }

        $count = $user->workspaces()->count();

        if ($count === 1) {
            return $this->redirectWithWorkspace($request, $user->workspaces()->sole()->id);
        }

        // TODO: Redirect to workspace selector.
        // if ($count > 1) {
        //     return redirect('/');
        // }

        // TODO: Redirect to workspace creation.
        return redirect('/');
    }

    private function redirectWithWorkspace(Request $request, string $workspaceId): Response
    {
        return redirect()->to($request->fullUrlWithQuery(['workspace' => $workspaceId]));
    }

    private function isMember(User $user, string $workspaceId): bool
    {
        return $user->workspaces()->whereKey($workspaceId)->exists();
    }

    private function persist(string $workspaceId): void
    {
        session(['workspace' => $workspaceId]);
        context(['workspace' => $workspaceId]);
        cookie()->queue('workspace', $workspaceId, 30 * 24 * 60);
    }
}
