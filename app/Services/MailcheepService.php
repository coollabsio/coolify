<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MailcheepService
{
    private string $apiKey;

    private string $baseUrl = 'https://api.mailcheep.cloud/v1';

    public function __construct()
    {
        $this->apiKey = config('subscription.mailcheep_api_key');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findContactByEmail(string $email): ?array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/contacts", [
                'search' => $email,
            ]);

            if ($response->successful()) {
                $contacts = $response->json('data', []);
                foreach ($contacts as $contact) {
                    if (data_get($contact, 'email') === $email) {
                        return $contact;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Mailcheep: failed to find contact', ['email' => $email, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $customFields
     * @return array<string, mixed>|null
     */
    public function createContact(string $email, string $name, string $listId, array $customFields = []): ?array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/contacts", [
                'email' => $email,
                'name' => $name,
                'list_id' => $listId,
                'custom_fields' => $customFields,
            ]);

            if ($response->successful()) {
                return $response->json('data');
            }

            Log::warning('Mailcheep: failed to create contact', ['email' => $email, 'status' => $response->status()]);

            return null;
        } catch (\Exception $e) {
            Log::error('Mailcheep: failed to create contact', ['email' => $email, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function updateContact(string $id, array $data): ?array
    {
        try {
            $response = $this->client()->put("{$this->baseUrl}/contacts/{$id}", $data);

            if ($response->successful()) {
                return $response->json('data');
            }

            Log::warning('Mailcheep: failed to update contact', ['id' => $id, 'status' => $response->status()]);

            return null;
        } catch (\Exception $e) {
            Log::error('Mailcheep: failed to update contact', ['id' => $id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $customFields
     * @return array<string, mixed>|null
     */
    public function createOrUpdateContact(string $email, string $name, string $listId, array $customFields = []): ?array
    {
        $existing = $this->findContactByEmail($email);

        if ($existing) {
            return $this->updateContact(data_get($existing, 'id'), [
                'name' => $name,
                'list_id' => $listId,
                'custom_fields' => $customFields,
            ]);
        }

        return $this->createContact($email, $name, $listId, $customFields);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function addToChurnedList(string $email): ?array
    {
        $existing = $this->findContactByEmail($email);

        if ($existing) {
            return $this->updateContact(data_get($existing, 'id'), [
                'list_id' => config('subscription.mailcheep_list_churned'),
            ]);
        }

        return null;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
        ])->timeout(15)->retry(2, 100);
    }
}
