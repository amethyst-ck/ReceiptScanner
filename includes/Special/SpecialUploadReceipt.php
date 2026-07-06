<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use FSFile;
use JobQueueGroup;
use JobSpecification;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use RepoGroup;
use UploadFromFile;

/**
 * Upload one or more files (multi-file input) and enqueue each for
 * receipt processing via UploadFromFile + the queue.
 */
class SpecialUploadReceipt extends SpecialPage {

	private const FILE_FIELD = 'wpReceiptFile';
	private const KIND_FIELD = 'wpReceiptKind';
	private const COUNT_FIELD = 'wpReceiptFileCount';

	public function __construct(
		private readonly QueueStore $queueStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly RepoGroup $repoGroup
	) {
		parent::__construct( 'UploadReceipt', 'upload' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();
		$this->checkPermissions();
		$this->getOutput()->addModuleStyles( 'ext.receiptscanner.upload' );
		$this->getOutput()->addModules( 'ext.receiptscanner.upload' );

		$request = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if (
			$request->wasPosted()
			&& $csrf->matchToken( $request->getVal( 'wpEditToken' ) )
		) {
			$this->handleUpload();
			return;
		}
		$this->showForm();
	}

	private function showForm( array $errors = [] ): void {
		$out = $this->getOutput();
		$csrf = $this->getContext()->getCsrfTokenSet();

		if ( $errors ) {
			$lines = array_map( static function ( $e ) {
				return Html::element( 'li', [], $e );
			}, $errors );
			$out->addHTML( Html::errorBox(
				Html::rawElement( 'ul', [], implode( '', $lines ) )
			) );
		}

		$out->addHTML( Html::rawElement(
			'form',
			[
				'method' => 'post',
				'enctype' => 'multipart/form-data',
				'action' => $this->getPageTitle()->getLocalURL(),
				'class' => 'rs-upload-form',
			],
			Html::element( 'p', [], $this->msg( 'receiptscanner-upload-intro' )->text() )
			. Html::rawElement(
				'fieldset',
				[ 'class' => 'rs-upload-kind' ],
				Html::element( 'legend', [],
					$this->msg( 'receiptscanner-upload-kind-legend' )->text() )
				. $this->kindRadio( ReceiptKind::Expense,
					$this->msg( 'receiptscanner-kind-expense' )->text(), true )
				. ' '
				. $this->kindRadio( ReceiptKind::Income,
					$this->msg( 'receiptscanner-kind-income' )->text(), false )
			)
			. Html::rawElement( 'div', [ 'class' => 'rs-upload-picker' ],
				Html::rawElement( 'label',
					[ 'class' => 'mw-ui-button rs-upload-pick' ],
					$this->msg( 'receiptscanner-upload-choose' )->escaped()
					. Html::element( 'input', [
						'type' => 'file',
						'name' => self::FILE_FIELD . '[]',
						'accept' => '.pdf,.jpg,.jpeg,.png,.heic,.heif',
						'multiple' => '',
						'required' => '',
						'class' => 'rs-upload-input',
					] )
				)
				. ' '
				. Html::element( 'span', [ 'class' => 'rs-upload-chosen' ], '' )
			)
			. Html::hidden( 'wpEditToken', $csrf->getToken() )
			// Filled in by ext.receiptscanner.upload.js on file-input change.
			// Compared against $_FILES count on submit to detect when PHP's
			// max_file_uploads / post_max_size silently truncated the request.
			. Html::hidden( self::COUNT_FIELD, '', [ 'class' => 'rs-upload-count' ] )
			. Html::rawElement( 'div', [ 'class' => 'rs-upload-submit' ],
				Html::element(
					'button',
					[
						'type' => 'submit',
						'class' => 'mw-ui-button mw-ui-progressive',
					],
					$this->msg( 'receiptscanner-upload-submit' )->text()
				)
			)
		) );
	}

	/**
	 * Replace every dot except the extension dot with an underscore.
	 * Public static so it is unit-testable.
	 */
	public static function sanitizeFilename( string $name ): string {
		$dot = strrpos( $name, '.' );
		if ( $dot === false || $dot === 0 ) {
			return $name;
		}
		$stem = substr( $name, 0, $dot );
		$ext = substr( $name, $dot );
		return str_replace( '.', '_', $stem ) . $ext;
	}

	private function kindRadio( ReceiptKind $kind, string $label, bool $checked ): string {
		$attrs = [
			'type' => 'radio',
			'name' => self::KIND_FIELD,
			'value' => $kind->value,
			'id' => "wp-rs-kind-{$kind->value}",
		];
		if ( $checked ) {
			$attrs['checked'] = '';
		}
		return Html::element( 'input', $attrs )
			. ' '
			. Html::element( 'label', [ 'for' => "wp-rs-kind-{$kind->value}" ], $label )
			. ' ';
	}

	/**
	 * Normalize PHP's parallel-array multi-file `$_FILES[<field>]` shape
	 * into a per-file list (WebRequestUpload can't iterate it).
	 *
	 * @return list<array{name:string, tmp_name:?string, error:int, size:int}>
	 */
	private function collectUploadedFiles( string $field ): array {
		$files = $_FILES[$field] ?? null;
		if ( !$files || empty( $files['name'] ) ) {
			return [];
		}
		$names = (array)$files['name'];
		$out = [];
		foreach ( $names as $i => $name ) {
			$out[] = [
				'name' => (string)$name,
				'tmp_name' => $files['tmp_name'][$i] ?? null,
				'error' => (int)( $files['error'][$i] ?? UPLOAD_ERR_NO_FILE ),
				'size' => (int)( $files['size'][$i] ?? 0 ),
			];
		}
		return $out;
	}

	private function handleUpload(): void {
		$user = $this->getUser();
		$kind = ReceiptKind::tryFrom(
			(string)$this->getRequest()->getVal( self::KIND_FIELD, '' )
		) ?? ReceiptKind::Expense;

		$uploads = $this->collectUploadedFiles( self::FILE_FIELD );

		// The JS records the client-selected file count in a hidden field;
		// fewer $_FILES entries means PHP's max_file_uploads /
		// post_max_size silently dropped the rest.
		$reportedCount = $this->getRequest()->getInt( self::COUNT_FIELD );
		$truncated = $reportedCount > 0 && $reportedCount > count( $uploads );

		if ( !$uploads ) {
			// Nothing chosen, or the whole POST body exceeded post_max_size.
			$msg = $truncated
				? $this->msg( 'receiptscanner-upload-truncated' )
					->numParams( $reportedCount )->text()
				: $this->msg( 'receiptscanner-upload-nofile' )->text();
			$this->showForm( [ $msg ] );
			return;
		}

		$state = self::emptyUploadState();
		// SHAs seen earlier in this batch — byte-identical re-picks get
		// their own counter, distinct from "already in the wiki" dupes.
		$seenInBatch = [];
		foreach ( $uploads as $u ) {
			$this->processUpload( $u, $kind, $user, $seenInBatch, $state );
		}

		// Intra-batch dupes count as queued (their first occurrence is);
		// alreadyReviewed files are saved, not queued, but still advance
		// to the review page so the skip can be reported.
		$queued = $state['newUploads'] + $state['reEnqueued']
			+ $state['alreadyActive'] + $state['intraBatchDupes'];

		// Stay on the form only when nothing happened at all.
		if ( $queued === 0 && $state['alreadyReviewed'] === 0 ) {
			if ( $truncated ) {
				array_unshift( $state['errors'],
					$this->msg( 'receiptscanner-upload-truncated' )
						->numParams( $reportedCount )->text() );
			}
			$this->showForm( $state['errors'] );
			return;
		}

		// Advance to the review page; the flash summary renders there.
		$this->getRequest()->getSession()->set( 'rs-upload-flash', [
			'queued' => $queued,
			'newUploads' => $state['newUploads'],
			'reEnqueued' => $state['reEnqueued'],
			'alreadyActive' => $state['alreadyActive'],
			'intraBatchDupes' => $state['intraBatchDupes'],
			'alreadyReviewed' => $state['alreadyReviewed'],
			'truncated' => $truncated,
			'reported' => $reportedCount,
			'errors' => $state['errors'],
			'renamed' => $state['renamed'],
		] );
		$this->getOutput()->redirect(
			SpecialPage::getTitleFor( 'ReceiptReview' )->getLocalURL()
		);
	}

	/**
	 * Fresh per-batch state struct for processUpload(). Public for tests.
	 *
	 * @return array{errors:list<string>, renamed:list<array{from:string,to:string}>, newUploads:int, reEnqueued:int, alreadyActive:int, intraBatchDupes:int, alreadyReviewed:int}
	 */
	public static function emptyUploadState(): array {
		return [
			'errors' => [],
			'renamed' => [],
			'newUploads' => 0,
			'reEnqueued' => 0,
			'alreadyActive' => 0,
			'intraBatchDupes' => 0,
			'alreadyReviewed' => 0,
		];
	}

	/**
	 * Classify and dispatch one uploaded file, accumulating into $state
	 * and $seenInBatch. Public so the SHA-based branches are unit-testable.
	 *
	 * @param array{name:string, tmp_name:?string, error:int, size:int} $u
	 * @param array<string,bool> &$seenInBatch
	 * @param array $state
	 */
	public function processUpload(
		array $u,
		ReceiptKind $kind,
		$user,
		array &$seenInBatch,
		array &$state
	): void {
		if ( $u['error'] !== UPLOAD_ERR_OK || !$u['tmp_name'] || !$u['name'] ) {
			$state['errors'][] = $this->msg( 'receiptscanner-upload-rejected' )
				->numParams( $u['error'] )->plaintextParams( $u['name'] ?: '?' )->text();
			return;
		}

		// Content already in the wiki? Skip the upload and (re-)enqueue
		// the existing File: — re-running an upload means "reprocess".
		$sha1 = FSFile::getSha1Base36FromPath( $u['tmp_name'] );
		if ( $sha1 !== false ) {
			if ( isset( $seenInBatch[$sha1] ) ) {
				$state['intraBatchDupes']++;
				return;
			}
			$seenInBatch[$sha1] = true;

			$existing = $this->repoGroup->findFileFromKey( $sha1 );
			if ( $existing ) {
				if ( $this->queueStore->findActiveBySha1( $sha1 ) ) {
					$state['alreadyActive']++;
				} elseif ( $this->queueStore->findSavedBySha1( $sha1 ) ) {
					// Already saved into a receipt page — the page is the
					// source of truth, so don't slip it back into the queue.
					$state['alreadyReviewed']++;
				} else {
					$rsqId = $this->queueStore->enqueue(
						$sha1,
						$existing->getName(),
						$user->getId(),
						$kind
					);
					$this->jobQueueGroup->lazyPush(
						new JobSpecification(
							'ReceiptScanJob', [ 'rsq_id' => $rsqId ], [], $existing->getTitle()
						)
					);
					$state['reEnqueued']++;
				}
				return;
			}
		}

		// MW's upload validator checks every dot-separated filename
		// segment against $wgProhibitedFileExtensions ("Name.com.pdf"
		// fails on "com"), so replace embedded dots first.
		$wikiName = self::sanitizeFilename( $u['name'] );
		if ( $wikiName !== $u['name'] ) {
			$state['renamed'][] = [ 'from' => $u['name'], 'to' => $wikiName ];
		}

		$upload = new UploadFromFile();
		$upload->initializePathInfo( $wikiName, $u['tmp_name'], $u['size'], true );

		$verification = $upload->verifyUpload();
		if ( $verification['status'] !== \UploadBase::OK ) {
			$state['errors'][] = $this->msg( 'receiptscanner-upload-rejected' )
				->numParams( $verification['status'] )
				->plaintextParams( $u['name'] )->text();
			return;
		}

		// UploadFromFile doesn't check page protection or reupload rights.
		$permErrors = $upload->verifyTitlePermissions( $user );
		if ( $permErrors !== true ) {
			$state['errors'][] = $this->msg( 'receiptscanner-upload-forbidden' )
				->plaintextParams( $wikiName )->text();
			return;
		}

		// Identical content was handled above via SHA-1, so this title
		// holds different content — don't publish a new revision over it.
		$localFile = $upload->getLocalFile();
		if ( $localFile && $localFile->exists() ) {
			$state['errors'][] = $this->msg( 'receiptscanner-upload-name-collision' )
				->plaintextParams( $wikiName )->text();
			return;
		}

		$status = $upload->performUpload(
			$this->msg( 'receiptscanner-upload-comment' )->inContentLanguage()->text(),
			'',
			false,
			$user
		);
		if ( !$status->isGood() ) {
			$state['errors'][] = $u['name'] . ': '
				. $status->getWikiText( false, false, $this->getLanguage() );
			return;
		}

		$file = $upload->getLocalFile();
		$rsqId = $this->queueStore->enqueue(
			$file->getSha1(),
			$file->getName(),
			$user->getId(),
			$kind
		);
		$this->jobQueueGroup->lazyPush(
			new JobSpecification( 'ReceiptScanJob', [ 'rsq_id' => $rsqId ], [], $file->getTitle() )
		);
		$state['newUploads']++;
	}
}
