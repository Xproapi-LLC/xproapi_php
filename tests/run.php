<?php

/*
 * Offline tests for the PHP client: a fake transport stands in for the API. Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__.'/../autoload.php';

use Xproapi\AuthenticationException;
use Xproapi\Client;
use Xproapi\InsufficientCreditsException;
use Xproapi\NotFoundException;
use Xproapi\RateLimitException;
use Xproapi\XproapiException;

$failures = 0;
$count = 0;

function test(string $name, Closure $body): void
{
    global $failures, $count;
    $count++;

    try {
        $body();
        echo "  ✓ {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  ✗ {$name}\n    ".get_class($e).': '.$e->getMessage()."\n";
    }
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * A client whose transport answers from $responses in order and records each request.
 *
 * @param  list<array{0: int, 1: array<string, mixed>, 2?: array<string, string>}>  $responses  [status, body, headers]
 * @param  list<array{url: string, headers: array<string, string>}>  $requests
 */
function fake(array $responses, ?array &$requests = null, int $maxRetries = 2, ?array &$slept = null): Client
{
    $requests = [];
    $slept = [];

    return new Client(
        'xp_test_key',
        baseUrl: 'https://api.test/api/',
        maxRetries: $maxRetries,
        transport: function (string $url, array $headers) use (&$responses, &$requests) {
            $requests[] = ['url' => $url, 'headers' => $headers];
            [$status, $body, $responseHeaders] = array_shift($responses) + [2 => []];

            return ['status' => $status, 'headers' => $responseHeaders, 'body' => json_encode($body)];
        },
        sleep: function (int $microseconds) use (&$slept) {
            $slept[] = $microseconds;
        },
    );
}

echo "xproapi-php\n";

test('sends the API key and builds the query, leaving out null parameters', function () {
    $x = fake([[200, ['followers' => [], 'has_next_page' => false, 'next_cursor' => '', 'status' => 'success']]], $requests);

    $x->followers('jack', pageSize: 50);

    check($requests[0]['url'] === 'https://api.test/api/twitter/user/followers?userName=jack&pageSize=50', 'url: '.$requests[0]['url']);
    check($requests[0]['headers']['X-API-Key'] === 'xp_test_key', 'missing X-API-Key');
});

test('sends booleans as true / false and joins id lists', function () {
    $x = fake([[200, ['tweets' => []]], [200, ['tweets' => []]]], $requests);

    $x->quotes('123', includeReplies: false);
    $x->tweetsByIds(['1', '2', '3']);

    check(str_contains($requests[0]['url'], 'includeReplies=false'), 'url: '.$requests[0]['url']);
    check(str_contains($requests[1]['url'], 'tweet_ids=1%2C2%2C3'), 'url: '.$requests[1]['url']);
});

test('returns the decoded body and remembers the rate limit headers', function () {
    $x = fake([[200, ['data' => ['userName' => 'jack'], 'status' => 'success'], ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59']]]);

    check($x->userInfo('jack')['data']['userName'] === 'jack', 'wrong body');
    check($x->rateLimit() === ['limit' => 60, 'remaining' => 59], 'rate limit: '.json_encode($x->rateLimit()));
});

test('maps error statuses to exception classes with the API message', function () {
    foreach ([401 => AuthenticationException::class, 402 => InsufficientCreditsException::class, 404 => NotFoundException::class] as $status => $class) {
        $x = fake([[$status, ['status' => 'error', 'error' => $status, 'message' => "Nope {$status}"]]]);

        try {
            $x->userInfo('jack');
            throw new RuntimeException("{$status} did not throw");
        } catch (XproapiException $e) {
            check($e instanceof $class, "{$status} threw ".get_class($e));
            check($e->getMessage() === "Nope {$status}" && $e->status === $status, 'message/status: '.$e->getMessage());
        }
    }
});

test('retries a 429 after Retry-After, then succeeds', function () {
    $x = fake([
        [429, ['status' => 'error', 'error' => 429, 'message' => 'Slow down'], ['Retry-After' => '3']],
        [200, ['data' => ['userName' => 'jack']]],
    ], $requests, slept: $slept);

    check($x->userInfo('jack')['data']['userName'] === 'jack', 'wrong body');
    check(count($requests) === 2 && $slept === [3_000_000], 'slept: '.json_encode($slept));
});

test('gives up after maxRetries and throws RateLimitException with retryAfter', function () {
    $limited = [429, ['status' => 'error', 'error' => 429, 'message' => 'Slow down'], ['Retry-After' => '5']];
    $x = fake([$limited, $limited], $requests, maxRetries: 1);

    try {
        $x->userInfo('jack');
        throw new RuntimeException('did not throw');
    } catch (RateLimitException $e) {
        check($e->retryAfter === 5 && count($requests) === 2, 'retryAfter / attempts');
    }
});

test('does not retry client errors', function () {
    $x = fake([[400, ['status' => 'error', 'error' => 400, 'message' => 'userName is required.']]], $requests);

    try {
        $x->get('/twitter/user/info');
    } catch (XproapiException) {
    }

    check(count($requests) === 1, 'retried a 400');
});

test('paginate() follows next_cursor across pages and stops at maxPages', function () {
    $page = fn (array $ids, bool $more, string $cursor) => [200, ['followers' => array_map(fn ($id) => ['id' => $id], $ids), 'has_next_page' => $more, 'next_cursor' => $cursor]];
    $x = fake([$page(['1', '2'], true, 'c1'), $page(['3'], true, 'c2'), $page(['4'], false, '')], $requests);

    $all = array_column(iterator_to_array($x->paginate('followers', ['userName' => 'jack']), false), 'id');
    check($all === ['1', '2', '3', '4'], 'items: '.json_encode($all));
    check(str_contains($requests[1]['url'], 'cursor=c1') && str_contains($requests[2]['url'], 'cursor=c2'), 'cursor not sent');

    $x = fake([$page(['1'], true, 'c1'), $page(['2'], true, 'c2')], $requests);
    $limited = iterator_to_array($x->paginate('followers', ['userName' => 'jack'], maxPages: 1), false);
    check(count($limited) === 1 && count($requests) === 1, 'maxPages ignored');
});

test('rejects an empty key and unknown paginated endpoints', function () {
    try {
        new Client('');
        throw new RuntimeException('empty key accepted');
    } catch (InvalidArgumentException) {
    }

    try {
        iterator_to_array(fake([])->paginate('userInfo'));
        throw new RuntimeException('userInfo accepted');
    } catch (InvalidArgumentException) {
    }
});

echo $failures === 0 ? "\n{$count} passed\n" : "\n{$failures} of {$count} failed\n";
exit($failures === 0 ? 0 : 1);
