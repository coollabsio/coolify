<?php

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Guzzle middleware that sends a request again once after a connection failure.
 * cURL counts the DNS lookup in the connect timeout, and a lost DNS query waits 5 seconds before the resolver tries again.
 * Only idempotent methods are retried, because a POST or PATCH can reach the server before the timeout.
 */
class RetryHttpConnectionFailures
{
    private const array RETRIED_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            return $this->send($handler, $request, $options)->otherwise(function (Throwable $exception) use ($handler, $request, $options): PromiseInterface {
                if (! $exception instanceof ConnectException || ! in_array($request->getMethod(), self::RETRIED_METHODS, true)) {
                    return Create::rejectionFor($exception);
                }

                Sleep::usleep(500_000);

                return $this->send($handler, $request, $options);
            });
        };
    }

    /**
     * The cURL handler rejects the promise on a connection failure, but other handlers (such as Http::fake()) throw.
     */
    private function send(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        try {
            return Create::promiseFor($handler($request, $options));
        } catch (ConnectException $exception) {
            return Create::rejectionFor($exception);
        }
    }
}
