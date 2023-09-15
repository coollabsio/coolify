<?php

use App\Jobs\SubscriptionTrialEndedJob;
use App\Jobs\SubscriptionTrialEndsSoonJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\Waitlist;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

Route::get('/source/github/redirect', function () {
    try {
        $code = request()->get('code');
        $state = request()->get('state');
        $github_app = GithubApp::where('uuid', $state)->firstOrFail();
        $api_url = data_get($github_app, 'api_url');
        $data = Http::withBody(null)->accept('application/vnd.github+json')->post("$api_url/app-manifests/$code/conversions")->throw()->json();
        $id = data_get($data, 'id');
        $slug = data_get($data, 'slug');
        $client_id = data_get($data, 'client_id');
        $client_secret = data_get($data, 'client_secret');
        $private_key = data_get($data, 'pem');
        $webhook_secret = data_get($data, 'webhook_secret');
        $private_key = PrivateKey::create([
            'name' => $slug,
            'private_key' => $private_key,
            'team_id' => $github_app->team_id,
            'is_git_related' => true,
        ]);
        $github_app->name = $slug;
        $github_app->app_id = $id;
        $github_app->client_id = $client_id;
        $github_app->client_secret = $client_secret;
        $github_app->webhook_secret = $webhook_secret;
        $github_app->private_key_id = $private_key->id;
        $github_app->save();
        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    } catch (Exception $e) {
        return handleError($e);
    }
});

