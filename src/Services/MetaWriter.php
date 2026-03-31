<?php

namespace CoderEmbassy\AiSeoAutomation\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Writes AI-generated SEO content to the correct meta fields based on
 * which SEO target is active (yoast, rankmath, or native).
 */
class MetaWriter {

    private const OPTION_TARGET = 'ce_ai_seo_seo_target';
    private const META_LOCKED   = '_ce_ai_seo_field_locked';
    private const VALID_TARGETS = [ 'yoast', 'rankmath', 'native' ];

    /**
     * Write title and meta description to the configured target and backup keys.
     *
     * @param int    $product_id Product post ID.
     * @param string $title      SEO title.
     * @param string $meta       Meta description.
     * @return array{written: bool, reason?: string, target?: string, fields?: array}
     */
    public function write( int $product_id, string $title, string $meta ): array {
        if ( $this->isLocked( $product_id ) ) {
            $target = $this->resolveTarget();
            return [ 'written' => false, 'reason' => 'locked', 'target' => $target ];
        }

        $target = $this->resolveTarget();
        $keys   = $this->getFieldKeys( $target );

        update_post_meta( $product_id, $keys['title'], $title );
        update_post_meta( $product_id, $keys['meta'], $meta );

        // Always write backup to plugin's own keys.
        update_post_meta( $product_id, '_ce_ai_seo_seo_title', $title );
        update_post_meta( $product_id, '_ce_ai_seo_seo_meta', $meta );

        return [
            'written' => true,
            'target'  => $target,
            'fields'  => [ 'title' => $keys['title'], 'meta' => $keys['meta'] ],
        ];
    }

    /**
     * Lock SEO fields for a product so write() will skip.
     */
    public function lock( int $product_id ): void {
        update_post_meta( $product_id, self::META_LOCKED, '1' );
    }

    /**
     * Unlock SEO fields for a product.
     */
    public function unlock( int $product_id ): void {
        delete_post_meta( $product_id, self::META_LOCKED );
    }

    /**
     * Check if SEO fields are locked for a product.
     */
    public function isLocked( int $product_id ): bool {
        $val = get_post_meta( $product_id, self::META_LOCKED, true );
        return $val !== '' && $val !== false && $val !== null;
    }

    /**
     * Resolve and validate the current SEO target (yoast, rankmath, or native).
     */
    public function resolveTarget(): string {
        $target = (string) get_option( self::OPTION_TARGET, 'yoast' );
        return in_array( $target, self::VALID_TARGETS, true ) ? $target : 'yoast';
    }

    /**
     * Get meta keys for title and meta description for the given target.
     *
     * @return array{title: string, meta: string}
     */
    private function getFieldKeys( string $target ): array {
        switch ( $target ) {
            case 'rankmath':
                return [ 'title' => 'rank_math_title', 'meta' => 'rank_math_description' ];
            case 'native':
                return [ 'title' => '_ce_ai_seo_seo_title', 'meta' => '_ce_ai_seo_seo_meta' ];
            case 'yoast':
            default:
                return [ 'title' => '_yoast_wpseo_title', 'meta' => '_yoast_wpseo_metadesc' ];
        }
    }
}
