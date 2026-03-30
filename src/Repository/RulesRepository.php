<?php

namespace AiWooSeo\Repository;

use AiWooSeo\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the rules table.
 * All queries use $wpdb->prepare() — no raw dynamic SQL.
 */
class RulesRepository {

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Create a new rule and return its ID.
     *
     * @param array $data Rule fields (see column list in migration).
     * @return int New rule ID.
     */
    public function createRule( array $data ): int {
        global $wpdb;

        $table = Schema::tableName( Schema::RULES );
        $now   = current_time( 'mysql' );

        $wpdb->insert(
            $table,
            [
                'name'            => sanitize_text_field( $data['name'] ?? '' ),
                'description'     => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
                'brand'           => isset( $data['brand'] ) ? sanitize_text_field( (string) $data['brand'] ) : null,
                'language'        => sanitize_text_field( (string) ( $data['language'] ?? 'en' ) ),
                'tone'            => isset( $data['tone'] ) ? sanitize_text_field( (string) $data['tone'] ) : null,
                'prompt_template' => isset( $data['prompt_template'] ) ? (string) $data['prompt_template'] : null,
                'category_ids'    => isset( $data['category_ids'] ) ? wp_json_encode( array_map( 'absint', (array) $data['category_ids'] ) ) : null,
                'is_default'      => (int) ! empty( $data['is_default'] ),
                'created_by'      => isset( $data['created_by'] ) ? (int) $data['created_by'] : ( get_current_user_id() ?: null ),
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $wpdb->insert_id && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ai-woo-seo] createRule failed. DB error: ' . $wpdb->last_error );
            error_log( '[ai-woo-seo] Last query: ' . $wpdb->last_query );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing rule.
     *
     * @param int   $id   Rule ID.
     * @param array $data Fields to update.
     * @return bool True on success.
     */
    public function updateRule( int $id, array $data ): bool {
        global $wpdb;

        $table   = Schema::tableName( Schema::RULES );
        $payload = [ 'updated_at' => current_time( 'mysql' ) ];
        $formats = [ '%s' ];

        if ( array_key_exists( 'name', $data ) ) {
            $payload['name'] = sanitize_text_field( (string) $data['name'] );
            $formats[]       = '%s';
        }
        if ( array_key_exists( 'description', $data ) ) {
            $payload['description'] = isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null;
            $formats[]              = '%s';
        }
        if ( array_key_exists( 'brand', $data ) ) {
            $payload['brand'] = isset( $data['brand'] ) ? sanitize_text_field( (string) $data['brand'] ) : null;
            $formats[]        = '%s';
        }
        if ( array_key_exists( 'language', $data ) ) {
            $payload['language'] = sanitize_text_field( (string) $data['language'] );
            $formats[]           = '%s';
        }
        if ( array_key_exists( 'tone', $data ) ) {
            $payload['tone'] = isset( $data['tone'] ) ? sanitize_text_field( (string) $data['tone'] ) : null;
            $formats[]       = '%s';
        }
        if ( array_key_exists( 'prompt_template', $data ) ) {
            $payload['prompt_template'] = isset( $data['prompt_template'] ) ? (string) $data['prompt_template'] : null;
            $formats[]                  = '%s';
        }
        if ( array_key_exists( 'category_ids', $data ) ) {
            $payload['category_ids'] = isset( $data['category_ids'] )
                ? wp_json_encode( array_map( 'absint', (array) $data['category_ids'] ) )
                : null;
            $formats[] = '%s';
        }
        if ( array_key_exists( 'is_default', $data ) ) {
            $payload['is_default'] = (int) ! empty( $data['is_default'] );
            $formats[]             = '%d';
        }

        $result = $wpdb->update( $table, $payload, [ 'id' => $id ], $formats, [ '%d' ] );
        return $result !== false;
    }

    /**
     * Delete a rule by ID.
     *
     * @param int $id Rule ID.
     * @return bool True on success.
     */
    public function deleteRule( int $id ): bool {
        global $wpdb;
        $table  = Schema::tableName( Schema::RULES );
        $result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        return $result !== false && $result > 0;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Fetch a single rule by ID.
     *
     * @param int $id Rule ID.
     * @return object|null Row object or null if not found.
     */
    public function getRule( int $id ): ?object {
        global $wpdb;
        $table = Schema::tableName( Schema::RULES );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id )
        );
        return $row ? $this->decodeRow( $row ) : null;
    }

    /**
     * List all rules with pagination.
     *
     * @param int $limit  Max rows to return (capped at 200).
     * @param int $offset Row offset.
     * @return object[]
     */
    public function listRules( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = Schema::tableName( Schema::RULES );
        $limit = min( $limit, 200 );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY is_default DESC, id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return is_array( $rows ) ? array_map( [ $this, 'decodeRow' ], $rows ) : [];
    }

    /**
     * Return the default rule, if one exists.
     *
     * @return object|null
     */
    public function getDefaultRule(): ?object {
        global $wpdb;
        $table = Schema::tableName( Schema::RULES );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_default = %d ORDER BY id ASC LIMIT 1",
                1
            )
        );
        return $row ? $this->decodeRow( $row ) : null;
    }

    /**
     * Find the best-matching rule for a set of WooCommerce category IDs.
     *
     * Iterates rules that have category_ids set and returns the first whose
     * JSON-decoded list overlaps the supplied IDs.
     * Falls back to the default rule when no category match is found.
     *
     * @param int[] $categoryIds WC category term IDs.
     * @return object|null Matched rule, default rule, or null.
     */
    public function getRuleForCategories( array $categoryIds ): ?object {
        global $wpdb;

        if ( empty( $categoryIds ) ) {
            return $this->getDefaultRule();
        }

        $table = Schema::tableName( Schema::RULES );
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE category_ids IS NOT NULL AND category_ids != '' ORDER BY id ASC"
        );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $ids = json_decode( (string) $row->category_ids, true );
                if ( is_array( $ids ) && array_intersect( $ids, $categoryIds ) ) {
                    return $this->decodeRow( $row );
                }
            }
        }

        return $this->getDefaultRule();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Decode the JSON category_ids column back to an array on a row object.
     */
    private function decodeRow( object $row ): object {
        if ( isset( $row->category_ids ) && is_string( $row->category_ids ) && $row->category_ids !== '' ) {
            $decoded           = json_decode( $row->category_ids, true );
            $row->category_ids = is_array( $decoded ) ? $decoded : [];
        } else {
            $row->category_ids = [];
        }
        $row->is_default = (bool) $row->is_default;
        return $row;
    }
}
