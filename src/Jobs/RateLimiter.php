<?php

namespace AiWooSeo\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-based token bucket rate limiter.
 * Prevents exceeding AI provider rate limits.
 */
class RateLimiter {

    private int $maxPerMinute;
    private string $transientKey;

    public function __construct( string $provider = 'openai', int $maxPerMinute = 60 ) {
        $this->maxPerMinute = $maxPerMinute;
        $this->transientKey = "aiwoo_rate_{$provider}";
    }

    /**
     * Block until a rate-limit slot is available, then consume one slot.
     *
     * Loops with 0.5s sleeps while at or over limit to avoid overwhelming the transient store.
     */
    public function waitIfNeeded(): void {
        $attempts = 0;

        while ( true ) {
            $calls = (int) get_transient( $this->transientKey );

            if ( $calls < $this->maxPerMinute ) {
                break;
            }

            usleep( 500000 ); // 0.5s

            // Safety valve: after 120 half-second checks (60s) give up waiting
            // and let the Worker's timeout guard handle rescheduling.
            if ( ++$attempts >= 120 ) {
                break;
            }
        }

        $calls = (int) get_transient( $this->transientKey );
        set_transient( $this->transientKey, $calls + 1, 60 );
    }
}
