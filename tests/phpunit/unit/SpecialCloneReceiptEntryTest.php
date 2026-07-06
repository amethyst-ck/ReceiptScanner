<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\ReceiptScanner\Special\SpecialCloneReceiptEntry;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWikiUnitTestCase;
use WikiPage;

// MainHooks::onRegistration defines these at extension load; unit
// tests run without that registration so stand-in values matching
// the defaults from $wgReceiptScannerNamespaceIndex are fine.
if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
	define( 'NS_RECEIPTSCANNER_EXPENSE', 3000 );
	define( 'NS_RECEIPTSCANNER_INCOME', 3002 );
}

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialCloneReceiptEntry::planClone
 */
class SpecialCloneReceiptEntryTest extends MediaWikiUnitTestCase {

	/**
	 * Install a mock global service container so planClone() can build the
	 * FormEdit redirect via SpecialPage::getTitleFor()->getLocalURL()
	 * without a live MediaWiki. Only the services that path touches are
	 * stubbed.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wgArticlePath'] = '/wiki/$1';
		$GLOBALS['wgScript'] = '/w/index.php';
		$GLOBALS['wgMainPageIsDomainRoot'] = false;
		$GLOBALS['wgActionPaths'] = [];
		$GLOBALS['wgVariantArticlePath'] = false;

		$spf = $this->createMock( SpecialPageFactory::class );
		$spf->method( 'getLocalNameFor' )->willReturnCallback(
			static fn ( $name, $subpage = false ) => $subpage === false || $subpage === null
				? $name : "$name/$subpage"
		);

		$interwiki = $this->createMock( InterwikiLookup::class );
		$interwiki->method( 'fetch' )->willReturn( false );

		$hookContainer = $this->createMock( HookContainer::class );
		$hookContainer->method( 'run' )->willReturn( true );

		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getNamespaceName' )->willReturnCallback(
			static fn ( $ns ) => $ns === NS_SPECIAL ? 'Special' : ''
		);

		$services = $this->getMockBuilder( MediaWikiServices::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getSpecialPageFactory',
				'getInterwikiLookup',
				'getHookContainer',
				'getTitleFormatter',
			] )
			->getMock();
		$services->method( 'getSpecialPageFactory' )->willReturn( $spf );
		$services->method( 'getInterwikiLookup' )->willReturn( $interwiki );
		$services->method( 'getHookContainer' )->willReturn( $hookContainer );
		$services->method( 'getTitleFormatter' )->willReturn( $titleFormatter );

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
		parent::tearDown();
	}

	private function makeTitle( int $namespace, bool $exists = true ): Title {
		$t = $this->createMock( Title::class );
		$t->method( 'getNamespace' )->willReturn( $namespace );
		$t->method( 'exists' )->willReturn( $exists );
		return $t;
	}

	private function build( ?Title $title, ?string $wikitext ): SpecialCloneReceiptEntry {
		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( $title );

		$wpf = $this->createMock( WikiPageFactory::class );
		if ( $title !== null && $wikitext !== null ) {
			$page = $this->createMock( WikiPage::class );
			$page->method( 'getContent' )->willReturn( new WikitextContent( $wikitext ) );
			$wpf->method( 'newFromTitle' )->with( $title )->willReturn( $page );
		}
		return new SpecialCloneReceiptEntry( $wpf, $tf );
	}

	public function testEmptySubPageReturnsErrorKey(): void {
		$result = $this->build( null, null )->planClone( '' );
		$this->assertSame( 'receiptscanner-clone-no-source', $result['error'] );
		$this->assertArrayNotHasKey( 'redirect', $result );
	}

	public function testUnresolvableTitleReturnsErrorKey(): void {
		// titleFactory returns null for garbage input.
		$result = $this->build( null, null )->planClone( '::::' );
		$this->assertSame( 'receiptscanner-clone-source-not-found', $result['error'] );
	}

	public function testNonExistentTitleReturnsErrorKey(): void {
		$title = $this->makeTitle( NS_RECEIPTSCANNER_EXPENSE, exists: false );
		$result = $this->build( $title, null )->planClone( 'Expense:1234' );
		$this->assertSame( 'receiptscanner-clone-source-not-found', $result['error'] );
	}

	public function testWrongNamespaceReturnsErrorKey(): void {
		// NS_HELP = 12 — not an Expense or Income page.
		$title = $this->makeTitle( 12 );
		$result = $this->build( $title, null )->planClone( 'Help:Receipts' );
		$this->assertSame( 'receiptscanner-clone-not-receipt', $result['error'] );
	}

	public function testHappyPathReturnsRedirectWithPrefill(): void {
		$wikitext = "{{Expense\n|date=2026-05-01\n|total=42.00\n|category=Travel/Meals\n|currency=USD\n|queue_id=123\n}}";
		$title = $this->makeTitle( NS_RECEIPTSCANNER_EXPENSE );
		$result = $this->build( $title, $wikitext )->planClone( 'Expense:1234' );

		$this->assertArrayNotHasKey( 'error', $result );
		$url = $result['redirect'] ?? '';
		// Special:FormEdit/Expense as the redirect target
		$this->assertStringContainsString( 'FormEdit', $url );
		$this->assertStringContainsString( 'Expense', $url );

		// All non-queue_id fields should be URL-encoded into the
		// query string as Expense[<key>]=<value>.
		foreach (
			[
				'date'     => '2026-05-01',
				'total'    => '42.00',
				'category' => 'Travel%2FMeals',
				'currency' => 'USD',
			] as $key => $expected
		) {
			$this->assertStringContainsString(
				"Expense%5B$key%5D=$expected",
				$url,
				"missing prefill for $key"
			);
		}
	}

	public function testHappyPathStripsQueueIdFromPrefill(): void {
		// The clone is a wiki-side fork; carrying the original queue
		// row's id would re-consume the same row when the clone saves.
		// PageForms emits one |field=value per line, which is what
		// parseTemplateFields() splits on.
		$wikitext = "{{Expense\n|date=2026-05-01\n|queue_id=99\n}}";
		$title = $this->makeTitle( NS_RECEIPTSCANNER_EXPENSE );
		$result = $this->build( $title, $wikitext )->planClone( 'Expense:1234' );

		$url = $result['redirect'];
		$this->assertStringContainsString( 'date', $url );
		$this->assertStringNotContainsString( 'queue_id', $url );
		$this->assertStringNotContainsString( '99', $url );
	}

	public function testIncomeNamespaceUsesIncomeForm(): void {
		$wikitext = "{{Income\n|date=2026-05-01\n|category=Sales/Products\n}}";
		$title = $this->makeTitle( NS_RECEIPTSCANNER_INCOME );
		$result = $this->build( $title, $wikitext )->planClone( 'Income:9999' );

		$url = $result['redirect'];
		// Form is Income, not Expense; prefill keys use Income[...]
		$this->assertStringContainsString( 'FormEdit/Income', $url );
		$this->assertStringContainsString( 'Income%5Bdate%5D=2026-05-01', $url );
		$this->assertStringNotContainsString( 'Expense%5B', $url );
	}
}
