<?php

namespace AiWooSeo\Jobs;

use AiWooSeo\Repository\JobRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Thin facade over JobRepository and Queue scheduling.
 */
class JobManager {

    public function __construct( private JobRepository $repo ) {}

    /**
     * Create a job and schedule immediate processing.
     *
     * @param string $name       Job name.
     * @param int    $userId     Creator user ID.
     * @param int[]  $productIds Product IDs to process.
     * @param array  $options    Additional options (rule_id, overwrite_existing, etc).
     * @return int New job ID.
     */
    public function createJob( string $name, int $userId, array $productIds, array $options = [] ): int {
        $jobId = $this->repo->createJob( $name, $userId, $productIds, $options );

        // Schedule a one-off cron in 5 seconds to kick off the worker.
        // Never schedule one event per item — only per job.
        wp_schedule_single_event( time() + 5, 'aiwoo_queue_runner' );

        return $jobId;
    }

    /**
     * Fetch the oldest pending item for a job.
     *
     * @param int $jobId Job ID.
     * @return object|null
     */
    public function fetchPendingItem( int $jobId ): ?object {
        return $this->repo->fetchPendingItem( $jobId );
    }

    /**
     * Mark an item as processing.
     *
     * @param int $itemId Item ID.
     */
    public function markItemProcessing( int $itemId ): void {
        $this->repo->markItemProcessing( $itemId );
    }

    /**
     * Mark an item complete.
     *
     * @param int   $itemId  Item ID.
     * @param array $preview Preview data.
     * @param array $diff    Diff data.
     */
    public function markItemComplete( int $itemId, array $preview, array $diff ): void {
        $this->repo->markItemComplete( $itemId, $preview, $diff );
    }

    /**
     * Mark an item failed.
     *
     * @param int    $itemId Item ID.
     * @param string $error  Error message.
     */
    public function markItemFailed( int $itemId, string $error ): void {
        $this->repo->markItemFailed( $itemId, $error );
    }

    /**
     * Get job progress.
     *
     * @param int $jobId Job ID.
     * @return array{total: int, done: int, failed: int, pct: int}
     */
    public function getJobProgress( int $jobId ): array {
        return $this->repo->getJobProgress( $jobId );
    }

    /**
     * Update job status.
     *
     * @param int    $jobId  Job ID.
     * @param string $status New status.
     */
    public function markJobStatus( int $jobId, string $status ): void {
        $this->repo->markJobStatus( $jobId, $status );
    }

    /**
     * Get a job row by ID.
     *
     * @param int $jobId Job ID.
     * @return object|null
     */
    public function getJob( int $jobId ): ?object {
        return $this->repo->getJob( $jobId );
    }
}
