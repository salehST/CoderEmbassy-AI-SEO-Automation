<?php

namespace CoderEmbassy\AiSeoAutomation\Repository;

use CoderEmbassy\AiSeoAutomation\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD operations for jobs and job_items tables.
 */
class JobRepository {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    /**
     * Create a new job and batch-insert all product items.
     *
     * @param string $name       Job display name.
     * @param int    $userId     User who created the job.
     * @param int[]  $productIds Product IDs to process.
     * @param array  $options    Optional job-level options (rule_id, overwrite_existing, etc).
     * @return int Job ID.
     * @throws \RuntimeException If job creation or item insert fails.
     */
    public function createJob( string $name, int $userId, array $productIds, array $options = [] ): int {
        global $wpdb;

        $jobs_table = Schema::tableName( Schema::JOBS );
        $now        = current_time( 'mysql' );

        $wpdb->insert(
            $jobs_table,
            [
                'name'        => sanitize_text_field( $name ),
                'status'      => 'pending',
                'user_id'     => $userId,
                'rule_id'     => isset( $options['rule_id'] ) ? (int) $options['rule_id'] : null,
                'options'     => wp_json_encode( $options ),
                'total_items' => 0,
                'done_items'  => 0,
                'created_at'  => $now,
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s' ]
        );

        $job_id = (int) $wpdb->insert_id;
        if ( ! $job_id ) {
            throw new \RuntimeException( 'Failed to create job row' );
        }

        $productIds = array_values( array_unique( array_map( 'absint', $productIds ) ) );

        if ( ! empty( $productIds ) ) {
            $this->batchInsertItems( $job_id, $productIds, $now );

            $wpdb->update(
                $jobs_table,
                [ 'total_items' => count( $productIds ) ],
                [ 'id' => $job_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        return $job_id;
    }

    /**
     * Batch-insert job items using a single multi-value INSERT.
     *
     * @param int    $jobId      Job ID.
     * @param int[]  $productIds Array of product IDs.
     * @param string $now        Current MySQL datetime.
     */
    private function batchInsertItems( int $jobId, array $productIds, string $now ): void {
        global $wpdb;

        $items_table = Schema::tableName( Schema::JOB_ITEMS );
        foreach ( $productIds as $productId ) {
            $wpdb->insert(
                $items_table,
                [
                    'job_id'     => $jobId,
                    'product_id' => (int) $productId,
                    'status'     => 'pending',
                    'created_at' => $now,
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Fetch the oldest pending item for a job (FIFO).
     *
     * @param int $jobId Job ID.
     * @return object|null
     */
    public function fetchPendingItem( int $jobId ): ?object {
        global $wpdb;

        $table = Schema::tableName( Schema::JOB_ITEMS );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE job_id = %d AND status = \'pending\' ORDER BY id ASC LIMIT 1',
                $table,
                $jobId
            )
        );

        return $row ?: null;
    }

    /**
     * Mark an item as processing to prevent double-processing.
     *
     * @param int $itemId Item ID.
     */
    public function markItemProcessing( int $itemId ): void {
        global $wpdb;

        $table = Schema::tableName( Schema::JOB_ITEMS );
        $wpdb->update(
            $table,
            [
                'status'     => 'processing',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $itemId ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark an item complete with its preview data.
     *
     * @param int   $itemId  Item ID.
     * @param array $preview Generated preview array.
     * @param array $diff    Optional diff (for display).
     */
    public function markItemComplete( int $itemId, array $preview, array $diff ): void {
        global $wpdb;

        $table = Schema::tableName( Schema::JOB_ITEMS );
        $wpdb->update(
            $table,
            [
                'status'     => 'complete',
                'preview'    => wp_json_encode( $preview ),
                'diff'       => wp_json_encode( $diff ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $itemId ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark an item as failed, incrementing attempt count.
     *
     * @param int    $itemId Item ID.
     * @param string $error  Error message.
     */
    public function markItemFailed( int $itemId, string $error ): void {
        global $wpdb;

        $table = Schema::tableName( Schema::JOB_ITEMS );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = \'failed\', last_error = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d',
                $table,
                substr( $error, 0, 65535 ),
                current_time( 'mysql' ),
                $itemId
            )
        );
    }

    /**
     * Get job progress.
     *
     * @param int $jobId Job ID.
     * @return array{total: int, done: int, failed: int, pct: int}
     */
    public function getJobProgress( int $jobId ): array {
        global $wpdb;

        $table = Schema::tableName( Schema::JOB_ITEMS );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(status = 'complete') AS done,
                    SUM(status = 'failed') AS failed
                FROM %i
                WHERE job_id = %d",
                $table,
                $jobId
            )
        );

        $total  = (int) ( $row->total ?? 0 );
        $done   = (int) ( $row->done ?? 0 );
        $failed = (int) ( $row->failed ?? 0 );
        $pct    = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;

        return compact( 'total', 'done', 'failed', 'pct' );
    }

    /**
     * Update the top-level job status.
     *
     * @param int    $jobId  Job ID.
     * @param string $status New status (e.g. 'pending', 'processing', 'complete', 'applied', 'cancelled').
     */
    public function markJobStatus( int $jobId, string $status ): void {
        global $wpdb;

        $table  = Schema::tableName( Schema::JOBS );
        $data   = [
            'status'     => sanitize_key( $status ),
            'updated_at' => current_time( 'mysql' ),
        ];
        $format = [ '%s', '%s' ];

        if ( $status === 'complete' ) {
            $data['completed_at'] = current_time( 'mysql' );
            $format[]             = '%s';
        }

        $wpdb->update( $table, $data, [ 'id' => $jobId ], $format, [ '%d' ] );
    }

    /**
     * Fetch the oldest job by status.
     *
     * @param string[] $statuses Job status values to match.
     * @return object|null
     */
    public function fetchOldestJobByStatus( array $statuses ): ?object {
        global $wpdb;

        if ( empty( $statuses ) ) {
            return null;
        }

        $table = Schema::tableName( Schema::JOBS );
        $in    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $sql   = "SELECT * FROM %i WHERE status IN ({$in}) ORDER BY id ASC LIMIT 1";
        $args  = array_merge( [ $table ], array_values( $statuses ) );

        $row = $wpdb->get_row(
            $wpdb->prepare( $sql, $args )
        );

        return $row ?: null;
    }

    /**
     * Get a job row by ID.
     *
     * @param int $jobId Job ID.
     * @return object|null
     */
    public function getJob( int $jobId ): ?object {
        global $wpdb;

        $table = Schema::tableName( Schema::JOBS );
        $row   = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $jobId )
        );

        return $row ?: null;
    }

    /**
     * List jobs with optional status filter.
     *
     * @param array $filters Optional: status (string), limit (int).
     * @return object[]
     */
    public function listJobs( array $filters = [] ): array {
        global $wpdb;

        $table  = Schema::tableName( Schema::JOBS );
        $query  = 'SELECT * FROM %i WHERE 1=1';
        $values = [ $table ];

        if ( ! empty( $filters['status'] ) ) {
            $query   .= ' AND status = %s';
            $values[] = sanitize_key( $filters['status'] );
        }

        $limit     = min( (int) ( $filters['limit'] ?? 20 ), 200 );

        $values[] = $limit;

        $query .= ' ORDER BY id DESC LIMIT %d';
        $rows   = $wpdb->get_results( $wpdb->prepare( $query, $values ) );
        return is_array( $rows ) ? $rows : [];
    }

    // phpcs:enable
}
