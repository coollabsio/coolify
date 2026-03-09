<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Http;
use Spatie\Url\Url;

use function data_get;
use function str;

trait HasGitRepository
{
    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base_dir = $this->base_directory ?? '/';
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "{$this->source->html_url}/{$this->git_repository}/src/{$this->git_branch}{$base_dir}";
                    }
                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}{$base_dir}";
                }
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "https://{$git_repository}/src/{$this->git_branch}{$base_dir}";
                    }
                    return "https://{$git_repository}/tree/{$this->git_branch}{$base_dir}";
                }
                return $this->git_repository;
            }
        );
    }

    public function gitWebhook(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/settings/hooks";
                }
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
                    return "https://{$git_repository}/settings/hooks";
                }
                return $this->git_repository;
            }
        );
    }

    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
                    return "https://{$git_repository}/commits/{$this->git_branch}";
                }
                return $this->git_repository;
            }
        );
    }

    public function gitCommitLink($link): string
    {
        if (!is_null(data_get($this, 'source.html_url')) && !is_null(data_get($this, 'git_repository')) && !is_null(data_get($this, 'git_branch'))) {
            if (str($this->source->html_url)->contains('bitbucket')) {
                return "{$this->source->html_url}/{$this->git_repository}/commits/{$link}";
            }
            return "{$this->source->html_url}/{$this->git_repository}/commit/{$link}";
        }
        if (str($this->git_repository)->contains('bitbucket')) {
            $git_repository = str_replace('.git', '', $this->git_repository);
            $url = Url::fromString($git_repository);
            $url = $url->withUserInfo('');
            $url = $url->withPath($url->getPath() . '/commits/' . $link);
            return $url->__toString();
        }
        if (strpos($this->git_repository, 'git@') === 0) {
            $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
            if (data_get($this, 'source.html_url')) {
                return "{$this->source->html_url}/{$git_repository}/commit/{$link}";
            }
            return "{$git_repository}/commit/{$link}";
        }
        return $this->git_repository;
    }
}
