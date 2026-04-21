<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Token-bucket rate limiter backed by the application cache.
 *
 * Settings (per TowMate login policy):
 *   - Maximum capacity : 10 tokens
 *   - Refill rate      : 5 tokens every 10 seconds
 *   - Cost per request : 5 tokens
 *
 * A user starts with a full bucket (10 tokens), so they can make 2
 * rapid attempts before the bucket empties.  Tokens refill at 0.5/s,
 * so they recover one extra attempt roughly every 10 seconds.
 */
class TokenBucketRateLimiter
{
    public function __construct(
        private readonly int $maxTokens     = 10,
        private readonly int $refillAmount  = 5,
        private readonly int $refillEvery   = 10,   // seconds
        private readonly int $tokenCost     = 5,
        private readonly int $ttl           = 600,  // cache TTL (seconds)
    ) {}

    /**
     * Attempt to consume tokens for the given key.
     *
     * @return bool  true = allowed, false = throttled
     */
    public function attempt(string $key): bool
    {
        $cacheKey = 'token_bucket:' . $key;
        $now      = microtime(true);

        $bucket = Cache::get($cacheKey);

        if ($bucket === null) {
            // First request – start with a full bucket.
            $bucket = ['tokens' => $this->maxTokens, 'last_refill' => $now];
        }

        // Refill based on elapsed time.
        $elapsed       = max(0.0, $now - (float) $bucket['last_refill']);
        $intervals     = (int) floor($elapsed / $this->refillEvery);
        $tokensToAdd   = $intervals * $this->refillAmount;

        if ($tokensToAdd > 0) {
            $bucket['tokens']      = min($this->maxTokens, (int) $bucket['tokens'] + $tokensToAdd);
            $bucket['last_refill'] = $bucket['last_refill'] + ($intervals * $this->refillEvery);
        }

        if ((int) $bucket['tokens'] < $this->tokenCost) {
            // Not enough tokens – persist current state and deny.
            Cache::put($cacheKey, $bucket, $this->ttl);

            return false;
        }

        $bucket['tokens'] -= $this->tokenCost;
        Cache::put($cacheKey, $bucket, $this->ttl);

        return true;
    }

    /**
     * Seconds until the bucket has enough tokens for one more request.
     */
    public function retryAfter(string $key): int
    {
        $cacheKey = 'token_bucket:' . $key;
        $bucket   = Cache::get($cacheKey);

        if ($bucket === null) {
            return 0;
        }

        $deficit    = $this->tokenCost - (int) $bucket['tokens'];
        $intervals  = (int) ceil($deficit / $this->refillAmount);

        return $intervals * $this->refillEvery;
    }

    /**
     * Reset the bucket for a key (e.g. on successful login).
     */
    public function clear(string $key): void
    {
        Cache::forget('token_bucket:' . $key);
    }
}
