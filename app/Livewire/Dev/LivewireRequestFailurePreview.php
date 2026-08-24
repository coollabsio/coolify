<?php

namespace App\Livewire\Dev;

use Illuminate\Http\Exceptions\HttpResponseException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

class LivewireRequestFailurePreview extends Component
{
    /**
     * @var list<int>
     */
    public array $statuses = [502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 527, 530];

    public function fail(int $status): never
    {
        abort_unless(in_array($status, $this->statuses, true), Response::HTTP_NOT_FOUND);

        throw new HttpResponseException(response(
            '<!doctype html><html><body><h1>Gateway time-out</h1><p>cloudflare proxy error '.$status.'</p></body></html>',
            $status,
            ['Content-Type' => 'text/html']
        ));
    }

    public function render(): mixed
    {
        return view('livewire.dev.livewire-request-failure-preview')->layout('layouts.simple');
    }
}
