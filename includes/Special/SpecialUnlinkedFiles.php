<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Services\UnlinkedFilesStore;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;
use RepoGroup;

/**
 * List File: pages that no Expense or Income page references (neither
 * as the primary `file` nor in `supplemental_files`). Useful for
 * finding uploads that were never attached to an entry. The query
 * itself lives in UnlinkedFilesStore.
 */
class SpecialUnlinkedFiles extends SpecialPage {

	private const LIMIT = 100;

	public function __construct(
		private readonly UnlinkedFilesStore $unlinkedFilesStore,
		private readonly RepoGroup $repoGroup,
		private readonly QueueStore $queueStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( 'UnlinkedFiles' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();

		// Each row rendered below carries per-kind "Process" buttons —
		// self-submitting POST forms targeting this same page. A POST here
		// means one was clicked: enqueue that file, then land the user on
		// Special:ReceiptReview to watch it. (The Delete button is a plain
		// link into core's ?action=delete flow, no handling here.)
		$req = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if (
			$req->wasPosted()
			&& $csrf->matchToken( $req->getVal( 'wpEditToken' ) )
		) {
			$filename = $req->getRawVal( 'process' );
			$kind = ReceiptKind::tryFrom( (string)$req->getRawVal( 'kind', '' ) )
				?? ReceiptKind::Expense;
			if ( $filename ) {
				$this->enqueueExistingFile( $filename, $kind );
				$this->getOutput()->redirect(
					SpecialPage::getTitleFor( 'ReceiptReview' )->getLocalURL()
				);
				return;
			}
		}

		$unlinked = $this->unlinkedFilesStore->findUnlinkedFiles( self::LIMIT );
		$this->renderList( $unlinked );
	}

	private function enqueueExistingFile( string $filename, ReceiptKind $kind ): void {
		$file = $this->repoGroup->findFile( $filename );
		if ( !$file ) {
			return;
		}
		// An active (pending/processing/ready) row already covers this
		// file; failed and consumed rows don't block re-enqueueing.
		if ( $this->queueStore->findActiveBySha1( $file->getSha1() ) ) {
			return;
		}
		$rsqId = $this->queueStore->enqueue(
			$file->getSha1(),
			$file->getName(),
			$this->getUser()->getId(),
			$kind
		);
		$this->jobQueueGroup->lazyPush(
			new JobSpecification( 'ReceiptScanJob', [ 'rsq_id' => $rsqId ], [], $file->getTitle() )
		);
	}

	private function renderList( array $unlinked ): void {
		$out = $this->getOutput();
		if ( !$unlinked ) {
			$out->addWikiTextAsInterface(
				$this->msg( 'receiptscanner-unlinked-empty' )->plain()
			);
			return;
		}

		$out->addHTML( Html::element( 'p', [],
			$this->msg( 'receiptscanner-unlinked-intro' )
				->numParams( count( $unlinked ) )->text()
		) );

		$linkRenderer = $this->getLinkRenderer();
		$rows = '';
		foreach ( $unlinked as $title ) {
			$display = str_replace( '_', ' ', $title );
			$fileTitle = $this->titleFactory->makeTitle( NS_FILE, $title );

			// Synchronous transform, like core's Special:NewFiles: Canasta
			// has no thumb.php or 404 renderer, so a deferred thumb URL
			// would never be generated. Thumbs are cached after first render.
			$file = $this->repoGroup->findFile( $fileTitle );
			$thumbLink = '';
			if ( $file ) {
				$thumb = $file->transform( [ 'width' => 120 ] );
				if ( $thumb ) {
					$thumbLink = $thumb->toHtml( [ 'desc-link' => true ] );
				}
			}
			$nameLink = $linkRenderer->makeLink( $fileTitle, $display );

			$processExpense = $this->processButton(
				$display, ReceiptKind::Expense,
				$this->msg( 'receiptscanner-unlinked-process-expense' )->text()
			);
			$processIncome = $this->processButton(
				$display, ReceiptKind::Income,
				$this->msg( 'receiptscanner-unlinked-process-income' )->text()
			);
			// Core's confirmation page carries the permission check, the
			// reason field, and the log entry; nothing to handle here.
			$deleteButton = $this->getAuthority()->probablyCan( 'delete', $fileTitle )
				? ' ' . Html::element( 'a', [
					'href' => $fileTitle->getLocalURL( [ 'action' => 'delete' ] ),
					'class' => 'mw-ui-button mw-ui-destructive',
				], $this->msg( 'receiptscanner-unlinked-delete' )->text() )
				: '';

			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [ 'class' => 'rs-unlinked-thumb-cell' ], $thumbLink )
				. Html::rawElement( 'td', [],
					Html::rawElement( 'div', [ 'class' => 'rs-unlinked-name' ], $nameLink )
					. Html::rawElement( 'div', [ 'class' => 'rs-unlinked-actions' ],
						$processExpense . ' ' . $processIncome . $deleteButton )
				)
			);
		}

		$out->addHTML( Html::rawElement( 'table',
			[ 'class' => 'wikitable rs-unlinked-table' ], $rows ) );
		$out->addModuleStyles( 'ext.receiptscanner.unlinked' );
	}

	private function processButton( string $filename, ReceiptKind $kind, string $label ): string {
		return CsrfPostButton::render(
			$this->getContext(),
			$this->getPageTitle(),
			'process',
			$filename,
			$label,
			[ 'kind' => $kind->value ],
			[ 'class' => 'rs-unlinked-process-form' ]
		);
	}
}
