<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;

/**
 * One-time banner on Special:ReceiptReview summarizing the upload that
 * just redirected here. SpecialUploadReceipt::handleUpload stashes the
 * counts in the session; this renders and clears them.
 */
readonly class UploadFlashRenderer {
	use MsgTrait;

	public function __construct(
		private IContextSource $context
	) {
	}

	public function render(): void {
		$session = $this->context->getRequest()->getSession();
		$flash = $session->get( 'rs-upload-flash' );
		if ( !$flash ) {
			return;
		}
		$session->remove( 'rs-upload-flash' );

		$out = $this->context->getOutput();
		$queued = (int)( $flash['queued'] ?? 0 );
		$newUploads = (int)( $flash['newUploads'] ?? $queued );
		$reEnqueued = (int)( $flash['reEnqueued'] ?? 0 );
		$alreadyActive = (int)( $flash['alreadyActive'] ?? 0 );
		$intraBatchDupes = (int)( $flash['intraBatchDupes'] ?? 0 );
		$alreadyReviewed = (int)( $flash['alreadyReviewed'] ?? 0 );

		// Headline: truncation wins; otherwise pick the success line
		// matching the mix of new uploads vs. re-queued known files.
		$intraDupesNote = $intraBatchDupes > 0
			? ' ' . $this->msg( 'receiptscanner-upload-flash-intra-batch-dupes' )
				->numParams( $intraBatchDupes )->text()
			: '';
		$reviewedNote = $alreadyReviewed > 0
			? ' ' . $this->msg( 'receiptscanner-upload-flash-already-reviewed' )
				->numParams( $alreadyReviewed )->text()
			: '';
		if ( !empty( $flash['truncated'] ) ) {
			$reported = (int)( $flash['reported'] ?? 0 );
			$dropped = max( 0, $reported - $queued );
			$out->addHTML( Html::warningBox( htmlspecialchars(
				$this->msg( 'receiptscanner-upload-flash-truncated' )
					->numParams( $queued, $reported, $dropped )->text()
				. $intraDupesNote . $reviewedNote
			) ) );
		} elseif ( $newUploads > 0 && ( $reEnqueued + $alreadyActive ) > 0 ) {
			$out->addHTML( Html::successBox( htmlspecialchars(
				$this->msg( 'receiptscanner-upload-flash-queued-mixed' )
					->numParams( $newUploads, $reEnqueued, $alreadyActive )->text()
				. $intraDupesNote . $reviewedNote
			) ) );
		} elseif ( $newUploads === 0 && ( $reEnqueued + $alreadyActive ) > 0 ) {
			$out->addHTML( Html::successBox( htmlspecialchars(
				$this->msg( 'receiptscanner-upload-flash-queued-duplicates' )
					->numParams( $reEnqueued, $alreadyActive )->text()
				. $intraDupesNote . $reviewedNote
			) ) );
		} elseif ( $queued > 0 ) {
			$out->addHTML( Html::successBox( htmlspecialchars(
				$this->msg( 'receiptscanner-upload-flash-queued' )
					->numParams( $queued - $intraBatchDupes )->text()
				. $intraDupesNote . $reviewedNote
			) ) );
		} else {
			// Nothing queued: the whole selection was already reviewed
			// and saved, so re-uploading was a no-op by design.
			$out->addHTML( Html::successBox( htmlspecialchars(
				$this->msg( 'receiptscanner-upload-flash-already-reviewed-only' )
					->numParams( $alreadyReviewed )->text()
			) ) );
		}

		if ( !empty( $flash['errors'] ) ) {
			$items = '';
			foreach ( $flash['errors'] as $e ) {
				$items .= Html::element( 'li', [], (string)$e );
			}
			$out->addHTML( Html::warningBox(
				Html::element( 'p', [],
					$this->msg( 'receiptscanner-upload-flash-itemerrors' )->text() )
				. Html::rawElement( 'ul', [], $items )
			) );
		}

		if ( !empty( $flash['renamed'] ) ) {
			$items = '';
			foreach ( $flash['renamed'] as $r ) {
				$items .= Html::element( 'li', [],
					$this->msg( 'receiptscanner-review-renamed-item' )
						->plaintextParams( $r['from'], $r['to'] )->text()
				);
			}
			$out->addHTML( Html::noticeBox(
				Html::element( 'p', [],
					$this->msg( 'receiptscanner-upload-flash-renamed' )
						->numParams( count( $flash['renamed'] ) )->text() )
				. Html::rawElement( 'ul', [], $items ),
				''
			) );
		}
	}
}
