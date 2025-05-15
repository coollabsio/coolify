<?php

namespace App\Notifications;

use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Spatie\Url\Url;

class SslExpirationNotification extends CustomEmailNotification
{
    protected Collection $resources;

    protected array $urls = [];

    public function __construct(array|Collection $resources)
    {
        $this->onQueue('high');
        $this->resources = collect($resources);

        // Collect URLs for each resource
        $this->resources->each(function ($resource) {
            if (data_get($resource, 'environment.project.uuid')) {
                $routeName = match ($resource->type()) {
                    'application' => 'project.application.configuration',
                    'database' => 'project.database.configuration',
                    'service' => 'project.service.configuration',
                    default => null
                };

                if ($routeName) {
                    $route = route($routeName, [
                        'project_uuid' => data_get($resource, 'environment.project.uuid'),
                        'environment_uuid' => data_get($resource, 'environment.uuid'),
                        $resource->type().'_uuid' => data_get($resource, 'uuid'),
                    ]);

                    $settings = instanceSettings();
                    if (data_get($settings, 'fqdn')) {
                        $url = Url::fromString($route);
                        $url = $url->withPort(null);
                        $fqdn = data_get($settings, 'fqdn');
                        $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                        $url = $url->withHost($fqdn);

                        $this->urls[$resource->name] = $url->__toString();
                    } else {
                        $this->urls[$resource->name] = $route;
                    }
                }
            }
        });
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('ssl_certificate_renewal');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: [Action Required] SSL Certificates Renewed - Manual Redeployment Needed');
        $mail->view('emails.ssl-certificate-renewed', [
            'resources' => $this->resources,
            'urls' => $this->urls,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $resourceNames = $this->resources->pluck('name')->join(', ');

        $message = new DiscordMessage(
            title: '🔒 SSL Certificates Renewed',
            description: "SSL certificates have been renewed for: {$resourceNames}.\n\n**Action Required:** These resources need to be redeployed manually.",
            color: DiscordMessage::warningColor(),
        );

        foreach ($this->urls as $name => $url) {
            $message->addField($name, "[View Resource]({$url})");
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $resourceNames = $this->resources->pluck('name')->join(', ');
        $message = "Coolify: SSL certificates have been renewed for: {$resourceNames}.\n\nAction Required: These resources need to be redeployed manually for the new SSL certificates to take effect.";

        $buttons = [];
        foreach ($this->urls as $name => $url) {
            $buttons[] = [
                'text' => "View {$name}",
                'url' => $url,
            ];
        }

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $resourceNames = $this->resources->pluck('name')->join(', ');
        $message = "SSL certificates have been renewed for: {$resourceNames}<br/><br/>";
        $message .= '<b>Action Required:</b> These resources need to be redeployed manually for the new SSL certificates to take effect.';

        $buttons = [];
        foreach ($this->urls as $name => $url) {
            $buttons[] = [
                'text' => "View {$name}",
                'url' => $url,
            ];
        }

        return new PushoverMessage(
            title: 'SSL Certificates Renewed',
            level: 'warning',
            message: $message,
            buttons: $buttons,
        );
    }

    public function toSlack(): SlackMessage
    {
        $resourceNames = $this->resources->pluck('name')->join(', ');
        $description = "SSL certificates have been renewed for: {$resourceNames}\n\n";
        $description .= '**Action Required:** These resources need to be redeployed manually for the new SSL certificates to take effect.';

        if (! empty($this->urls)) {
            $description .= "\n\n**Resource URLs:**\n";
            foreach ($this->urls as $name => $url) {
                $description .= "• {$name}: {$url}\n";
            }
        }

        return new SlackMessage(
            title: '🔒 SSL Certificates Renewed',
            description: $description,
            color: SlackMessage::warningColor()
        );
    }
}
