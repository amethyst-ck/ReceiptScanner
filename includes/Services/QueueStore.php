<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Read/write access to the receipt_scanner_queue table.
 *
 * State machine:
 *   Pending → Processing → Ready → Consumed
 *                       ↘ Failed (retryable)
 */
class QueueStore {

	private const TABLE = 'receipt_scanner_queue';

	/**
	 * Prefix used by ReceiptScanJob when stashing a translatable error
	 * code in `rsq_error`. SpecialReceiptReview strips the prefix and
	 * maps the suffix to a message key; anything not carrying it is
	 * shown verbatim (legacy / unknown errors).
	 */
	public const ERROR_PREFIX = 'rserror|';

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {
	}

	/**
	 * Create a pending queue row. Returns the new rsq_id.
	 */
	public function enqueue(
		string $fileSha1,
		string $fileName,
		int $uploaderId,
		ReceiptKind $kind = ReceiptKind::Expense
	): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [
				'rsq_file_sha1' => $fileSha1,
				'rsq_file_name' => $fileName,
				'rsq_uploader' => $uploaderId,
				'rsq_status' => QueueStatus::Pending->value,
				'rsq_kind' => $kind->value,
				'rsq_enqueued_at' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->insertId();
	}

	/**
	 * Update a row's kind (Expense ↔ Income). Used when the user
	 * picks the wrong kind at upload and corrects it on the review page.
	 */
	public function setKind( int $id, ReceiptKind $kind ): void {
		$this->dbProvider->getPrimaryDatabase()
			->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'rsq_kind' => $kind->value ] )
			->where( [ 'rsq_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Reads the primary: the replica's snapshot may pre-date a write the
	 * caller just committed (read-your-own-writes).
	 *
	 * @return array<string,mixed>|null Row as an associative array, or null.
	 */
	public function get( int $id ): ?array {
		$row = $this->dbProvider->getPrimaryDatabase()
			->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'rsq_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (array)$row : null;
	}

	/**
	 * Atomically claim a pending row: a conditional UPDATE guarantees
	 * exactly one winner under at-least-once job delivery.
	 *
	 * @return bool True when this call flipped the row Pending → Processing.
	 */
	public function claimForProcessing( int $id ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'rsq_status' => QueueStatus::Processing->value ] )
			->where( [
				'rsq_id' => $id,
				'rsq_status' => QueueStatus::Pending->value,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() === 1;
	}

	/**
	 * @param array<string,mixed> $response Sidecar JSON response;
	 *   `raw_text` is dropped so OCR text never persists in the table.
	 */
	public function setReady( int $id, string $textSource, array $response ): void {
		unset( $response['raw_text'] );
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'rsq_status' => QueueStatus::Ready->value,
				'rsq_text_source' => $textSource,
				'rsq_response' => json_encode( $response ),
				'rsq_processed_at' => $dbw->timestamp(),
			] )
			->where( [ 'rsq_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Mark a row failed with a short error string (truncated to 255 chars).
	 * Failed rows are retryable from Special:ReceiptReview.
	 */
	public function setFailed( int $id, string $error ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'rsq_status' => QueueStatus::Failed->value,
				'rsq_error' => substr( $error, 0, 255 ),
				'rsq_processed_at' => $dbw->timestamp(),
			] )
			->where( [ 'rsq_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Reset a non-pending row to Pending and return it — atomically, so
	 * concurrent reprocess POSTs can't both queue a job.
	 *
	 * @return array<string,mixed>|null The row when this call won the
	 *   transition; null otherwise. Only push a job on non-null.
	 */
	public function reprocess( int $id ): ?array {
		if ( !$this->resetToPending( $id ) ) {
			return null;
		}
		return $this->get( $id );
	}

	/**
	 * Reset a non-pending row to `pending` in place (stable id) and clear
	 * processed state. Guarded UPDATE: exactly one concurrent caller wins.
	 *
	 * @return bool True when this call transitioned the row.
	 */
	public function resetToPending( int $id ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'rsq_status' => QueueStatus::Pending->value,
				'rsq_text_source' => null,
				'rsq_response' => null,
				'rsq_error' => null,
				'rsq_processed_at' => null,
				// Re-stamp so the "Enqueued" column reflects this run.
				'rsq_enqueued_at' => $dbw->timestamp(),
			] )
			->where( [ 'rsq_id' => $id ] )
			->andWhere( $dbw->expr( 'rsq_status', '!=', QueueStatus::Pending->value ) )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() === 1;
	}

	/**
	 * Terminal transition: the row's data now lives in the given receipt
	 * page.
	 */
	/**
	 * Dismiss a ready or failed row: consumed with no receipt page.
	 * Conditional on the current status so a dismiss racing an in-flight
	 * scan cannot be resurrected by the job's later setReady().
	 */
	public function dismiss( int $id ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'rsq_status' => QueueStatus::Consumed->value,
				'rsq_receipt_page' => 0,
				'rsq_consumed_at' => $dbw->timestamp(),
			] )
			->where( [
				'rsq_id' => $id,
				'rsq_status' => [
					QueueStatus::Ready->value,
					QueueStatus::Failed->value,
				],
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() === 1;
	}

	public function setConsumed( int $id, int $receiptPageId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'rsq_status' => QueueStatus::Consumed->value,
				'rsq_receipt_page' => $receiptPageId,
				'rsq_consumed_at' => $dbw->timestamp(),
			] )
			->where( [ 'rsq_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getByStatus( QueueStatus $status, int $limit = 50 ): array {
		$res = $this->dbProvider->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'rsq_status' => $status->value ] )
			->orderBy( 'rsq_enqueued_at' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();
		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = (array)$row;
		}
		return $rows;
	}

	/**
	 * Most recent pending/processing/ready row for a SHA-1 — blocks
	 * duplicate enqueues while prior work is unresolved. Failed and
	 * consumed rows don't count. Primary read (read-your-own-writes).
	 */
	public function findActiveBySha1( string $sha1 ): ?array {
		$row = $this->dbProvider->getPrimaryDatabase()
			->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [
				'rsq_file_sha1' => $sha1,
				'rsq_status' => [
					QueueStatus::Pending->value,
					QueueStatus::Processing->value,
					QueueStatus::Ready->value,
				],
			] )
			->orderBy( 'rsq_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (array)$row : null;
	}

	/**
	 * Consumed row for a SHA-1 that landed in a real receipt page
	 * (rsq_receipt_page > 0) — the page is the source of truth, so such
	 * files must not re-enter the queue. Dismissed rows (page id 0) don't
	 * count; re-uploading those is a legitimate reconsideration. Primary
	 * read (read-your-own-writes).
	 */
	public function findSavedBySha1( string $sha1 ): ?array {
		$dbr = $this->dbProvider->getPrimaryDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [
				'rsq_file_sha1' => $sha1,
				'rsq_status' => QueueStatus::Consumed->value,
			] )
			->andWhere( $dbr->expr( 'rsq_receipt_page', '>', 0 ) )
			->orderBy( 'rsq_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (array)$row : null;
	}

	/**
	 * Drop all non-consumed rows for a SHA-1 (file deleted). Consumed
	 * rows stay — they refer to a saved receipt page.
	 */
	public function deleteNonConsumedBySha1( string $fileSha1 ): void {
		$this->dbProvider->getPrimaryDatabase()
			->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'rsq_file_sha1' => $fileSha1 ] )
			->andWhere( $this->dbProvider->getPrimaryDatabase()
				->expr( 'rsq_status', '!=', QueueStatus::Consumed->value ) )
			->caller( __METHOD__ )
			->execute();
	}
}
