<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Renderless;

/**
 * Remembers the last view (table, grid, list, or canvas) of a list page per user,
 * so the page opens the canvas again when the user left it on the canvas.
 * One cookie per user and page holds the choice; the server reads it before
 * rendering, so the page does not flash the list before it opens the canvas.
 */
trait RemembersCanvasView
{
    private const array CANVAS_VIEW_PAGES = ['servers', 'server_resources', 'projects', 'project', 'environment', 'tags', 'team_variables', 'project_variables', 'environment_variables', 'server_variables', 'dashboard', 'dashboard_projects', 'dashboard_servers'];

    private const array LIST_VIEWS = ['table', 'grid', 'list'];

    /**
     * List page switcher: remember table or grid without a re-render.
     */
    #[Renderless]
    public function saveListView(string $page, string $view): void
    {
        if (in_array($page, self::CANVAS_VIEW_PAGES, true) && in_array($view, self::LIST_VIEWS, true)) {
            $this->rememberView($page, $view);
        }
    }

    /**
     * @param  'servers'|'server_resources'|'projects'|'project'|'environment'|'tags'|'team_variables'|'project_variables'|'environment_variables'|'server_variables'  $page
     * @param  'table'|'grid'|'list'|'canvas'  $view
     */
    protected function rememberView(string $page, string $view): void
    {
        Cookie::queue(Cookie::forever($this->viewCookieName($page), $view));
    }

    /**
     * List page: true when the user last used the canvas. A ?view=table|grid|list link
     * (the switcher on the canvas page) picks the list again and is remembered.
     *
     * @param  'servers'|'server_resources'|'projects'|'project'|'environment'|'tags'|'team_variables'|'project_variables'|'environment_variables'|'server_variables'  $page
     */
    protected function prefersCanvasView(string $page): bool
    {
        $view = request()->query('view');
        if (in_array($view, self::LIST_VIEWS, true)) {
            $this->rememberView($page, $view);

            return false;
        }

        return request()->cookie($this->viewCookieName($page)) === 'canvas';
    }

    /**
     * List page: the remembered list view (table or grid), or $default.
     */
    protected function listView(string $page, string $default): string
    {
        foreach ([request()->query('view'), request()->cookie($this->viewCookieName($page))] as $view) {
            if (in_array($view, ['table', 'grid'], true)) {
                return $view;
            }
        }

        return $default;
    }

    private function viewCookieName(string $page): string
    {
        return 'coolify_'.auth()->id().'_'.$page.'_view';
    }
}