Route::get('/source/github/install', function () {
    try {
        $installation_id = request()->get('installation_id');
        $source = request()->get('source');
        $setup_action = request()->get('setup_action');
        $github_app = GithubApp::where('uuid', $source)->firstOrFail();
        if ($setup_action === 'install') {
            $github_app->installation_id = $installation_id;
            $github_app->save();
        }
        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    } catch (Exception $e) {
        return handleError($e);
    }
});
Route::post('/source/github/events', function () {
    try {
        $x_github_delivery = request()->header('X-GitHub-Delivery');
        $x_github_event = Str::lower(request()->header('X-GitHub-Event'));
        $x_github_hook_installation_target_id = request()->header('X-GitHub-Hook-Installation-Target-Id');
        $x_hub_signature_256 = Str::after(request()->header('X-Hub-Signature-256'), 'sha256=');
        $payload = request()->collect();
        if ($x_github_event === 'ping') {
            // Just pong
            return response('pong');
        }
        if ($x_github_event === 'installation') {
            // Installation handled by setup redirect url. Repositories queried on-demand.
            return response('cool');
        }
        $github_app = GithubApp::where('app_id', $x_github_hook_installation_target_id)->firstOrFail();

        $webhook_secret = data_get($github_app, 'webhook_secret');
        $hmac = hash_hmac('sha256', request()->getContent(), $webhook_secret);
        ray($hmac, $x_hub_signature_256)->blue();
        if (config('app.env') !== 'local') {
            if (!hash_equals($x_hub_signature_256, $hmac)) {
                return response('not cool');
            }
        }

        if ($x_github_event === 'push') {
            $id = data_get($payload, 'repository.id');
            $branch = data_get($payload, 'ref');
            if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                $branch = Str::after($branch, 'refs/heads/');
            }
            ray('Webhook GitHub Push Event: ' . $id . ' with branch: ' . $branch);
        }
        if ($x_github_event === 'pull_request') {
            $action = data_get($payload, 'action');
            $id = data_get($payload, 'repository.id');
            $pull_request_id = data_get($payload, 'number');
            $pull_request_html_url = data_get($payload, 'pull_request.html_url');
            $branch = data_get($payload, 'pull_request.head.ref');
            $base_branch = data_get($payload, 'pull_request.base.ref');
            ray('Webhook GitHub Pull Request Event: ' . $id . ' with branch: ' . $branch . ' and base branch: ' . $base_branch . ' and pull request id: ' . $pull_request_id);
        }
        if (!$id || !$branch) {
            return response('Nothing to do. No id or branch found.');
        }
        $applications = Application::where('repository_project_id', $id)->whereRelation('source', 'is_public', false);
        if ($x_github_event === 'push') {
            $applications = $applications->where('git_branch', $branch)->get();
        }
        if ($x_github_event === 'pull_request') {
            $applications = $applications->where('git_branch', $base_branch)->get();
        }
        if ($applications->isEmpty()) {
            return response("Nothing to do. No applications found with branch '$base_branch'.");
        }
        foreach ($applications as $application) {
            $isFunctional = $application->destination->server->isFunctional();
            if (!$isFunctional) {
                ray('Server is not functional: ' . $application->destination->server->name);
                continue;
            }
            if ($x_github_event === 'push') {
                if ($application->isDeployable()) {
                    ray('Deploying ' . $application->name . ' with branch ' . $branch);
                    $deployment_uuid = new Cuid2(7);
                    queue_application_deployment(
                        application_id: $application->id,
                        deployment_uuid: $deployment_uuid,
                        force_rebuild: false,
                        is_webhook: true
                    );
                } else {
                    ray('Deployments disabled for ' . $application->name);
                }
            }
            if ($x_github_event === 'pull_request') {
                if ($action === 'opened' || $action === 'synchronize' || $action === 'reopened') {
                    if ($application->isPRDeployable()) {
                        $deployment_uuid = new Cuid2(7);
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if (!$found) {
                            ApplicationPreview::create([
                                'application_id' => $application->id,
                                'pull_request_id' => $pull_request_id,
                                'pull_request_html_url' => $pull_request_html_url,
                            ]);
                        }
                        queue_application_deployment(
                            application_id: $application->id,
                            pull_request_id: $pull_request_id,
                            deployment_uuid: $deployment_uuid,
                            force_rebuild: false,
                            is_webhook: true
                        );
                        ray('Deploying preview for ' . $application->name . ' with branch ' . $branch . ' and base branch ' . $base_branch . ' and pull request id ' . $pull_request_id);
                        return response('Preview Deployment queued.');
                    } else {
                        ray('Preview deployments disabled for ' . $application->name);
                        return response('Nothing to do. Preview Deployments disabled.');
                    }
                }
                if ($action === 'closed') {
                    $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                    if ($found) {
                        $found->delete();
                        $container_name = generateApplicationContainerName($application->uuid, $pull_request_id);
                        ray('Stopping container: ' . $container_name);
                        remote_process(["docker rm -f $container_name"], $application->destination->server);
                        return response('Preview Deployment closed.');
                    }
                    return response('Nothing to do. No Preview Deployment found');
                }
            }
        }
    } catch (Exception $e) {
        ray($e->getMessage());
        return handleError($e);
    }
});
Route::get('/waitlist/confirm', function () {
    $email = request()->get('email');
    $confirmation_code = request()->get('confirmation_code');
    ray($email, $confirmation_code);
    try {
        $found = Waitlist::where('uuid', $confirmation_code)->where('email', $email)->first();
        if ($found) {
            if (!$found->verified) {
                if ($found->created_at > now()->subMinutes(config('constants.waitlist.expiration'))) {
                    $found->verified = true;
                    $found->save();
                    send_internal_notification('Waitlist confirmed: ' . $email);
                    return 'Thank you for confirming your email address. We will notify you when you are next in line.';
                } else {
                    $found->delete();
                    send_internal_notification('Waitlist expired: ' . $email);
                    return 'Your confirmation code has expired. Please sign up again.';
                }
            }
        }
        return redirect()->route('dashboard');
    } catch (Exception $e) {
        send_internal_notification('Waitlist confirmation failed: ' . $e->getMessage());
        ray($e->getMessage());
        return redirect()->route('dashboard');
    }
})->name('webhooks.waitlist.confirm');
Route::get('/waitlist/cancel', function () {
    $email = request()->get('email');
    $confirmation_code = request()->get('confirmation_code');
    try {
        $found = Waitlist::where('uuid', $confirmation_code)->where('email', $email)->first();
        if ($found && !$found->verified) {
            $found->delete();
            send_internal_notification('Waitlist cancelled: ' . $email);
            return 'Your email address has been removed from the waitlist.';
        }
        return redirect()->route('dashboard');
    } catch (Exception $e) {
        send_internal_notification('Waitlist cancellation failed: ' . $e->getMessage());
        ray($e->getMessage());
        return redirect()->route('dashboard');
    }
})->name('webhooks.waitlist.cancel');


