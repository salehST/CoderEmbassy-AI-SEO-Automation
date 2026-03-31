<?php

namespace CoderEmbassy\AiSeoAutomation\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Removes PII from product data before sending to AI APIs.
 */
class PiiScrubber {

    /**
     * @var array<string, string>
     */
    private static array $patterns = [
        'email'  => '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
        'phone'  => '/(\+?[\d\s\-\(\)]{7,15})/',
        'postal' => '/\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b/i', // UK postcodes
        'zip'    => '/\b\d{5}(?:-\d{4})?\b/', // US ZIP codes
    ];

    /**
     * Scrub PII from product fields.
     *
     * @param array<string, mixed> $product Product data with keys: title, description, short_description, etc.
     * @return array<string, mixed> Product data with PII replaced by placeholders.
     */
    public static function scrub( array $product ): array {
        $fields = [ 'description', 'short_description', 'title' ];

        foreach ( $fields as $field ) {
            if ( ! empty( $product[ $field ] ) && is_string( $product[ $field ] ) ) {
                foreach ( self::$patterns as $type => $pattern ) {
                    $product[ $field ] = preg_replace(
                        $pattern,
                        "[{$type} removed]",
                        $product[ $field ]
                    );
                }
            }
        }

        return $product;
    }
}
