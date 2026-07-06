<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Special:ReceiptReview — triage queue grouped by status. Ready rows
 * expose inline edit of parsed fields, Toggle expense/income, Reprocess,
 * Review-in-form, and Dismiss. Pending / Processing rows surface
 * the file and queue id.
 *
 * This class handles the POST dispatch and the queue mutations; the
 * HTML lives in ReviewQueueRenderer.
 */
class SpecialReceiptReview extends SpecialPage {

	public function __construct(
		private readonly QueueStore $queueStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( 'ReceiptReview' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();
		$out = $this->getOutput();

		$request = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if (
			$request->wasPosted()
			&& $csrf->matchToken( $request->getVal( 'wpEditToken' ) )
		) {
			$handled = false;
			$retryId = $request->getInt( 'retry' );
			if ( $retryId ) {
				$this->retry( $retryId );
				$handled = true;
			}
			$dismissId = $request->getInt( 'dismiss' );
			if ( $dismissId ) {
				// Conditional transition: only ready/failed rows dismiss,
				// so a racing scan can't resurrect the row afterwards.
				$this->queueStore->dismiss( $dismissId );
				$handled = true;
			}
			$reprocessId = $request->getInt( 'reprocess' );
			if ( $reprocessId ) {
				$this->reprocess( $reprocessId );
				$handled = true;
			}
			$toggleKindId = $request->getInt( 'togglekind' );
			if ( $toggleKindId ) {
				$this->toggleKind( $toggleKindId );
				$handled = true;
			}
			if ( $handled ) {
				// Post/Redirect/Get — a 302 to the same URL stops the
				// browser from re-submitting the POST on refresh.
				$out->redirect( $this->getPageTitle()->getLocalURL() );
				return;
			}
		}

		( new UploadFlashRenderer( $this->getContext() ) )->render();

		$renderer = new ReviewQueueRenderer(
			$this->getContext(),
			$this->queueStore,
			$this->titleFactory,
			$this->getPageTitle()
		);
		$activeCount = 0;
		foreach ( [
			QueueStatus::Ready,
			QueueStatus::Processing,
			QueueStatus::Pending,
			QueueStatus::Failed,
		] as $status ) {
			$count = $renderer->renderSection( $status );
			if ( $status === QueueStatus::Processing || $status === QueueStatus::Pending ) {
				$activeCount += $count;
			}
		}
		// Auto-refresh only when there is still in-flight work, so an
		// idle reviewer's page doesn't reload underneath them.
		if ( $activeCount > 0 ) {
			$out->addMeta( 'http:refresh', '10' );
		}
	}

	/** Flip expense ↔ income on a Ready row, then reprocess it. */
	private function toggleKind( int $id ): void {
		$row = $this->queueStore->get( $id );
		if ( !$row || $row['rsq_status'] !== QueueStatus::Ready->value ) {
			return;
		}
		// Win the reset race first: a lost race (double-click, concurrent
		// reprocess) must not flip the kind again or push a stray job.
		if ( !$this->queueStore->resetToPending( $id ) ) {
			return;
		}
		$current = ReceiptKind::tryFrom( $row['rsq_kind'] ?? '' ) ?? ReceiptKind::Expense;
		$this->queueStore->setKind( $id, $current->other() );
		$this->pushScanJob( $row['rsq_file_name'], $id );
	}

	/** Queue a (re)scan of the given file's row. */
	private function pushScanJob( string $fileName, int $rsqId ): void {
		$title = $this->titleFactory->makeTitleSafe( NS_FILE, $fileName )
			?? $this->getPageTitle();
		$this->jobQueueGroup->lazyPush(
			new JobSpecification( 'ReceiptScanJob', [ 'rsq_id' => $rsqId ], [], $title )
		);
	}

	private function retry( int $id ): void {
		$row = $this->queueStore->get( $id );
		if ( !$row || $row['rsq_status'] !== QueueStatus::Failed->value ) {
			return;
		}
		// An active (pending/processing/ready) row already covers this
		// file — repeated Retry clicks must not clone duplicates.
		if ( $this->queueStore->findActiveBySha1( $row['rsq_file_sha1'] ) ) {
			return;
		}
		// Clone rather than reset in place, so the failure stays visible
		// until dismissed.
		$newId = $this->queueStore->enqueue(
			$row['rsq_file_sha1'],
			$row['rsq_file_name'],
			(int)$row['rsq_uploader'],
			ReceiptKind::tryFrom( $row['rsq_kind'] ?? '' ) ?? ReceiptKind::Expense
		);
		$this->pushScanJob( $row['rsq_file_name'], $newId );
	}

	/**
	 * Re-parse a non-pending row in place: same id, back to `pending`,
	 * fresh job — the user sees one row move Ready → Pending → Ready.
	 */
	private function reprocess( int $id ): void {
		$row = $this->queueStore->reprocess( $id );
		if ( !$row ) {
			return;
		}
		$this->pushScanJob( $row['rsq_file_name'], $id );
	}
}
