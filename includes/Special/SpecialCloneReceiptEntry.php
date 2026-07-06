<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Clone an Expense/Income entry into a fresh PageForms draft — for
 * receipts that cover multiple line items, each needing its own entry
 * with the same file and most fields pre-populated.
 */
class SpecialCloneReceiptEntry extends UnlistedSpecialPage {

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( 'CloneReceiptEntry' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();

		$result = $this->planClone( (string)$subPage );
		if ( isset( $result['error'] ) ) {
			$this->getOutput()->showErrorPage( 'errorpagetitle', $result['error'] );
			return;
		}
		$this->getOutput()->redirect( $result['redirect'] );
	}

	/**
	 * Resolve $subPage and produce an error key or the FormEdit redirect
	 * URL. Public for unit tests; execute() just dispatches the result.
	 *
	 * @return array{error?:string, redirect?:string}
	 */
	public function planClone( string $subPage ): array {
		if ( $subPage === '' ) {
			return [ 'error' => 'receiptscanner-clone-no-source' ];
		}

		$sourceTitle = $this->titleFactory->newFromText( $subPage );
		if ( !$sourceTitle || !$sourceTitle->exists() ) {
			return [ 'error' => 'receiptscanner-clone-source-not-found' ];
		}

		$kind = ReceiptKind::tryFromNamespace( $sourceTitle->getNamespace() );
		if ( !$kind ) {
			return [ 'error' => 'receiptscanner-clone-not-receipt' ];
		}

		$formName = $kind->formName();

		$content = $this->wikiPageFactory->newFromTitle( $sourceTitle )->getContent();
		if ( !$content || !method_exists( $content, 'getText' ) ) {
			return [ 'error' => 'receiptscanner-clone-not-wikitext' ];
		}

		$fields = BulkEditService::parseTemplateFields( $content->getText() );

		$prefill = [];
		foreach ( $fields as $key => $value ) {
			// The clone has no queue row of its own; drop the link.
			if ( $key === 'queue_id' ) {
				continue;
			}
			// Stored values are entity-encoded (NotesEscaper); decode for
			// the form so the user edits readable text, not &#124; runs.
			$prefill["{$formName}[$key]"] = html_entity_decode(
				$value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$target = SpecialPage::getTitleFor( 'FormEdit', $formName );
		return [ 'redirect' => $target->getLocalURL( $prefill ) ];
	}
}
