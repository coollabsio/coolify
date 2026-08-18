<?php

namespace App\Console\Commands\Cloud;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExportUsers extends Command
{
    protected $signature = 'cloud:export-users';

    protected $description = 'Export subscribed and unsubscribed verified Coolify Cloud users to separate CSV files';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return self::FAILURE;
        }

        $backups = Storage::disk('backups');
        $backups->delete('cloud-users.csv');

        $subscribedPath = $backups->path('cloud-users-subscribed.csv');
        $unsubscribedPath = $backups->path('cloud-users-unsubscribed.csv');
        $subscribedOutput = fopen($subscribedPath, 'wb');

        if ($subscribedOutput === false) {
            $this->error("Unable to open {$subscribedPath} for writing.");

            return self::FAILURE;
        }

        $unsubscribedOutput = fopen($unsubscribedPath, 'wb');

        if ($unsubscribedOutput === false) {
            fclose($subscribedOutput);
            $this->error("Unable to open {$unsubscribedPath} for writing.");

            return self::FAILURE;
        }

        $subscribedCount = 0;
        $unsubscribedCount = 0;

        try {
            $header = [
                'email',
                'first_name',
                'last_name',
                'lifetime_value_currency',
                'lifetime_value_amount',
                'utm_campaign',
                'utm_source',
                'utm_medium',
                'utm_content',
                'utm_term',
                'phone',
            ];

            $this->writeCsvRow($subscribedOutput, $header);
            $this->writeCsvRow($unsubscribedOutput, $header);

            foreach (User::query()
                ->select(['id', 'email', 'name'])
                ->where('id', '!=', 0)
                ->whereNotNull('email_verified_at')
                ->withExists([
                    'teams as is_subscribed' => fn ($query) => $query
                        ->whereRelation('subscription', 'stripe_invoice_paid', true),
                ])
                ->lazyById(500) as $user) {
                $nameParts = preg_split('/\s+/u', trim((string) $user->name), 2) ?: [];
                [$firstName, $lastName] = array_pad($nameParts, 2, '');

                $row = [
                    $user->email,
                    $firstName,
                    $lastName,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ];

                if ($user->is_subscribed) {
                    $this->writeCsvRow($subscribedOutput, $row);
                    $subscribedCount++;
                } else {
                    $this->writeCsvRow($unsubscribedOutput, $row);
                    $unsubscribedCount++;
                }
            }
        } catch (Throwable $exception) {
            $this->error("Unable to export users: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            fclose($subscribedOutput);
            fclose($unsubscribedOutput);
        }

        $this->info("Exported {$subscribedCount} subscribed verified users to {$subscribedPath}");
        $this->info("Exported {$unsubscribedCount} unsubscribed verified users to {$unsubscribedPath}");

        return self::SUCCESS;
    }

    /**
     * @param  resource  $output
     * @param  array<int, mixed>  $fields
     */
    private function writeCsvRow($output, array $fields): void
    {
        if (fputcsv($output, $fields, ',', '"', '') === false) {
            throw new RuntimeException('Unable to write the CSV file.');
        }
    }
}
