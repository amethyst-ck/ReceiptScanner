<?php

namespace MediaWiki\Extension\ReceiptScanner\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\CurrencySymbol;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use Parser;

/**
 * The {{#receiptscanner_*}} parser functions used by the templates,
 * forms, and dashboard. Each render method documents its own function.
 */
readonly class ParserHooks implements ParserFirstCallInitHook {

	public const CONSTRUCTOR_OPTIONS = [ 'ReceiptScannerSystemCurrency' ];

	private ServiceOptions $options;

	public function __construct(
		private CategoryVocabulary $vocabulary,
		private UserStore $userStore,
		Config $mainConfig
	) {
		$this->options = new ServiceOptions(
			self::CONSTRUCTOR_OPTIONS, $mainConfig
		);
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'receiptscanner_categories',      $this->renderCategories( ... ) );
		$parser->setFunctionHook( 'receiptscanner_currency_symbol', $this->renderCurrencySymbol( ... ) );
		$parser->setFunctionHook( 'receiptscanner_format_amount',   $this->renderFormatAmount( ... ) );
		$parser->setFunctionHook( 'receiptscanner_users',           $this->renderUsers( ... ) );
		$parser->setFunctionHook( 'receiptscanner_truncate',        $this->renderTruncate( ... ) );
		$parser->setFunctionHook( 'receiptscanner_system_currency', $this->renderSystemCurrency( ... ) );
		$parser->setFunctionHook( 'receiptscanner_dashboard',       $this->renderDashboard( ... ) );
		$parser->setFunctionHook( 'receiptscanner_form_actions',    $this->renderFormActions( ... ) );
		$parser->setFunctionHook( 'receiptscanner_file_url',        $this->renderFileUrl( ... ) );
	}

	/**
	 * Render width for "click to view" links routed via the thumbnailer.
	 * Exported to JS as wgReceiptScannerViewWidth (see MainHooks).
	 */
	public const VIEW_WIDTH = 1500;

	/**
	 * File extensions browsers can't render inline; routed via a JPEG
	 * thumb. Exported to JS as wgReceiptScannerRenderExts (see MainHooks).
	 */
	public const RENDER_EXTENSIONS = [ 'heic', 'heif' ];

	/**
	 * {{#receiptscanner_file_url:FILENAME}} — click-through URL for a
	 * receipt thumbnail: Special:FilePath, with ?width= appended for
	 * HEIC/HEIF so the HeicHandler pipeline serves a JPEG.
	 */
	public function renderFileUrl(
		Parser $parser,
		string $filename = ''
	): string {
		if ( $filename === '' ) {
			return '';
		}
		$title = SpecialPage::getTitleFor( 'FilePath', $filename );
		$needsRenderRe = '/\.(' . implode( '|', self::RENDER_EXTENSIONS ) . ')$/i';
		// getFullURL: `[URL text]` wikitext only accepts absolute URLs.
		if ( preg_match( $needsRenderRe, $filename ) ) {
			return $title->getFullURL( [ 'width' => self::VIEW_WIDTH ] );
		}
		return $title->getFullURL();
	}

	/**
	 * {{#receiptscanner_form_actions:}} — Clone button routing to
	 * Special:CloneReceiptEntry for the page being edited; empty when
	 * the form is in create mode (nothing to clone from).
	 *
	 * @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderFormActions( Parser $parser ): array {
		$title = $parser->getTitle();
		if ( !$title || !$title->exists() ) {
			return [ '', 'isHTML' => true, 'noparse' => true ];
		}
		if ( !ReceiptKind::tryFromNamespace( $title->getNamespace() ) ) {
			return [ '', 'isHTML' => true, 'noparse' => true ];
		}
		$key = $title->getPrefixedDBkey();
		$cloneUrl = SpecialPage::getTitleFor( 'CloneReceiptEntry', $key )->getLocalURL();
		$html = Html::rawElement( 'span', [ 'class' => 'rs-form-actions' ],
			Html::element( 'a', [
				'href' => $cloneUrl,
				'class' => 'mw-ui-button',
			], wfMessage( 'receiptscanner-form-clone-link' )->text() )
		);
		return [ $html, 'isHTML' => true, 'noparse' => true ];
	}

	/**
	 * {{#receiptscanner_dashboard:}} — 6-tile launcher grid: the four
	 * Special pages plus Form:Expense / Form:Income. Styles travel with
	 * Template:Receipt dashboard via TemplateStyles.
	 *
	 * @return array{0:string,isHTML:bool,noparse:bool}
	 */
	public function renderDashboard( Parser $parser ): array {
		$tiles = [
			[
				'href' => SpecialPage::getTitleFor( 'UploadReceipt' )->getLocalURL(),
				'icon' => '📤',
				'label' => wfMessage( 'receiptscanner-dashboard-upload-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-upload-sub' )->text(),
			],
			[
				'href' => SpecialPage::getTitleFor( 'FormEdit', 'Expense' )->getLocalURL(),
				'icon' => '🧾',
				'label' => wfMessage( 'receiptscanner-dashboard-new-expense-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-new-expense-sub' )->text(),
			],
			[
				'href' => SpecialPage::getTitleFor( 'FormEdit', 'Income' )->getLocalURL(),
				'icon' => '💵',
				'label' => wfMessage( 'receiptscanner-dashboard-new-income-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-new-income-sub' )->text(),
			],
			[
				'href' => SpecialPage::getTitleFor( 'ReceiptReview' )->getLocalURL(),
				'icon' => '🔍',
				'label' => wfMessage( 'receiptscanner-dashboard-review-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-review-sub' )->text(),
			],
			[
				'href' => SpecialPage::getTitleFor( 'Ledger' )->getLocalURL(),
				'icon' => '📊',
				'label' => wfMessage( 'receiptscanner-dashboard-ledger-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-ledger-sub' )->text(),
			],
			[
				'href' => SpecialPage::getTitleFor( 'UnlinkedFiles' )->getLocalURL(),
				'icon' => '🧹',
				'label' => wfMessage( 'receiptscanner-dashboard-unlinked-label' )->text(),
				'sub'   => wfMessage( 'receiptscanner-dashboard-unlinked-sub' )->text(),
			],
		];

		$tileHtml = '';
		foreach ( $tiles as $t ) {
			$tileHtml .= Html::rawElement( 'a',
				[ 'href' => $t['href'], 'class' => 'rs-dashboard-tile' ],
				Html::element( 'span', [ 'class' => 'rs-dashboard-icon' ], $t['icon'] )
				. Html::element( 'span', [ 'class' => 'rs-dashboard-label' ], $t['label'] )
				. Html::element( 'span', [ 'class' => 'rs-dashboard-sub' ], $t['sub'] )
			);
		}

		return [
			Html::rawElement( 'div', [ 'class' => 'rs-dashboard' ], $tileHtml ),
			'isHTML' => true,
			'noparse' => true,
		];
	}

	/** {{#receiptscanner_system_currency:}} — the accounting currency. */
	public function renderSystemCurrency( Parser $parser ): string {
		return (string)$this->options->get( 'ReceiptScannerSystemCurrency' );
	}

	/** {{#receiptscanner_truncate:STRING|MAX|SUFFIX}} — multibyte cap. */
	public function renderTruncate(
		Parser $parser,
		string $string = '',
		string $max = '',
		string $suffix = '…'
	): string {
		$n = (int)$max;
		if ( $n <= 0 || mb_strlen( $string ) <= $n ) {
			return $string;
		}
		return mb_substr( $string, 0, $n ) . $suffix;
	}

	/** {{#receiptscanner_users:SEPARATOR}} — joined real usernames. */
	public function renderUsers(
		Parser $parser,
		string $separator = ','
	): string {
		if ( $separator === '' ) {
			$separator = ',';
		}
		return implode( $separator, $this->userStore->getUsernames() );
	}

	/** {{#receiptscanner_categories:KIND|SEPARATOR}} — joined vocabulary. */
	public function renderCategories(
		Parser $parser,
		string $kind = 'expense',
		string $separator = ','
	): string {
		if ( $separator === '' ) {
			$separator = ',';
		}
		$receiptKind = ReceiptKind::tryFrom( $kind ) ?? ReceiptKind::Expense;

		// Register the vocabulary page as a parser-cache dependency
		// (templatelinks edge), so editing it invalidates the cached form.
		$vocabTitle = $this->vocabulary->getCategoryPageTitle( $receiptKind );
		if ( $vocabTitle && $vocabTitle->exists() ) {
			$parser->getOutput()->addTemplate(
				$vocabTitle,
				$vocabTitle->getArticleID(),
				$vocabTitle->getLatestRevID()
			);
		}

		return implode( $separator, $this->vocabulary->getPaths( $receiptKind ) );
	}

	/**
	 * {{#receiptscanner_format_amount:AMOUNT|CODE}} — accounting-style
	 * display amount: symbol, thousands separators, two decimals
	 * ("$1,234.50"). CODE defaults to the system currency. Non-numeric
	 * input passes through unchanged so templates degrade gracefully.
	 */
	public function renderFormatAmount(
		Parser $parser,
		string $amount = '',
		string $code = ''
	): string {
		$trimmed = trim( $amount );
		if ( !is_numeric( $trimmed ) ) {
			return $amount;
		}
		if ( trim( $code ) === '' ) {
			$code = (string)$this->options->get( 'ReceiptScannerSystemCurrency' );
		}
		return CurrencySymbol::format( (float)$trimmed, $code );
	}

	/** {{#receiptscanner_currency_symbol:CODE}} — display symbol. */
	public function renderCurrencySymbol(
		Parser $parser,
		string $code = ''
	): string {
		return CurrencySymbol::forCode( $code );
	}
}
