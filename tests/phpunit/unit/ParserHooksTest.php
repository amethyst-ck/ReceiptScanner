<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Hooks\ParserHooks;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use MediaWiki\HookContainer\HookContainer;
use Parser;

if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
	define( 'NS_RECEIPTSCANNER_EXPENSE', 3000 );
	define( 'NS_RECEIPTSCANNER_INCOME', 3002 );
}

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Hooks\ParserHooks
 */
class ParserHooksTest extends MediaWikiUnitTestCase {

	/**
	 * Install a mock global service container so the parser functions that
	 * build Special-page URLs via SpecialPage::getTitleFor()->getLocalURL()
	 * can resolve without a live MediaWiki. Only the handful of services
	 * those URL paths touch are stubbed.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wgArticlePath'] = '/wiki/$1';
		$GLOBALS['wgScript'] = '/w/index.php';
		$GLOBALS['wgServer'] = 'https://wiki.example';
		$GLOBALS['wgMainPageIsDomainRoot'] = false;
		$GLOBALS['wgActionPaths'] = [];
		$GLOBALS['wgVariantArticlePath'] = false;

		$spf = $this->createMock( SpecialPageFactory::class );
		// Echo the requested special page + subpage back as the local name.
		$spf->method( 'getLocalNameFor' )->willReturnCallback(
			static function ( $name, $subpage = false ) {
				return $subpage === false || $subpage === null
					? $name : "$name/$subpage";
			}
		);

		$interwiki = $this->createMock( \MediaWiki\Interwiki\InterwikiLookup::class );
		$interwiki->method( 'fetch' )->willReturn( false );

		$hookContainer = $this->createMock( HookContainer::class );
		$hookContainer->method( 'run' )->willReturn( true );

		$urlUtils = $this->createMock( UrlUtils::class );
		// Prepend the server to make an absolute URL, mirroring expand().
		$urlUtils->method( 'expand' )->willReturnCallback(
			static fn ( $url ) => 'https://wiki.example' . $url
		);

		$titleFormatter = $this->createMock( \MediaWiki\Title\TitleFormatter::class );
		$titleFormatter->method( 'getNamespaceName' )->willReturnCallback(
			static fn ( $ns ) => $ns === NS_SPECIAL ? 'Special' : ''
		);

		// get() returning false makes Message::format() short-circuit to
		// the ⧼key⧽ placeholder, skipping the transform pipeline. The
		// form-action tests assert on the button href, not its label.
		$messageCache = $this->createMock( \MessageCache::class );
		$messageCache->method( 'get' )->willReturn( false );

		// wfMessage()->getLanguage() falls back to RequestContext::getMain();
		// stub the main context so it resolves without live services.
		$lang = $this->createMock( \MediaWiki\Language\Language::class );
		$lang->method( 'getCode' )->willReturn( 'en' );
		$mainContext = $this->createMock( \MediaWiki\Context\RequestContext::class );
		$mainContext->method( 'getLanguage' )->willReturn( $lang );
		$rcProp = new \ReflectionProperty( \MediaWiki\Context\RequestContext::class, 'instance' );
		$rcProp->setAccessible( true );
		$rcProp->setValue( null, $mainContext );

		$services = $this->getMockBuilder( MediaWikiServices::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getSpecialPageFactory',
				'getInterwikiLookup',
				'getHookContainer',
				'getUrlUtils',
				'getTitleFormatter',
				'getMessageCache',
			] )
			->getMock();
		$services->method( 'getSpecialPageFactory' )->willReturn( $spf );
		$services->method( 'getInterwikiLookup' )->willReturn( $interwiki );
		$services->method( 'getHookContainer' )->willReturn( $hookContainer );
		$services->method( 'getUrlUtils' )->willReturn( $urlUtils );
		$services->method( 'getTitleFormatter' )->willReturn( $titleFormatter );
		$services->method( 'getMessageCache' )->willReturn( $messageCache );

		// Install the mock as the global instance. Unit-test mode has no
		// live instance to save/restore, so set the static directly and
		// clear it in tearDown.
		$prop = new \ReflectionProperty( MediaWikiServices::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, $services );
		MediaWikiServices::allowGlobalInstanceAfterUnitTests();
	}

	protected function tearDown(): void {
		MediaWikiServices::disallowGlobalInstanceInUnitTests();
		$prop = new \ReflectionProperty( MediaWikiServices::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		$rcProp = new \ReflectionProperty( \MediaWiki\Context\RequestContext::class, 'instance' );
		$rcProp->setAccessible( true );
		$rcProp->setValue( null, null );
		parent::tearDown();
	}

	private function newHooks(
		?CategoryVocabulary $vocab = null,
		?UserStore $userStore = null,
		string $systemCurrency = 'USD'
	): ParserHooks {
		return new ParserHooks(
			$vocab ?? $this->createMock( CategoryVocabulary::class ),
			$userStore ?? $this->createMock( UserStore::class ),
			new HashConfig( [ 'ReceiptScannerSystemCurrency' => $systemCurrency ] )
		);
	}

	private function parserMock(): Parser {
		return $this->createMock( Parser::class );
	}

	// ----- renderTruncate -----

	public function testTruncateReturnsShortStringUnchanged(): void {
		$hooks = $this->newHooks();
		$this->assertSame(
			'Hello',
			$hooks->renderTruncate( $this->parserMock(), 'Hello', '10' )
		);
	}

	public function testTruncateCapsLongStringWithEllipsis(): void {
		$hooks = $this->newHooks();
		$this->assertSame(
			'Hello…',
			$hooks->renderTruncate( $this->parserMock(), 'Hello, world', '5' )
		);
	}

	public function testTruncateSupportsCustomSuffix(): void {
		$hooks = $this->newHooks();
		$this->assertSame(
			'Hello [more]',
			$hooks->renderTruncate( $this->parserMock(), 'Hello, world', '5', ' [more]' )
		);
	}

	public function testTruncateIsMultibyteSafe(): void {
		$hooks = $this->newHooks();
		// 5 chars from a 6-char multibyte string.
		$this->assertSame(
			'héllo…',
			$hooks->renderTruncate( $this->parserMock(), 'héllo!world', '5' )
		);
	}

	public function testTruncateZeroOrNegativeMaxReturnsInput(): void {
		$hooks = $this->newHooks();
		$this->assertSame(
			'Hello',
			$hooks->renderTruncate( $this->parserMock(), 'Hello', '0' )
		);
		$this->assertSame(
			'Hello',
			$hooks->renderTruncate( $this->parserMock(), 'Hello', '-1' )
		);
	}

	// ----- renderCurrencySymbol -----

	public function testCurrencySymbolKnownCode(): void {
		$hooks = $this->newHooks();
		$this->assertSame( '$', $hooks->renderCurrencySymbol( $this->parserMock(), 'USD' ) );
		$this->assertSame( '€', $hooks->renderCurrencySymbol( $this->parserMock(), 'EUR' ) );
	}

	public function testCurrencySymbolUnknownPassesThrough(): void {
		$hooks = $this->newHooks();
		$this->assertSame( 'XYZ', $hooks->renderCurrencySymbol( $this->parserMock(), 'XYZ' ) );
	}

	// ----- renderFormatAmount -----

	public function testFormatAmountCommasAndTwoDecimals(): void {
		$hooks = $this->newHooks();
		$this->assertSame( '$1,234.50',
			$hooks->renderFormatAmount( $this->parserMock(), '1234.5', 'USD' ) );
		$this->assertSame( '$1,000,000.50',
			$hooks->renderFormatAmount( $this->parserMock(), '1000000.5', 'USD' ) );
		$this->assertSame( '€25,000.00',
			$hooks->renderFormatAmount( $this->parserMock(), '25000', 'EUR' ) );
	}

	public function testFormatAmountDefaultsToSystemCurrency(): void {
		$hooks = $this->newHooks( null, null, 'EUR' );
		$this->assertSame( '€9.99',
			$hooks->renderFormatAmount( $this->parserMock(), '9.99' ) );
	}

	public function testFormatAmountNegativeUsesParentheses(): void {
		$hooks = $this->newHooks();
		$this->assertSame( '($5.30)',
			$hooks->renderFormatAmount( $this->parserMock(), '-5.3', 'USD' ) );
	}

	public function testFormatAmountNonNumericPassesThrough(): void {
		$hooks = $this->newHooks();
		$this->assertSame( '', $hooks->renderFormatAmount( $this->parserMock(), '' ) );
		$this->assertSame( 'n/a', $hooks->renderFormatAmount( $this->parserMock(), 'n/a', 'USD' ) );
	}

	// ----- renderSystemCurrency -----

	public function testSystemCurrencyFromConfig(): void {
		$hooks = $this->newHooks( null, null, 'EUR' );
		$this->assertSame( 'EUR', $hooks->renderSystemCurrency( $this->parserMock() ) );
	}

	// ----- renderUsers -----

	public function testUsersJoinedByDefaultSeparator(): void {
		$userStore = $this->createMock( UserStore::class );
		$userStore->method( 'getUsernames' )->willReturn( [ 'Alice', 'Bob', 'Carol' ] );
		$hooks = $this->newHooks( null, $userStore );
		$this->assertSame(
			'Alice,Bob,Carol',
			$hooks->renderUsers( $this->parserMock() )
		);
	}

	public function testUsersCustomSeparator(): void {
		$userStore = $this->createMock( UserStore::class );
		$userStore->method( 'getUsernames' )->willReturn( [ 'Alice', 'Bob' ] );
		$hooks = $this->newHooks( null, $userStore );
		$this->assertSame(
			'Alice|Bob',
			$hooks->renderUsers( $this->parserMock(), '|' )
		);
	}

	public function testUsersEmptySeparatorFallsBackToComma(): void {
		// MediaWiki passes '' for omitted parser-function args.
		$userStore = $this->createMock( UserStore::class );
		$userStore->method( 'getUsernames' )->willReturn( [ 'Alice', 'Bob' ] );
		$hooks = $this->newHooks( null, $userStore );
		$this->assertSame(
			'Alice,Bob',
			$hooks->renderUsers( $this->parserMock(), '' )
		);
	}

	// ----- renderCategories -----

	public function testCategoriesExpenseKind(): void {
		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->expects( $this->once() )
			->method( 'getPaths' )
			->with( ReceiptKind::Expense )
			->willReturn( [ 'Travel', 'Travel/Meals', 'Office' ] );
		$hooks = $this->newHooks( $vocab );
		$this->assertSame(
			'Travel,Travel/Meals,Office',
			$hooks->renderCategories( $this->parserMock(), 'expense' )
		);
	}

	public function testCategoriesIncomeKind(): void {
		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->method( 'getPaths' )
			->with( ReceiptKind::Income )
			->willReturn( [ 'Sales' ] );
		$hooks = $this->newHooks( $vocab );
		$this->assertSame(
			'Sales',
			$hooks->renderCategories( $this->parserMock(), 'income' )
		);
	}

	public function testCategoriesCustomSeparator(): void {
		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->method( 'getPaths' )->willReturn( [ 'A', 'B', 'C' ] );
		$hooks = $this->newHooks( $vocab );
		$this->assertSame(
			'A|B|C',
			$hooks->renderCategories( $this->parserMock(), 'expense', '|' )
		);
	}

	public function testCategoriesEmptyKindFallsBackToExpense(): void {
		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->expects( $this->once() )
			->method( 'getPaths' )
			->with( ReceiptKind::Expense )
			->willReturn( [ 'Travel' ] );
		$hooks = $this->newHooks( $vocab );
		$this->assertSame(
			'Travel',
			$hooks->renderCategories( $this->parserMock(), '' )
		);
	}

	public function testCategoriesRegistersVocabPageAsParserDependency(): void {
		// When the vocabulary page exists, renderCategories must
		// register it as a parser-cache dependency via
		// ParserOutput::addTemplate — otherwise editing
		// Project:Expense categories wouldn't invalidate Form:Expense's
		// cached parse, and the combobox would serve stale labels until
		// the form was manually purged.
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'getArticleID' )->willReturn( 1234 );
		$title->method( 'getLatestRevID' )->willReturn( 5678 );

		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->method( 'getPaths' )->willReturn( [ 'Travel' ] );
		$vocab->method( 'getCategoryPageTitle' )
			->with( ReceiptKind::Expense )
			->willReturn( $title );

		$out = $this->createMock( ParserOutput::class );
		$out->expects( $this->once() )
			->method( 'addTemplate' )
			->with( $title, 1234, 5678 );

		$parser = $this->parserMock();
		$parser->method( 'getOutput' )->willReturn( $out );

		$hooks = $this->newHooks( $vocab );
		$this->assertSame( 'Travel',
			$hooks->renderCategories( $parser, 'expense' ) );
	}

	// ----- renderFileUrl -----

	public function testFileUrlEmptyReturnsEmpty(): void {
		$hooks = $this->newHooks();
		$this->assertSame( '', $hooks->renderFileUrl( $this->parserMock(), '' ) );
	}

	public function testFileUrlPdfUsesPlainFilePath(): void {
		// Browsers render PDFs inline; don't waste a thumbnail render
		// on them — link to the original via Special:FilePath.
		$hooks = $this->newHooks();
		$url = $hooks->renderFileUrl( $this->parserMock(), 'receipt.pdf' );
		$this->assertStringContainsString( 'Special:FilePath', $url );
		$this->assertStringContainsString( 'receipt.pdf', $url );
		$this->assertStringNotContainsString( 'width=', $url );
		// Must be absolute — the template wraps the result in external-
		// link wikitext, which only fires on http(s)://… URLs.
		$this->assertMatchesRegularExpression( '#^https?://#', $url );
	}

	public function testFileUrlJpegUsesPlainFilePath(): void {
		$hooks = $this->newHooks();
		$url = $hooks->renderFileUrl( $this->parserMock(), 'photo.jpg' );
		$this->assertStringNotContainsString( 'width=', $url );
	}

	public function testFileUrlHeicAddsWidthForBrowserViewability(): void {
		// HEIC and HEIF: browsers don't render them inline, so the
		// link must go through the thumbnailer for a JPEG rendition.
		$hooks = $this->newHooks();
		foreach ( [ 'IMG_9865.HEIC', 'photo.heic', 'something.HEIF' ] as $name ) {
			$url = $hooks->renderFileUrl( $this->parserMock(), $name );
			$this->assertStringContainsString( 'Special:FilePath', $url, $name );
			$this->assertStringContainsString( 'width=1500', $url, $name );
		}
	}

	public function testCategoriesSkipsDependencyWhenVocabPageMissing(): void {
		// If the configured vocabulary page doesn't exist (operator
		// hasn't created it yet), don't add a dependency on a phantom
		// title — and don't crash trying.
		$vocab = $this->createMock( CategoryVocabulary::class );
		$vocab->method( 'getPaths' )->willReturn( [] );
		$vocab->method( 'getCategoryPageTitle' )->willReturn( null );

		$out = $this->createMock( ParserOutput::class );
		$out->expects( $this->never() )->method( 'addTemplate' );

		$parser = $this->parserMock();
		$parser->method( 'getOutput' )->willReturn( $out );

		$hooks = $this->newHooks( $vocab );
		$this->assertSame( '',
			$hooks->renderCategories( $parser, 'expense' ) );
	}

	// ----- renderFormActions -----

	private function parserWithTitle( ?Title $title ): Parser {
		$parser = $this->parserMock();
		$parser->method( 'getTitle' )->willReturn( $title );
		return $parser;
	}

	public function testFormActionsEmptyWhenNoTitle(): void {
		// Parser::getTitle() is typed non-nullable, so an auto-stubbed
		// Title (exists() defaults to false) stands in for "no usable
		// title" — renderFormActions returns empty for it.
		$hooks = $this->newHooks();
		$result = $hooks->renderFormActions( $this->parserMock() );
		$this->assertSame( '', $result[0] );
	}

	public function testFormActionsEmptyWhenTitleDoesNotExist(): void {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( false );
		$hooks = $this->newHooks();
		$result = $hooks->renderFormActions( $this->parserWithTitle( $title ) );
		$this->assertSame( '', $result[0] );
	}

	public function testFormActionsEmptyForWrongNamespace(): void {
		// NS_HELP = 12 — buttons must hide.
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'getNamespace' )->willReturn( 12 );
		$hooks = $this->newHooks();
		$result = $hooks->renderFormActions( $this->parserWithTitle( $title ) );
		$this->assertSame( '', $result[0] );
	}

	public function testFormActionsRendersCloneButtonForExpense(): void {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'getNamespace' )->willReturn( NS_RECEIPTSCANNER_EXPENSE );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'Expense:1234' );

		$hooks = $this->newHooks();
		$html = $hooks->renderFormActions( $this->parserWithTitle( $title ) )[0];

		// Clone is the only saved-page action; reprocess is intentionally
		// not surfaced post-save (the page's stored values are the source
		// of truth after human review).
		$this->assertStringContainsString( 'Special:CloneReceiptEntry/Expense:1234', $html );
		$this->assertStringNotContainsString( 'ReprocessReceipt', $html );
		// mw-ui-button on the single <a> tag.
		$this->assertSame( 1, substr_count( $html, 'class="mw-ui-button"' ) );
		// Wrapper class JS uses to find the row.
		$this->assertStringContainsString( 'class="rs-form-actions"', $html );
	}

	public function testFormActionsIsHtmlAndNoparse(): void {
		// Parser-function contract: the second/third array slots tell
		// MediaWiki the output is raw HTML and not to re-parse it (the
		// <a> tags would otherwise get caught by the sanitiser).
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$title->method( 'getNamespace' )->willReturn( NS_RECEIPTSCANNER_INCOME );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'Income:5678' );

		$result = $this->newHooks()->renderFormActions( $this->parserWithTitle( $title ) );
		$this->assertTrue( $result['isHTML'] );
		$this->assertTrue( $result['noparse'] );
	}
}
