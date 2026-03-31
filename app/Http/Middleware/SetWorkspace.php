<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Scopes\WorkspaceScope;
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
            $memberId = $this->resolveMembership($user, $workspaceId);

            abort_if($memberId === null, 404);

            $this->persist($workspaceId, $memberId);

            return $next($request);
        }

        return $this->resolveWorkspace($user, $request);
    }

    private function resolveWorkspace(User $user, Request $request): Response
    {
        $candidate = $request->cookie('workspace');

        if (is_string($candidate) && $this->resolveMembership($user, $candidate) !== null) {
            return $this->redirectWithWorkspace($request, $candidate);
        }

        $candidate = session('workspace');

        if (is_string($candidate) && $this->resolveMembership($user, $candidate) !== null) {
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

    private function resolveMembership(User $user, string $workspaceId): ?string
    {
        $id = $user->memberships()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->value('id');

        return is_string($id) ? $id : null;
    }

    private function persist(string $workspaceId, string $memberId): void
    {
        session(['workspace' => $workspaceId, 'workspace_member' => $memberId]);
        context(['workspace' => $workspaceId, 'workspace_member' => $memberId]);
        cookie()->queue('workspace', $workspaceId, 30 * 24 * 60);
    }
}
