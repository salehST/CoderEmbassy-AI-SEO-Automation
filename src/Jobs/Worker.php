<?php

namespace AiWooSeo\Jobs;

use AiWooSeo\Engine\GenerationEngine;
use AiWooSeo\Repository\JobRepository;
use AiWooSeo\Services\MetaWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Processes a batch of job items with a hard 25-second timeout guard.
 */
class Worker {

    public function __construct(
        private JobRepository $jobs,
        private GenerationEngine $engine,
        private RateLimiter $rateLimiter,
        private MetaWriter $metaWriter
    ) {}

    /**
     * Process up to $batchSize items for a job.
     *
     * The 25-second guard is NON-NEGOTIABLE. When elapsed time exceeds the guard,
     * the job is rescheduled and the current execution returns cleanly, ensuring
     * WP-Cron / PHP never kills a mid-write operation.
     *
     * @param int $jobId     Job ID to process.
     * @param int $batchSize Max items to process in this execution.
     */
    public function processJob( int $jobId, int $batchSize = 10 ): void {
        $start     = time();
        $processed = 0;

        $job = $this->jobs->getJob( $jobId );
        if ( ! $job ) {
            return;
        }

        $this->jobs->markJobStatus( $jobId, 'processing' );
        $rule       = $this->getJobRule( $job );
        $jobOptions = json_decode( $job->options ?? '{}', true );
        if ( ! is_array( $jobOptions ) ) {
            $jobOptions = [];
        }
        $skipExisting = ! empty( $jobOptions['skip_existing'] );

        while ( $processed < $batchSize ) {
            // Hard timeout guard — leave 5s headroom before PHP max_execution_time
            if ( ( time() - $start ) > 25 ) {
                wp_schedule_single_event( time() + 5, 'aiwoo_queue_runner' );
                return;
            }

            $item = $this->jobs->fetchPendingItem( $jobId );
            if ( ! $item ) {
                break; // No more items to process
            }

            $this->jobs->markItemProcessing( (int) $item->id );

            // If skip_existing is set, silently skip products that already have AI-generated SEO.
            if ( $skipExisting && get_post_meta( (int) $item->product_id, '_aiwoo_seo_title', true ) ) {
                $this->jobs->markItemComplete( (int) $item->id, [ 'skipped' => true ], [] );
                $processed++;
                continue;
            }

            try {
                $this->rateLimiter->waitIfNeeded();

                $preview = $this->engine->generatePreviewForProduct(
                    (int) $item->product_id,
                    $rule
                );

                $this->jobs->markItemComplete( (int) $item->id, $preview, [] );

                if ( ( $jobOptions['source'] ?? '' ) === 'autopilot' ) {
                    try {
                        $this->metaWriter->write(
                            (int) $item->product_id,
                            $preview['title'] ?? '',
                            $preview['meta']  ?? ''
                        );
                    } catch ( \Throwable $writeEx ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[ai-woo-seo] Autopilot MetaWriter failed for product '
                                . $item->product_id . ': ' . $writeEx->getMessage() );
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                $this->jobs->markItemFailed( (int) $item->id, $e->getMessage() );
            }

            $processed++;
        }

        // Mark job complete if all items are resolved
        $progress = $this->jobs->getJobProgress( $jobId );
        if ( $progress['total'] > 0 && ( $progress['done'] + $progress['failed'] ) >= $progress['total'] ) {
            $this->jobs->markJobStatus( $jobId, 'complete' );
        }
    }

    /**
     * Extract rule array from a job row's options JSON.
     *
     * @param object $job Job row from database.
     * @return array Rule data, or empty array if not set.
     */
    private function getJobRule( object $job ): array {
        if ( empty( $job->options ) ) {
            return [];
        }

        $options = json_decode( $job->options, true );
        if ( ! is_array( $options ) ) {
            return [];
        }

        if ( isset( $options['rule'] ) && is_array( $options['rule'] ) ) {
            return (array) $options['rule'];
        }
        if ( ! empty( $options['rule_id'] ) && (int) $options['rule_id'] > 0 ) {
            return [ 'id' => (int) $options['rule_id'] ];
        }
        return [];
    }
}
