<?php

declare(strict_types=1);

namespace Xproapi;

use Closure;
use Generator;
use InvalidArgumentException;

/**
 * Client for the Xproapi public API (https://xproapi.com/docs).
 *
 *     $x = new Xproapi\Client(getenv('XPROAPI_KEY'));
 *     $user = $x->userInfo('elonmusk')['data'];
 *     foreach ($x->paginate('followers', ['userName' => 'elonmusk'], maxPages: 3) as $follower) { ... }
 *
 * Every method returns the decoded JSON response as an array. Errors throw an XproapiException subclass.
 * Rate-limited (429) and temporarily unavailable (502/503) requests are retried automatically.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://xproapi.com/api';

    public const VERSION = '1.0.0';

    /** Endpoint name => [path, key of the list in the response], used by paginate(). */
    private const PAGINATED = [
        'searchUsers' => ['/twitter/user/search', 'users'],
        'followers' => ['/twitter/user/followers', 'followers'],
        'followings' => ['/twitter/user/followings', 'followings'],
        'lastTweets' => ['/twitter/user/last_tweets', 'tweets'],
        'tweetTimeline' => ['/twitter/user/tweet_timeline', 'tweets'],
        'mentions' => ['/twitter/user/mentions', 'tweets'],
        'advancedSearch' => ['/twitter/tweet/advanced_search', 'tweets'],
        'replies' => ['/twitter/tweet/replies', 'replies'],
        'repliesV2' => ['/twitter/tweet/replies/v2', 'tweets'],
        'quotes' => ['/twitter/tweet/quotes', 'tweets'],
        'retweeters' => ['/twitter/tweet/retweeters', 'users'],
        'threadContext' => ['/twitter/tweet/thread_context', 'replies'],
    ];

    private readonly string $baseUrl;

    /** @var Closure(string, array<string, string>, int): array{status: int, headers: array<string, string>, body: string} */
    private readonly Closure $transport;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var array{limit: int|null, remaining: int|null} rate limit headers of the last response */
    private array $rateLimit = ['limit' => null, 'remaining' => null];

    /**
     * @param  string  $apiKey  from your panel → API → API keys
     * @param  string|null  $baseUrl  defaults to https://xproapi.com/api
     * @param  int  $timeout  seconds per request
     * @param  int  $maxRetries  retries for 429 / 502 / 503 / network errors (0 = never retry)
     * @param  (Closure(string, array<string, string>, int): array{status: int, headers: array<string, string>, body: string})|null  $transport  replaces the HTTP call (for tests)
     * @param  (Closure(int): void)|null  $sleep  waits the given microseconds between retries (for tests)
     */
    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 2,
        ?Closure $transport = null,
        ?Closure $sleep = null,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('An API key is required. Create one in your panel under API → API keys.');
        }

        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->transport = $transport ?? Closure::fromCallable([$this, 'curl']);
        $this->sleep = $sleep ?? fn (int $microseconds) => usleep($microseconds);
    }

    // ---- Users ------------------------------------------------------------------------------

    /** Profile of one user. Response: {data: User}. */
    public function userInfo(string $userName): array
    {
        return $this->get('/twitter/user/info', ['userName' => $userName]);
    }

    /**
     * Profiles of up to 100 users in one call (cheaper per profile). Response: {users: User[]}.
     *
     * @param  list<string>  $userNames
     */
    public function batchUserInfoByUsernames(array $userNames): array
    {
        return $this->get('/twitter/user/batch_info_by_usernames', ['userNames' => implode(',', $userNames)]);
    }

    /** "About this account": country, join date, username changes... Response: {data: {...}}. */
    public function userAbout(string $userName): array
    {
        return $this->get('/twitter/user_about', ['userName' => $userName]);
    }

    /** People search. Response: {users: User[], has_next_page, next_cursor}. */
    public function searchUsers(string $query, ?string $cursor = null): array
    {
        return $this->get('/twitter/user/search', ['query' => $query, 'cursor' => $cursor]);
    }

    /**
     * Followers, newest first. pageSize 20–200 (default 200). Response: {followers: User[], has_next_page, next_cursor}.
     */
    public function followers(string $userName, ?string $cursor = null, ?int $pageSize = null): array
    {
        return $this->get('/twitter/user/followers', ['userName' => $userName, 'cursor' => $cursor, 'pageSize' => $pageSize]);
    }

    /** Accounts the user follows, newest first. Response: {followings: User[], has_next_page, next_cursor}. */
    public function followings(string $userName, ?string $cursor = null, ?int $pageSize = null): array
    {
        return $this->get('/twitter/user/followings', ['userName' => $userName, 'cursor' => $cursor, 'pageSize' => $pageSize]);
    }

    /**
     * The user's tweets and retweets, newest first. Pass userName or userId.
     *
     * @param  array{userName?: string, userId?: string, includeReplies?: bool, cursor?: string}  $params
     */
    public function lastTweets(array $params): array
    {
        return $this->get('/twitter/user/last_tweets', $params);
    }

    /**
     * Like lastTweets; with includeReplies + includeParentTweet the tweets being replied to are kept too.
     *
     * @param  array{userName?: string, userId?: string, includeReplies?: bool, includeParentTweet?: bool, cursor?: string}  $params
     */
    public function tweetTimeline(array $params): array
    {
        return $this->get('/twitter/user/tweet_timeline', $params);
    }

    /**
     * Tweets by others that mention the user. sinceTime / untilTime are unix seconds.
     */
    public function mentions(string $userName, ?int $sinceTime = null, ?int $untilTime = null, ?string $cursor = null): array
    {
        return $this->get('/twitter/user/mentions', ['userName' => $userName, 'sinceTime' => $sinceTime, 'untilTime' => $untilTime, 'cursor' => $cursor]);
    }

    // ---- Tweets -----------------------------------------------------------------------------

    /**
     * Up to 100 tweets by id. Response: {tweets: Tweet[]}.
     *
     * @param  list<string>  $tweetIds
     */
    public function tweetsByIds(array $tweetIds): array
    {
        return $this->get('/twitter/tweets', ['tweet_ids' => implode(',', $tweetIds)]);
    }

    /**
     * X search with its operators (from:, since:, min_faves:, lang:, filter:...). queryType: Latest or Top.
     */
    public function advancedSearch(string $query, string $queryType = 'Latest', ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/advanced_search', ['query' => $query, 'queryType' => $queryType, 'cursor' => $cursor]);
    }

    /** Replies in a tweet's conversation, newest first. Response: {replies: Tweet[], ...}. */
    public function replies(string $tweetId, ?int $sinceTime = null, ?int $untilTime = null, ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/replies', ['tweetId' => $tweetId, 'sinceTime' => $sinceTime, 'untilTime' => $untilTime, 'cursor' => $cursor]);
    }

    /** Replies ranked like X shows them. queryType: Relevance, Latest or Likes. Response: {tweets: Tweet[], ...}. */
    public function repliesV2(string $tweetId, string $queryType = 'Relevance', ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/replies/v2', ['tweetId' => $tweetId, 'queryType' => $queryType, 'cursor' => $cursor]);
    }

    /** Quote tweets of a tweet. */
    public function quotes(string $tweetId, ?int $sinceTime = null, ?int $untilTime = null, bool $includeReplies = true, ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/quotes', ['tweetId' => $tweetId, 'sinceTime' => $sinceTime, 'untilTime' => $untilTime, 'includeReplies' => $includeReplies, 'cursor' => $cursor]);
    }

    /** Users who retweeted a tweet. Response: {users: User[], ...}. */
    public function retweeters(string $tweetId, ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/retweeters', ['tweetId' => $tweetId, 'cursor' => $cursor]);
    }

    /** The whole conversation around a tweet, in reading order. Response: {replies: Tweet[], ...}. */
    public function threadContext(string $tweetId, ?string $cursor = null): array
    {
        return $this->get('/twitter/tweet/thread_context', ['tweetId' => $tweetId, 'cursor' => $cursor]);
    }

    /** The long-form article attached to a tweet. Response: {article: {...}}. */
    public function article(string $tweetId): array
    {
        return $this->get('/twitter/article', ['tweet_id' => $tweetId]);
    }

    // ---- Pagination, raw requests, rate limits ----------------------------------------------

    /**
     * Every item of a paginated endpoint, page after page, following next_cursor.
     * Each page is a separate (charged) request; stop early with maxPages or by breaking out of the loop.
     *
     *     foreach ($x->paginate('followers', ['userName' => 'jack'], maxPages: 5) as $user) { ... }
     *
     * @param  string  $endpoint  one of: searchUsers, followers, followings, lastTweets, tweetTimeline, mentions,
     *                            advancedSearch, replies, repliesV2, quotes, retweeters, threadContext
     * @param  array<string, scalar|null>  $params  the endpoint's query parameters (without cursor)
     * @return Generator<int, array<string, mixed>>
     */
    public function paginate(string $endpoint, array $params = [], ?int $maxPages = null): Generator
    {
        [$path, $key] = self::PAGINATED[$endpoint] ?? throw new InvalidArgumentException(
            "{$endpoint} isn't paginated. Use one of: ".implode(', ', array_keys(self::PAGINATED)).'.'
        );

        $cursor = null;
        $pages = 0;

        do {
            $page = $this->get($path, [...$params, 'cursor' => $cursor]);
            $pages++;

            yield from $page[$key] ?? [];

            $cursor = ($page['has_next_page'] ?? false) ? (string) ($page['next_cursor'] ?? '') : '';
        } while ($cursor !== '' && ($maxPages === null || $pages < $maxPages));
    }

    /**
     * Any GET endpoint under the base URL, e.g. get('/twitter/user/info', ['userName' => 'jack']).
     * Null parameters are left out; booleans are sent as "true" / "false".
     *
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array
    {
        $query = [];

        foreach ($params as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $url = $this->baseUrl.'/'.ltrim($path, '/').($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $headers = ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json', 'User-Agent' => 'xproapi-php/'.self::VERSION];

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = ($this->transport)($url, $headers, $this->timeout);
            } catch (XproapiException $e) {
                if ($attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoff($attempt, null));

                    continue;
                }

                throw $e;
            }

            $responseHeaders = array_change_key_case($response['headers'], CASE_LOWER);
            $this->rateLimit = [
                'limit' => isset($responseHeaders['x-ratelimit-limit']) ? (int) $responseHeaders['x-ratelimit-limit'] : null,
                'remaining' => isset($responseHeaders['x-ratelimit-remaining']) ? (int) $responseHeaders['x-ratelimit-remaining'] : null,
            ];

            $status = $response['status'];
            $body = json_decode($response['body'], true);
            $body = is_array($body) ? $body : null;

            if ($status >= 200 && $status < 300 && $body !== null) {
                return $body;
            }

            $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;

            if (in_array($status, [429, 502, 503], true) && $attempt < $this->maxRetries) {
                ($this->sleep)($this->backoff($attempt, $retryAfter));

                continue;
            }

            throw XproapiException::fromResponse($status, $body, $retryAfter);
        }
    }

    /**
     * Rate limit of the last response: your per-minute limit and requests left this minute.
     *
     * @return array{limit: int|null, remaining: int|null}
     */
    public function rateLimit(): array
    {
        return $this->rateLimit;
    }

    /**
     * Microseconds to wait before a retry: Retry-After when the API sends it (capped at 60s), else 1s, 2s, 4s…
     */
    private function backoff(int $attempt, ?int $retryAfter): int
    {
        $seconds = $retryAfter !== null ? min(60, max(1, $retryAfter)) : 2 ** $attempt;

        return $seconds * 1_000_000;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $url, array $headers, int $timeout): array
    {
        $responseHeaders = [];
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => array_map(fn (string $name, string $value) => "{$name}: {$value}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);

        if ($body === false) {
            throw new XproapiException('Network error: '.curl_error($handle));
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $responseHeaders, 'body' => (string) $body];
    }
}
