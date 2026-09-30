# xproapi-php

PHP client for the [Xproapi](https://xproapi.com/docs) X (Twitter) data API. PHP 8.1+, `ext-curl` and `ext-json`, no other dependencies.

## Install

With Composer, from this folder (a path repository):

```json
{
    "repositories": [{ "type": "path", "url": "../xproapi-code-examples/xproapi-php" }],
    "require": { "xproapi/xproapi-php": "*" }
}
```

Without Composer, copy the folder and `require 'xproapi-php/autoload.php';`.

## Usage

```php
use Xproapi\Client;

$x = new Client(getenv('XPROAPI_KEY'));

$user = $x->userInfo('jack')['data'];
echo "{$user['name']} has {$user['followers']} followers\n";

// One page (up to 200)
$page = $x->followers('jack', pageSize: 200);
foreach ($page['followers'] as $follower) { /* ... */ }
$next = $x->followers('jack', cursor: $page['next_cursor']);

// Every page, capped at 5 requests
foreach ($x->paginate('followers', ['userName' => 'jack'], maxPages: 5) as $follower) {
    echo $follower['userName'], "\n";
}

// Tweets
$x->lastTweets(['userName' => 'jack', 'includeReplies' => false]);
$x->advancedSearch('from:jack min_faves:100', queryType: 'Top');
$x->tweetsByIds(['20', '1800000000000000000']);
$x->replies('https://x.com/jack/status/20');   // ids or links
```

Methods return the API's JSON as arrays. The response keys are listed in the [root README](../README.md#endpoints).

### Options

```php
new Client(
    apiKey: 'xp_...',
    baseUrl: 'https://xproapi.com/api', // default
    timeout: 30,                        // seconds per request
    maxRetries: 2,                      // for 429 / 502 / 503 / network errors; 0 = off
);
```

### Errors

```php
use Xproapi\{InsufficientCreditsException, NotFoundException, RateLimitException, XproapiException};

try {
    $x->userInfo('someone');
} catch (NotFoundException) {
    // no such user
} catch (InsufficientCreditsException $e) {
    // top up; nothing was charged
} catch (RateLimitException $e) {
    sleep($e->retryAfter);
} catch (XproapiException $e) {
    error_log("Xproapi {$e->status}: {$e->getMessage()}");
}
```

### Rate limit

```php
$x->rateLimit(); // ['limit' => 60, 'remaining' => 57] from the last response
```

### Anything else

`$x->get('/twitter/user/info', ['userName' => 'jack'])` calls any GET endpoint with the same auth, retries and errors.

## Tests

```bash
php tests/run.php
```
