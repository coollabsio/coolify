<?php

namespace App\Livewire\Project\Shared;

use App\Rules\ManualWebhookSecret;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

// Refactored ✅
class Webhooks extends Component
{
    use AuthorizesRequests;

    public $resource;

    public ?string $deploywebhook;

    public ?string $githubManualWebhook;

    public ?string $gitlabManualWebhook;

    public ?string $bitbucketManualWebhook;

    public ?string $giteaManualWebhook;

    public ?string $githubManualWebhookSecret = null;

    public ?string $gitlabManualWebhookSecret = null;

    public ?string $bitbucketManualWebhookSecret = null;

    public ?string $giteaManualWebhookSecret = null;

    /**
     * Maps each manual webhook provider to its component property and model attribute.
     *
     * @var array<string, array{property: string, attribute: string, label: string}>
     */
    private const SECRET_FIELDS = [
        'github' => ['property' => 'githubManualWebhookSecret', 'attribute' => 'manual_webhook_secret_github', 'label' => 'GitHub webhook secret'],
        'gitlab' => ['property' => 'gitlabManualWebhookSecret', 'attribute' => 'manual_webhook_secret_gitlab', 'label' => 'GitLab webhook secret'],
        'bitbucket' => ['property' => 'bitbucketManualWebhookSecret', 'attribute' => 'manual_webhook_secret_bitbucket', 'label' => 'Bitbucket webhook secret'],
        'gitea' => ['property' => 'giteaManualWebhookSecret', 'attribute' => 'manual_webhook_secret_gitea', 'label' => 'Gitea webhook secret'],
    ];

    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);

        if ($this->canViewSecrets()) {
            $this->githubManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_github');
            $this->gitlabManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitlab');
            $this->bitbucketManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_bitbucket');
            $this->giteaManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitea');
        }

        $this->githubManualWebhook = generateGitManualWebhook($this->resource, 'github');
        $this->gitlabManualWebhook = generateGitManualWebhook($this->resource, 'gitlab');
        $this->bitbucketManualWebhook = generateGitManualWebhook($this->resource, 'bitbucket');
        $this->giteaManualWebhook = generateGitManualWebhook($this->resource, 'gitea');
    }

    public function canViewSecrets(): bool
    {
        return auth()->user()->can('update', $this->resource);
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate($this->changedSecretRules(), [], $this->secretAttributeNames());
            $this->resource->update([
                'manual_webhook_secret_github' => $this->githubManualWebhookSecret,
                'manual_webhook_secret_gitlab' => $this->gitlabManualWebhookSecret,
                'manual_webhook_secret_bitbucket' => $this->bitbucketManualWebhookSecret,
                'manual_webhook_secret_gitea' => $this->giteaManualWebhookSecret,
            ]);
            $this->dispatch('success', 'Webhook secrets saved.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Return a random secret for the provider's field. The browser fills the field
     * as an unsaved change, and the user saves it with the form.
     */
    public function generateSecret(string $provider): ?string
    {
        try {
            $this->authorize('update', $this->resource);

            if (! array_key_exists($provider, self::SECRET_FIELDS)) {
                throw new \InvalidArgumentException('Unknown webhook provider.');
            }

            return Str::random(40);
        } catch (\Exception $e) {
            handleError($e, $this);

            return null;
        }
    }

    /**
     * Rules only for secrets that differ from the stored value, so existing short
     * secrets keep saving until they are changed.
     *
     * @return array<string, array<int, mixed>>
     */
    private function changedSecretRules(): array
    {
        $rules = [];
        foreach (self::SECRET_FIELDS as $field) {
            $currentValue = data_get($this->resource, $field['attribute']);
            if ($this->{$field['property']} !== $currentValue) {
                $rules[$field['property']] = ['nullable', 'string', new ManualWebhookSecret($currentValue)];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function secretAttributeNames(): array
    {
        return collect(self::SECRET_FIELDS)
            ->mapWithKeys(fn (array $field) => [$field['property'] => $field['label']])
            ->all();
    }
}
