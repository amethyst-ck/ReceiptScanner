<?php

namespace MediaWiki\Extension\ReceiptScanner\Jobs;

use Job;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarClient;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;
use RepoGroup;
use Throwable;

/**
 * Background job: hand a queued file to the sidecar and store the
 * result. Never throws to the runner — failures are recorded on the
 * row as `rserror|<code>` for the UI to translate and offer a retry.
 */
class ReceiptScanJob extends Job {

	public const ERR_FILE_GONE = 'filegone';
	public const ERR_INTERNAL = 'internal';

	/**
	 * Instantiated by JobFactory from the extension.json spec; push work
	 * with a JobSpecification, not `new`.
	 *
	 * @param Title $title File page title (informational; work is keyed by rsq_id).
	 * @param array{rsq_id:int} $params
	 */
	public function __construct(
		Title $title,
		array $params,
		private readonly QueueStore $queueStore,
		private readonly SidecarClient $sidecarClient,
		private readonly RepoGroup $repoGroup
	) {
		parent::__construct( 'ReceiptScanJob', $title, $params );
	}

	/**
	 * Look up the row, call the sidecar, store the result. Always returns
	 * true so the runner doesn't requeue: retries are user-driven from
	 * Special:ReceiptReview.
	 */
	public function run(): bool {
		$id = (int)$this->params['rsq_id'];
		// At-least-once delivery: the atomic claim decides which runner
		// handles the row; losers have nothing to do.
		if ( !$this->queueStore->claimForProcessing( $id ) ) {
			return true;
		}
		$row = $this->queueStore->get( $id );
		if ( !$row ) {
			// Row vanished between claim and fetch (e.g. file deleted).
			return true;
		}

		try {
			$file = $this->repoGroup->findFileFromKey( $row['rsq_file_sha1'] );
			if ( !$file ) {
				$this->queueStore->setFailed( $id, QueueStore::ERROR_PREFIX . self::ERR_FILE_GONE );
				return true;
			}
			$localPath = $file->getLocalRefPath();
			if ( $localPath === false ) {
				$this->queueStore->setFailed( $id, QueueStore::ERROR_PREFIX . self::ERR_FILE_GONE );
				return true;
			}
			$kind = ReceiptKind::tryFrom( $row['rsq_kind'] ?? '' ) ?? ReceiptKind::Expense;
			$result = $this->sidecarClient->parse(
				$localPath,
				$row['rsq_file_name'],
				$kind
			);
			$this->queueStore->setReady( $id, $result['text_source'] ?? 'unknown', $result );
		} catch ( SidecarException $e ) {
			$this->queueStore->setFailed( $id, QueueStore::ERROR_PREFIX . $e->errorCode );
			LoggerFactory::getInstance( 'ReceiptScanner' )
				->warning( $e->getMessage(), [ 'exception' => $e ] );
		} catch ( Throwable $e ) {
			$this->queueStore->setFailed( $id, QueueStore::ERROR_PREFIX . self::ERR_INTERNAL );
			LoggerFactory::getInstance( 'ReceiptScanner' )
				->warning( $e->getMessage(), [ 'exception' => $e ] );
		}
		return true;
	}
}
