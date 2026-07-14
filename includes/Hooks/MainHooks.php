<?php

namespace MediaWiki\Extension\ReceiptScanner\Hooks;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ReceiptScanner\NotesEscaper;
use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\TitleFactory;

/**
 * Catch-all hook handler for non-parser, non-schema concerns: namespace
 * registration, queue-row lifecycle tied to file/page events, module
 * loading per page context, sidebar toolbox links, and the
 * notes-encoding pass over PageForms-generated wikitext.
 */
readonly class MainHooks implements
	BeforePageDisplayHook,
	FileDeleteCompleteHook,
	PageDeleteCompleteHook,
	PageSaveCompleteHook,
	SidebarBeforeOutputHook
{

	/**
	 * Matches the `queue_id=<digits>` template parameter in a saved
	 * receipt page's wikitext (set via the hidden form field).
	 */
	private const QUEUE_ID_PARAM_RE = '/\|\s*queue_id\s*=\s*(\d+)/';

	/**
	 * Declare the Expense and Income namespaces at registration time.
	 * Base index comes from $wgReceiptScannerNamespaceIndex; operator
	 * $wgExtraNamespaces / $wgContentNamespaces entries win over defaults.
	 */
	public static function onRegistration(): void {
		$base = $GLOBALS['wgReceiptScannerNamespaceIndex'] ?? 3000;
		if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
			define( 'NS_RECEIPTSCANNER_EXPENSE', $base );
			define( 'NS_RECEIPTSCANNER_EXPENSE_TALK', $base + 1 );
			define( 'NS_RECEIPTSCANNER_INCOME', $base + 2 );
			define( 'NS_RECEIPTSCANNER_INCOME_TALK', $base + 3 );
		}
		$defaults = [
			NS_RECEIPTSCANNER_EXPENSE => 'Expense',
			NS_RECEIPTSCANNER_EXPENSE_TALK => 'Expense_talk',
			NS_RECEIPTSCANNER_INCOME => 'Income',
			NS_RECEIPTSCANNER_INCOME_TALK => 'Income_talk',
		];
		$GLOBALS['wgExtraNamespaces'] = $GLOBALS['wgExtraNamespaces'] ?? [];
		foreach ( $defaults as $idx => $label ) {
			if ( !isset( $GLOBALS['wgExtraNamespaces'][$idx] ) ) {
				$GLOBALS['wgExtraNamespaces'][$idx] = $label;
			}
		}
		$GLOBALS['wgContentNamespaces'] = $GLOBALS['wgContentNamespaces'] ?? [];
		foreach ( [ NS_RECEIPTSCANNER_EXPENSE, NS_RECEIPTSCANNER_INCOME ] as $ns ) {
			if ( !in_array( $ns, $GLOBALS['wgContentNamespaces'], true ) ) {
				$GLOBALS['wgContentNamespaces'][] = $ns;
			}
		}
	}

	public function __construct(
		private QueueStore $queueStore,
		private CategoryVocabulary $categoryVocabulary,
		private TitleFactory $titleFactory,
		private WikiPageFactory $wikiPageFactory,
		private RevisionLookup $revisionLookup
	) {
	}

	/**
	 * Drop non-consumed queue rows for a deleted file.
	 *
	 * @inheritDoc
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( $oldimage ) {
			// An old revision was deleted, not the file itself.
			return;
		}
		$this->queueStore->deleteNonConsumedBySha1( $file->getSha1() );
	}

	/**
	 * Invalidate the vocabulary cache on vocabulary-page edits;
	 * auto-consume a Ready queue row when its receipt page is saved;
	 * purge affected assignees' user pages.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$title = $wikiPage->getTitle();
		$this->categoryVocabulary->maybeInvalidate( $title );
		if ( !ReceiptKind::tryFromNamespace( $title->getNamespace() ) ) {
			return;
		}
		$this->maybeAutoConsume( $title, $revisionRecord );

		// {{User receipts}} queries have no dependency edge to the Cargo
		// data, so purge the affected assignees' user pages — both the
		// saved assignee and the previous one (bulk re-assignment).
		$names = [ $this->assigneeFromRevision( $revisionRecord ) ];
		$parentId = $revisionRecord->getParentId();
		if ( $parentId ) {
			$parent = $this->revisionLookup->getRevisionById( $parentId );
			if ( $parent ) {
				$names[] = $this->assigneeFromRevision( $parent );
			}
		}
		$this->purgeUserPages( $names );
	}

	/**
	 * Purge the assignee's user page when a receipt page is deleted, so
	 * {{User receipts}} stops listing the deleted entry.
	 *
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		if ( !ReceiptKind::tryFromNamespace( $page->getNamespace() ) ) {
			return;
		}
		$this->purgeUserPages( [ $this->assigneeFromRevision( $deletedRev ) ] );
	}

	private function assigneeFromRevision( RevisionRecord $rev ): ?string {
		$content = $rev->getContent( SlotRecord::MAIN );
		if ( !$content || !method_exists( $content, 'getText' ) ) {
			return null;
		}
		return BulkEditService::parseTemplateFields( $content->getText() )['assignee'] ?? null;
	}

	/** @param array<?string> $names Usernames; blanks and nulls skipped. */
	private function purgeUserPages( array $names ): void {
		foreach ( array_unique( array_filter( $names ) ) as $name ) {
			$userTitle = $this->titleFactory->makeTitleSafe( NS_USER, $name );
			if ( $userTitle && $userTitle->exists() ) {
				$this->wikiPageFactory->newFromTitle( $userTitle )->doPurge();
			}
		}
	}

	private function maybeAutoConsume( $title, $revisionRecord ): void {
		$content = $revisionRecord->getContent( SlotRecord::MAIN );
		if ( !$content || !method_exists( $content, 'getText' ) ) {
			return;
		}
		if ( !preg_match( self::QUEUE_ID_PARAM_RE, $content->getText(), $m ) ) {
			return;
		}
		$rsqId = (int)$m[1];
		$row = $this->queueStore->get( $rsqId );
		// Status check keeps this idempotent across subsequent edits.
		if ( $row && $row['rsq_status'] === QueueStatus::Ready->value ) {
			$this->queueStore->setConsumed( $rsqId, $title->getArticleID() );
		}
	}

	/**
	 * Load the form-polish module on FormEdit pages (special page or the
	 * per-page ?action=formedit tab).
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}
		// PageForms editing has two entry points: Special:FormEdit and
		// the per-page ?action=formedit tab.
		$action = $out->getRequest()->getRawVal( 'action' );
		if ( $title->isSpecial( 'FormEdit' ) || $action === 'formedit' ) {
			$out->addModules( 'ext.receiptscanner.form' );
			$out->addJsConfigVars( [
				'wgReceiptScannerSystemCurrency' =>
					$out->getConfig()->get( 'ReceiptScannerSystemCurrency' ),
				// Shared with the {{#receiptscanner_file_url:}} parser
				// function so the form's preview links behave identically.
				'wgReceiptScannerViewWidth' => ParserHooks::VIEW_WIDTH,
				'wgReceiptScannerRenderExts' => ParserHooks::RENDER_EXTENSIONS,
			] );
		}
	}

	/**
	 * Runs after PageForms composes the wikitext but before saving:
	 * entity-encode every free-text field value so user-typed text (or
	 * sidecar-extracted text from a crafted receipt) can't terminate the
	 * template or inject parameters.
	 *
	 * @param string $formName
	 * @param \MediaWiki\Title\Title|null $contextTitle
	 * @param string &$targetContent
	 * @return bool
	 */
	// HookContainer derives the name: `::` in the hook becomes `__`.
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onPageForms__WritePageData( $formName, &$contextTitle, &$targetContent ): bool {
		if ( $formName !== 'Expense' && $formName !== 'Income' ) {
			return true;
		}
		$values = RequestContext::getMain()->getRequest()->getArray( $formName ) ?? [];
		$targetContent = self::encodeFreeTextValues( $targetContent, $values );
		return true;
	}

	/**
	 * Encode wikitext-significant sequences in every field value. Each
	 * raw form value is located verbatim in the composed wikitext and
	 * replaced with its encoded form — no boundary parsing, so a value
	 * containing newline + `|name=` can't smuggle parameters in.
	 *
	 * @param array<string,mixed> $values Raw form values keyed by field
	 *   name (the `<FormName>[...]` request array).
	 */
	public static function encodeFreeTextValues( string $content, array $values ): string {
		foreach ( $values as $field => $value ) {
			if ( !is_string( $value ) || $value === '' ) {
				continue;
			}
			// Try the raw value plus trimmed / LF-normalized variants, in
			// case PageForms adjusted it before composing.
			$candidates = array_unique( [
				$value,
				trim( $value ),
				str_replace( "\r\n", "\n", $value ),
				trim( str_replace( "\r\n", "\n", $value ) ),
			] );
			foreach ( $candidates as $candidate ) {
				$encoded = NotesEscaper::encode( $candidate );
				if ( $encoded === $candidate ) {
					break;
				}
				$needle = '|' . $field . '=' . $candidate;
				$pos = strpos( $content, $needle );
				if ( $pos !== false ) {
					$content = substr_replace(
						$content, '|' . $field . '=' . $encoded, $pos, strlen( $needle )
					);
					break;
				}
			}
		}
		// Fallback for a notes value PageForms transformed beyond the
		// variants above: boundary-based encode of the composed wikitext.
		// A no-op when the value pass already encoded it.
		return preg_replace_callback(
			'/(\|\s*notes\s*=)(.*?)(?=\n\s*\|\s*[A-Za-z_][A-Za-z0-9_ ]*=|\n\s*\}\})/s',
			static function ( array $m ): string {
				return $m[1] . NotesEscaper::encode( $m[2] );
			},
			$content,
			1
		);
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// The tools all need a login (upload, review, ledger, form edits), so
		// only show the section to logged-in users.
		if ( !$skin->getUser()->isRegistered() ) {
			return;
		}
		// A dedicated sidebar section: the skin renders it as its own
		// group with the receiptscanner-sidebar-heading message as the
		// heading, visually separated from the core menus.
		$sidebar['receiptscanner-sidebar-heading'] = [
			[
				'msg' => 'uploadreceipt',
				'href' => SpecialPage::getTitleFor( 'UploadReceipt' )->getLocalURL(),
				'id' => 't-rs-uploadreceipt',
			],
			[
				'msg' => 'receiptscanner-sidebar-new-expense',
				'href' => SpecialPage::getTitleFor( 'FormEdit', 'Expense' )->getLocalURL(),
				'id' => 't-rs-new-expense',
			],
			[
				'msg' => 'receiptscanner-sidebar-new-income',
				'href' => SpecialPage::getTitleFor( 'FormEdit', 'Income' )->getLocalURL(),
				'id' => 't-rs-new-income',
			],
			[
				'msg' => 'receiptreview',
				'href' => SpecialPage::getTitleFor( 'ReceiptReview' )->getLocalURL(),
				'id' => 't-rs-receiptreview',
			],
			[
				'msg' => 'ledger',
				'href' => SpecialPage::getTitleFor( 'Ledger' )->getLocalURL(),
				'id' => 't-rs-ledger',
			],
			[
				'msg' => 'unlinkedfiles',
				'href' => SpecialPage::getTitleFor( 'UnlinkedFiles' )->getLocalURL(),
				'id' => 't-rs-unlinkedfiles',
			],
		];
	}
}
