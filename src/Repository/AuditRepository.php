<?php

namespace CoderEmbassy\AiSeoAutomation\Repository;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the audit log table.
 * Audit rows are NEVER deleted — rollbacks create new 'rollback' rows.
 */
class AuditRepository {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    /**
     * Insert a single audit row.
     *
     * @param array $data Keys: product_id, field_name, old_value, new_value, event_type, changed_by, job_id
     */
    public function insertAuditRow( array $data ): void {
        global $wpdb;

        $table = Schema::tableName( Schema::AUDIT );
        $wpdb->insert(
            $table,
            [
                'product_id'  => (int) ( $data['product_id'] ?? 0 ),
                'field_name'  => sanitize_key( $data['field_name'] ?? '' ),
                'old_value'   => isset( $data['old_value'] ) ? (string) $data['old_value'] : null,
                'new_value'   => isset( $data['new_value'] ) ? (string) $data['new_value'] : null,
                'event_type'  => sanitize_key( $data['event_type'] ?? 'apply' ),
                'changed_by'  => isset( $data['changed_by'] ) ? (int) $data['changed_by'] : null,
                'job_id'      => isset( $data['job_id'] ) ? (int) $data['job_id'] : null,
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );
    }

    /**
     * Get all audit rows for a job, ordered oldest-first.
     *
     * @param int $jobId Job ID.
     * @return object[]
     */
    public function getAuditByJob( int $jobId ): array {
        global $wpdb;

        $table = Schema::tableName( Schema::AUDIT );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE job_id = %d AND event_type = \'apply\' ORDER BY id ASC',
                $table,
                $jobId
            )
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Get audit rows for a specific product within a job.
     *
     * @param int $jobId     Job ID.
     * @param int $productId Product ID.
     * @return object[]
     */
    public function getAuditByJobAndProduct( int $jobId, int $productId ): array {
        global $wpdb;

        $table = Schema::tableName( Schema::AUDIT );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE job_id = %d AND product_id = %d AND event_type = \'apply\' ORDER BY id ASC',
                $table,
                $jobId,
                $productId
            )
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * List audit log with optional filters.
     *
     * @param array $filters  Optional: job_id, product_id, event_type,
     *                        date_from (YYYY-MM-DD), date_to (YYYY-MM-DD),
     *                        limit, offset.
     * @return object[]
     */
    public function listAudit( array $filters = [] ): array {
        global $wpdb;

        $table  = Schema::tableName( Schema::AUDIT );
        $query  = 'SELECT * FROM %i WHERE 1=1';
        $values = [ $table ];

        if ( ! empty( $filters['job_id'] ) ) {
            $query   .= ' AND job_id = %d';
            $values[] = (int) $filters['job_id'];
        }

        if ( ! empty( $filters['product_id'] ) ) {
            $query   .= ' AND product_id = %d';
            $values[] = (int) $filters['product_id'];
        }

        if ( ! empty( $filters['event_type'] ) ) {
            $query   .= ' AND event_type = %s';
            $values[] = sanitize_key( $filters['event_type'] );
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $query   .= ' AND created_at >= %s';
            $values[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $query   .= ' AND created_at <= %s';
            $values[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
        }

        $limit     = min( (int) ( $filters['limit'] ?? 50 ), 5000 );
        $offset    = (int) ( $filters['offset'] ?? 0 );

        $values[] = $limit;
        $values[] = $offset;

        $query .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built from fixed fragments; values are prepared.
        $rows   = $wpdb->get_results( $wpdb->prepare( $query, $values ) );
        return is_array( $rows ) ? $rows : [];
    }

    // phpcs:enable
}