Route::post('/payments/stripe/events', function () {
    try {
        $webhookSecret = config('subscription.stripe_webhook_secret');
        $signature = request()->header('Stripe-Signature');

        $event = \Stripe\Webhook::constructEvent(
            request()->getContent(),
            $signature,
            $webhookSecret
        );
        $webhook = Webhook::create([
            'type' => 'stripe',
            'payload' => request()->getContent()
        ]);
        $type = data_get($event, 'type');
        $data = data_get($event, 'data.object');
        ray('Event: ' . $type);
        switch ($type) {
            case 'checkout.session.completed':
                $clientReferenceId = data_get($data, 'client_reference_id');
                $userId = Str::before($clientReferenceId, ':');
                $teamId = Str::after($clientReferenceId, ':');
                $subscriptionId = data_get($data, 'subscription');
                $customerId = data_get($data, 'customer');
                $team = Team::find($teamId);
                $found = $team->members->where('id', $userId)->first();
                if (!$found->isAdmin()) {
                    throw new Exception("User {$userId} is not an admin or owner of team {$team->id}.");
                }
                $subscription = Subscription::where('team_id', $teamId)->first();
                if ($subscription) {
                    send_internal_notification('Old subscription activated for team: ' . $teamId);
                    $subscription->update([
                        'stripe_subscription_id' => $subscriptionId,
                        'stripe_customer_id' => $customerId,
                        'stripe_invoice_paid' => true,
                    ]);
                } else {
                    send_internal_notification('New subscription for team: ' . $teamId);
                    Subscription::create([
                        'team_id' => $teamId,
                        'stripe_subscription_id' => $subscriptionId,
                        'stripe_customer_id' => $customerId,
                        'stripe_invoice_paid' => true,
                    ]);
                }
                break;
            case 'invoice.paid':
                $customerId = data_get($data, 'customer');
                $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                $planId = data_get($data, 'lines.data.0.plan.id');
                $subscription->update([
                    'stripe_plan_id' => $planId,
                    'stripe_invoice_paid' => true,
                ]);
                break;
            case 'customer.subscription.updated':
                $customerId = data_get($data, 'customer');
                $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                $trialEndedAlready = data_get($subscription, 'stripe_trial_already_ended');
                $status = data_get($data, 'status');
                $subscriptionId = data_get($data, 'items.data.0.subscription');
                $planId = data_get($data, 'items.data.0.plan.id');
                $cancelAtPeriodEnd = data_get($data, 'cancel_at_period_end');
                $alreadyCancelAtPeriodEnd = data_get($subscription, 'stripe_cancel_at_period_end');
                $feedback = data_get($data, 'cancellation_details.feedback');
                $comment = data_get($data, 'cancellation_details.comment');
                $subscription->update([
                    'stripe_feedback' => $feedback,
                    'stripe_comment' => $comment,
                    'stripe_plan_id' => $planId,
                    'stripe_cancel_at_period_end' => $cancelAtPeriodEnd,
                ]);
                if ($status === 'paused') {
                    $subscription->update([
                        'stripe_invoice_paid' => false,
                    ]);
                    send_internal_notification('Subscription paused for team: ' . $subscription->team->id);
                }

                // Trial ended but subscribed, reactive servers
                if ($trialEndedAlready && $status === 'active') {
                    $team = data_get($subscription, 'team');
                    $team->trialEndedButSubscribed();
                }

                if ($feedback) {
                    $reason = "Cancellation feedback for {$subscription->team->id}: '" . $feedback . "'";
                    if ($comment) {
                        $reason .= ' with comment: \'' . $comment . "'";
                    }
                    send_internal_notification($reason);
                }
                if ($alreadyCancelAtPeriodEnd !== $cancelAtPeriodEnd) {
                    if ($cancelAtPeriodEnd) {
                        send_internal_notification('Subscription cancelled at period end for team: ' . $subscription->team->id);
                    } else {
                        send_internal_notification('Subscription resumed for team: ' . $subscription->team->id);
                    }
                }
                break;
            case 'customer.subscription.deleted':
                // End subscription
                $customerId = data_get($data, 'customer');
                $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                $team = data_get($subscription, 'team');
                $team->trialEnded();
                $subscription->update([
                    'stripe_subscription_id' => null,
                    'stripe_plan_id' => null,
                    'stripe_cancel_at_period_end' => false,
                    'stripe_invoice_paid' => false,
                    'stripe_trial_already_ended' => true,
                ]);
                send_internal_notification('Subscription cancelled: ' . $subscription->team->id);
                break;
            case 'customer.subscription.trial_will_end':
                $customerId = data_get($data, 'customer');
                $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                $team = data_get($subscription, 'team');
                if (!$team) {
                    throw new Exception('No team found for subscription: ' . $subscription->id);
                }
                SubscriptionTrialEndsSoonJob::dispatch($team);
                break;
            case 'customer.subscription.paused':
                $customerId = data_get($data, 'customer');
                $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                $team = data_get($subscription, 'team');
                if (!$team) {
                    throw new Exception('No team found for subscription: ' . $subscription->id);
                }
                $team->trialEnded();
                $subscription->update([
                    'stripe_trial_already_ended' => true,
                    'stripe_invoice_paid' => false,
                ]);
                SubscriptionTrialEndedJob::dispatch($team);
                send_internal_notification('Subscription paused for team: ' . $subscription->team->id);
                break;
            default:
                // Unhandled event type
        }
    } catch (Exception $e) {
        send_internal_notification("Subscription webhook ($type) failed: " . $e->getMessage());
        $webhook->update([
            'status' => 'failed',
            'failure_reason' => $e->getMessage(),
        ]);
        return response($e->getMessage(), 400);
    }
});
Route::post('/payments/paddle/events', function () {
    try {
        $payload = request()->all();
        $signature = request()->header('Paddle-Signature');
        $ts = Str::of($signature)->after('ts=')->before(';');
        $h1 = Str::of($signature)->after('h1=');
        $signedPayload = $ts->value . ':' . request()->getContent();
        $verify = hash_hmac('sha256', $signedPayload, config('subscription.paddle_webhook_secret'));
        ray($verify, $h1->value, hash_equals($verify, $h1->value));
        if (!hash_equals($verify, $h1->value)) {
            return response('Invalid signature.', 400);
        }
        $eventType = data_get($payload, 'event_type');
        ray($eventType);
        $webhook = Webhook::create([
            'type' => 'paddle',
            'payload' => $payload,
        ]);
        // TODO - Handle events
        switch ($eventType) {
            case 'subscription.activated':
        }
        ray('Subscription event: ' . $eventType);
        $webhook->update([
            'status' => 'success',
        ]);
    } catch (Exception $e) {
        ray($e->getMessage());
        send_internal_notification('Subscription webhook failed: ' . $e->getMessage());
        $webhook->update([
            'status' => 'failed',
            'failure_reason' => $e->getMessage(),
        ]);
    } finally {
        return response('OK');
    }
});
Route::post('/payments/lemon/events', function () {
    try {
        $secret = config('subscription.lemon_squeezy_webhook_secret');
        $payload = request()->collect();
        $hash = hash_hmac('sha256', $payload, $secret);
        $signature = request()->header('X-Signature');

        if (!hash_equals($hash, $signature)) {
            return response('Invalid signature.', 400);
        }

        $webhook = Webhook::create([
            'type' => 'lemonsqueezy',
            'payload' => $payload,
        ]);
        $event = data_get($payload, 'meta.event_name');
        ray('Subscription event: ' . $event);
        $email = data_get($payload, 'data.attributes.user_email');
        $team_id = data_get($payload, 'meta.custom_data.team_id');
        if (is_null($team_id) || empty($team_id)) {
            throw new Exception('No team_id found in webhook payload.');
        }
        $subscription_id = data_get($payload, 'data.id');
        $order_id = data_get($payload, 'data.attributes.order_id');
        $product_id = data_get($payload, 'data.attributes.product_id');
        $variant_id = data_get($payload, 'data.attributes.variant_id');
        $variant_name = data_get($payload, 'data.attributes.variant_name');
        $customer_id = data_get($payload, 'data.attributes.customer_id');
        $status = data_get($payload, 'data.attributes.status');
        $trial_ends_at = data_get($payload, 'data.attributes.trial_ends_at');
        $renews_at = data_get($payload, 'data.attributes.renews_at');
        $ends_at = data_get($payload, 'data.attributes.ends_at');
        $update_payment_method = data_get($payload, 'data.attributes.urls.update_payment_method');
        $team = Team::find($team_id);
        $found = $team->members->where('email', $email)->first();
        if (!$found->isAdmin()) {
            throw new Exception("User {$email} is not an admin or owner of team {$team->id}.");
        }
        switch ($event) {
            case 'subscription_created':
            case 'subscription_updated':
            case 'subscription_resumed':
            case 'subscription_unpaused':
                send_internal_notification("LemonSqueezy Event (`$event`): `" . $email  . '` with status `' . $status . '`, tier: `' . $variant_name . '`');
                $subscription = Subscription::updateOrCreate([
                    'team_id' => $team_id,
                ], [
                    'lemon_subscription_id' => $subscription_id,
                    'lemon_customer_id' => $customer_id,
                    'lemon_order_id' => $order_id,
                    'lemon_product_id' => $product_id,
                    'lemon_variant_id' => $variant_id,
                    'lemon_status' => $status,
                    'lemon_variant_name' => $variant_name,
                    'lemon_trial_ends_at' => $trial_ends_at,
                    'lemon_renews_at' => $renews_at,
                    'lemon_ends_at' => $ends_at,
                    'lemon_update_payment_menthod_url' => $update_payment_method,
                ]);
                break;
            case 'subscription_cancelled':
            case 'subscription_paused':
            case 'subscription_expired':
                $subscription = Subscription::where('team_id', $team_id)->where('lemon_order_id', $order_id)->first();
                if ($subscription) {
                    send_internal_notification("LemonSqueezy Event (`$event`): " . $subscription_id . ' for team ' . $team_id . ' with status ' . $status);
                    $subscription->update([
                        'lemon_status' => $status,
                        'lemon_trial_ends_at' => $trial_ends_at,
                        'lemon_renews_at' => $renews_at,
                        'lemon_ends_at' => $ends_at,
                        'lemon_update_payment_menthod_url' => $update_payment_method,
                    ]);
                }
                break;
        }

        $webhook->update([
            'status' => 'success',
        ]);
    } catch (Exception $e) {
        ray($e->getMessage());
        send_internal_notification('Subscription webhook failed: ' . $e->getMessage());
        $webhook->update([
            'status' => 'failed',
            'failure_reason' => $e->getMessage(),
        ]);
    } finally {
        return response('OK');
    }
});
