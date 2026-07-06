<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Jobs\ReceiptScanJob;
use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarClient;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * Special:ReceiptReview's rendering half: the per-status queue
 * sections, per-row action cells, parsed-field lists, and error
 * translation. Pure rendering — the POST dispatch and all queue
 * mutations stay in SpecialReceiptReview.
 */
readonly class ReviewQueueRenderer {
	use MsgTrait;

	/**
	 * A `processing` row enqueued longer ago than this (seconds) is
	 * stale — its runner likely died — and gets a Reprocess action.
	 */
	private const STALE_PROCESSING_SECONDS = 900;

	/** Stored `rserror|<code>` suffix → i18n key (see translateError). */
	private const ERROR_MESSAGES = [
		ReceiptScanJob::ERR_FILE_GONE      => 'receiptscanner-error-file-gone',
		ReceiptScanJob::ERR_INTERNAL       => 'receiptscanner-error-internal',
		SidecarClient::ERR_UNREADABLE      => 'receiptscanner-error-unreadable',
		SidecarClient::ERR_REQUEST         => 'receiptscanner-error-sidecar-unreachable',
		SidecarClient::ERR_BAD_RESPONSE    => 'receiptscanner-error-sidecar-bad-response',
	];

	public function __construct(
		private IContextSource $context,
		private QueueStore $queueStore,
		private TitleFactory $titleFactory,
		private Title $pageTitle
	) {
	}

	public function renderSection( QueueStatus $status ): int {
		$out = $this->context->getOutput();
		$rows = $this->queueStore->getByStatus( $status );

		$out->addHTML( Html::element(
			'h2',
			[],
			$this->msg( "receiptscanner-review-section-{$status->value}" )
				->numParams( count( $rows ) )->text()
		) );

		if ( !$rows ) {
			$out->addHTML( Html::element(
				'p',
				[],
				$this->msg( 'receiptscanner-review-empty' )->text()
			) );
			return 0;
		}

		// Pending/Processing rows have nothing actionable, so skip the
		// Action column — unless a stale Processing row needs recovery.
		$hasStale = $status === QueueStatus::Processing
			&& $this->anyStaleProcessing( $rows );
		$showAction = $status === QueueStatus::Ready
			|| $status === QueueStatus::Failed
			|| $hasStale;

		$body = '';
		foreach ( $rows as $row ) {
			$cells =
				Html::rawElement( 'td', [], $this->fileLink( $row['rsq_file_name'] ) )
				. Html::element( 'td', [], $this->formatTimestamp( $row['rsq_enqueued_at'] ) );
			if ( $showAction ) {
				$cell = $status === QueueStatus::Processing
					? $this->staleProcessingCell( $row )
					: $this->actionCell( $row );
				$cells .= Html::rawElement( 'td', [], $cell );
			}
			$body .= Html::rawElement( 'tr', [], $cells );
		}

		$headers = Html::element( 'th', [], $this->msg( 'receiptscanner-review-col-file' )->text() )
			. Html::element( 'th', [], $this->msg( 'receiptscanner-review-col-enqueued' )->text() );
		if ( $showAction ) {
			$headers .= Html::element( 'th', [], $this->msg( 'receiptscanner-review-col-action' )->text() );
		}

		$out->addHTML( Html::rawElement(
			'table',
			[ 'class' => 'wikitable' ],
			Html::rawElement( 'tr', [], $headers ) . $body
		) );
		return count( $rows );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function anyStaleProcessing( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( $this->isStaleProcessing( $row ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $row */
	private function isStaleProcessing( array $row ): bool {
		$enqueued = $row['rsq_enqueued_at'] ?? null;
		if ( !$enqueued ) {
			return false;
		}
		$age = (int)wfTimestamp( TS_UNIX ) - (int)wfTimestamp( TS_UNIX, $enqueued );
		return $age >= self::STALE_PROCESSING_SECONDS;
	}

	/**
	 * "Timed out" notice + Reprocess button for stale rows; empty for
	 * still-in-flight ones.
	 *
	 * @param array<string,mixed> $row
	 */
	private function staleProcessingCell( array $row ): string {
		if ( !$this->isStaleProcessing( $row ) ) {
			return '';
		}
		return Html::element( 'div', [],
				$this->msg( 'receiptscanner-review-timed-out' )->text() )
			. $this->inlinePostButton(
				'reprocess', (string)$row['rsq_id'],
				$this->msg( 'receiptscanner-review-reprocess' )->text()
			);
	}

	private function fileLink( string $fileName ): string {
		$title = $this->titleFactory->makeTitleSafe( NS_FILE, $fileName );
		if ( !$title ) {
			return htmlspecialchars( $fileName );
		}
		// Media link: straight to the file bytes, not the File: page.
		return Linker::makeMediaLinkObj( $title, htmlspecialchars( $fileName ) );
	}

	private function formatTimestamp( ?string $mwTs ): string {
		if ( !$mwTs ) {
			return '';
		}
		return $this->context->getLanguage()->userTimeAndDate( $mwTs, $this->context->getUser() );
	}

	private function actionCell( array $row ): string {
		return match ( QueueStatus::tryFrom( $row['rsq_status'] ?? '' ) ) {
			QueueStatus::Ready => $this->readyActions( $row ),
			QueueStatus::Failed => $this->failedActions( $row ),
			default => Html::element( 'span', [],
				$this->translateError( $row['rsq_error'] ?? '' ) ),
		};
	}

	/**
	 * Translate a stored `rserror|<code>` token to a localized string.
	 * Unprefixed or unrecognized values pass through unchanged.
	 */
	public function translateError( string $stored ): string {
		if ( !str_starts_with( $stored, QueueStore::ERROR_PREFIX ) ) {
			return $stored;
		}
		$code = substr( $stored, strlen( QueueStore::ERROR_PREFIX ) );
		if ( isset( self::ERROR_MESSAGES[$code] ) ) {
			return $this->msg( self::ERROR_MESSAGES[$code] )->text();
		}
		return $stored;
	}

	private function readyActions( array $row ): string {
		$response = json_decode( $row['rsq_response'] ?? '{}', true ) ?: [];
		$fields = $response['fields'] ?? [];
		$kind = ReceiptKind::tryFrom( $row['rsq_kind'] ?? '' ) ?? ReceiptKind::Expense;

		return $this->renderParsedFields( $kind, $fields )
			. Html::rawElement( 'div', [ 'style' => 'margin-top:.5em' ],
				$this->renderReviewFormLink( $row, $kind, $fields ) . ' '
				. $this->renderRowActionButtons( $row )
			);
	}

	/** Inline `<ul>` of the parsed key fields (type, date, total, …). */
	private function renderParsedFields( ReceiptKind $kind, array $fields ): string {
		$items = [];
		$kindLabel = $this->msg( "receiptscanner-kind-{$kind->value}" )->text();
		$items[] = Html::rawElement( 'li', [],
			Html::element( 'strong', [],
				$this->msg( 'receiptscanner-parsed-label-type' )->text()
				. $this->msg( 'colon-separator' )->text() )
			. htmlspecialchars( $kindLabel )
		);
		$partyKey = $kind->partyColumn();
		foreach ( [ 'date', 'total', 'subtotal', 'tax', 'fees', $partyKey ] as $f ) {
			$value = $fields[$f]['value'] ?? null;
			if ( $value !== null && $value !== '' ) {
				$label = $this->msg( "receiptscanner-parsed-label-$f" )->text();
				$items[] = Html::rawElement( 'li', [],
					Html::element( 'strong', [],
						$label . $this->msg( 'colon-separator' )->text() )
					. htmlspecialchars( (string)$value )
				);
			}
		}
		if ( !$items ) {
			return Html::element( 'em', [],
				$this->msg( 'receiptscanner-review-no-fields' )->text() );
		}
		return Html::rawElement( 'ul', [ 'class' => 'rs-parsed' ], implode( '', $items ) );
	}

	/**
	 * "Review in form" anchor: URL-prefills the PageForms form with the
	 * parsed values. Rows already consumed into a page route to FormEdit
	 * on that page so the user updates it instead of duplicating.
	 */
	private function renderReviewFormLink( array $row, ReceiptKind $kind, array $fields ): string {
		$formName = $kind->formName();
		$partyField = $kind->partyColumn();
		$prefill = [];
		foreach ( [ 'date', 'total', 'subtotal', 'tax', 'fees' ] as $f ) {
			$v = $fields[$f]['value'] ?? null;
			if ( $v !== null && $v !== '' ) {
				$prefill["{$formName}[$f]"] = (string)$v;
			}
		}
		$partyValue = $fields[$partyField]['value'] ?? null;
		if ( $partyValue !== null && $partyValue !== '' ) {
			$prefill["{$formName}[$partyField]"] = (string)$partyValue;
		}
		// The form's currency-conversion JS keys off `currency` to pin or
		// prompt for an exchange rate.
		if ( !empty( $fields['total']['currency'] ) ) {
			$prefill["{$formName}[currency]"] = $fields['total']['currency'];
		}
		// PageForms' tokens widget wants the display (spaces) form, not
		// the underscore form.
		$prefill["{$formName}[file]"] = str_replace( '_', ' ', $row['rsq_file_name'] );
		$prefill["{$formName}[queue_id]"] = (string)$row['rsq_id'];

		$existingPage = null;
		if ( !empty( $row['rsq_receipt_page'] ) ) {
			$existingPage = $this->titleFactory->newFromID( (int)$row['rsq_receipt_page'] );
		}
		$reviewTitle = $existingPage
			? SpecialPage::getTitleFor( 'FormEdit',
				$formName . '/' . $existingPage->getPrefixedDBkey() )
			: SpecialPage::getTitleFor( 'FormEdit', $formName );

		return Html::element( 'a', [
			'href' => $reviewTitle->getLocalURL( $prefill ),
			'class' => 'mw-ui-button mw-ui-progressive',
		], $this->msg( 'receiptscanner-review-open-form' )->text() );
	}

	/** Inline POST forms for Toggle type / Reprocess / Dismiss. */
	private function renderRowActionButtons( array $row ): string {
		$id = (string)$row['rsq_id'];
		return $this->inlinePostButton( 'togglekind', $id,
				$this->msg( 'receiptscanner-review-toggle-type' )->text() )
			. ' '
			. $this->inlinePostButton( 'reprocess', $id,
				$this->msg( 'receiptscanner-review-reprocess' )->text() )
			. ' '
			. $this->inlinePostButton( 'dismiss', $id,
				$this->msg( 'receiptscanner-review-dismiss' )->text() );
	}

	private function inlinePostButton( string $field, string $value, string $label ): string {
		return CsrfPostButton::render(
			$this->context, $this->pageTitle, $field, $value, $label
		);
	}

	private function failedActions( array $row ): string {
		return Html::element( 'div', [],
				$this->translateError( $row['rsq_error'] ?? '' ) )
			. $this->inlinePostButton(
				'retry', (string)$row['rsq_id'],
				$this->msg( 'receiptscanner-review-retry' )->text()
			)
			. ' '
			. $this->inlinePostButton(
				'dismiss', (string)$row['rsq_id'],
				$this->msg( 'receiptscanner-review-dismiss' )->text()
			);
	}
}
