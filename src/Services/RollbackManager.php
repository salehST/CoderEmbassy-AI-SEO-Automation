<?php

namespace CoderEmbassy\AiSeoAutomation\Services;

use CoderEmbassy\AiSeoAutomation\Repository\AuditRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Restores product SEO fields to their pre-apply values.
 * Audit history is NEVER deleted — rollbacks write new 'rollback' rows.
 */
class RollbackManager {

    public function __construct( private AuditRepository $audit ) {}

    /**
     * Roll back all apply operations from a given job.
     *
     * @param int $jobId       Job to roll back.
     * @param int $initiatedBy User ID performing the rollback.
     * @return array{restored_rows: int, job_id: int}
     */
    public function rollbackJob( int $jobId, int $initiatedBy ): array {
        $rows     = $this->audit->getAuditByJob( $jobId );
        $restored = 0;

        foreach ( $rows as $row ) {
            $this->restoreRow( $row, $initiatedBy, $jobId );
            $restored++;
        }

        return [ 'restored_rows' => $restored, 'job_id' => $jobId ];
    }

    /**
     * Roll back a single product within a job.
     *
     * @param int $jobId       Job ID.
     * @param int $productId   Product to roll back.
     * @param int $initiatedBy User ID performing the rollback.
     * @return array{restored_rows: int, job_id: int, product_id: int}
     */
    public function rollbackProduct( int $jobId, int $productId, int $initiatedBy ): array {
        $rows     = $this->audit->getAuditByJobAndProduct( $jobId, $productId );
        $restored = 0;

        foreach ( $rows as $row ) {
            $this->restoreRow( $row, $initiatedBy, $jobId );
            $restored++;
        }

        return [ 'restored_rows' => $restored, 'job_id' => $jobId, 'product_id' => $productId ];
    }

    /**
     * Restore a single audit row and write a new 'rollback' audit entry.
     *
     * @param object $row         Audit row from database.
     * @param int    $initiatedBy User ID.
     * @param int    $jobId       Job ID.
     */
    private function restoreRow( object $row, int $initiatedBy, int $jobId ): void {
        $productId = (int) $row->product_id;
        $fieldName = (string) $row->field_name;

        if ( $row->old_value === null ) {
            delete_post_meta( $productId, $fieldName );
        } else {
            update_post_meta( $productId, $fieldName, $row->old_value );
        }

        // Write a NEW audit row — never mutate or delete existing audit history
        $this->audit->insertAuditRow( [
            'product_id'  => $productId,
            'field_name'  => $fieldName,
            'old_value'   => $row->new_value,
            'new_value'   => $row->old_value,
            'event_type'  => 'rollback',
            'changed_by'  => $initiatedBy,
            'job_id'      => $jobId,
        ] );
    }
}
