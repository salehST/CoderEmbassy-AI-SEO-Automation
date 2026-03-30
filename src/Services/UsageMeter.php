<?php

namespace AiWooSeo\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks AI generation usage for display. No usage-based limits (WordPress.org
 * guidelines allow only feature-based restrictions on free plugins).
 */
class UsageMeter {

    /**
     * Check if generation is allowed. Always true — limits are feature-based, not usage-based.
     *
     * @return bool
     */
    public function canGenerate(): bool {
        return true;
    }

    /**
     * Increment usage count.
     */
    public function increment(): void {
        $key  = $this->getMonthlyKey();
        $used = (int) get_option( $key, 0 );
        update_option( $key, $used + 1, false );
    }

    /**
     * Get current tier.
     *
     * @return string
     */
    public function getTier(): string {
        return (string) get_option( 'aiwoo_tier', 'free' );
    }

    /**
     * Get usage for current month.
     *
     * @return int
     */
    public function getMonthlyUsage(): int {
        return (int) get_option( $this->getMonthlyKey(), 0 );
    }

    /**
     * Get usage summary for display (no cap — limit is null, percent is 0).
     *
     * @return array{used: int, tier: string, limit: null, percent: int}
     */
    public function getUsageSummary(): array {
        return [
            'used'    => $this->getMonthlyUsage(),
            'tier'    => $this->getTier(),
            'limit'   => null,
            'percent' => 0,
        ];
    }

    private function getMonthlyKey(): string {
        return 'aiwoo_usage_' . gmdate( 'Y_m' );
    }
}
