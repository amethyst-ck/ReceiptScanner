<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use WikiPage;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary
 */
class CategoryVocabularyTest extends MediaWikiUnitTestCase {

	public function testFlattensNestedList(): void {
		$wikitext = "* Travel\n** Meals\n** Lodging\n"
			. "* Office\n** Software\n*** Subscriptions";
		$this->assertSame(
			[
				'Travel',
				'Travel/Meals',
				'Travel/Lodging',
				'Office',
				'Office/Software',
				'Office/Software/Subscriptions',
			],
			CategoryVocabulary::parseList( $wikitext )
		);
	}

	public function testDepthJumpCollapses(): void {
		$this->assertSame(
			[ 'A', 'A/B' ],
			CategoryVocabulary::parseList( "* A\n*** B" )
		);
	}

	public function testSkipsNoiseAndBlankLines(): void {
		$this->assertSame(
			[ 'Real', 'Real/Child' ],
			CategoryVocabulary::parseList( "not a bullet\n* Real\n  \n** Child" )
		);
	}

	public function testEmptyInput(): void {
		$this->assertSame( [], CategoryVocabulary::parseList( '' ) );
	}

	public function testWhitespaceAroundLabels(): void {
		$this->assertSame(
			[ 'Travel', 'Travel/Meals' ],
			CategoryVocabulary::parseList( "*   Travel  \n**\tMeals\t" )
		);
	}

	// ----- instance methods -----

	/**
	 * Real WANObjectCache over an in-process HashBagOStuff — its
	 * getWithSetCallback()/delete() are final and can't be mocked, so
	 * tests observe genuine cache behavior instead.
	 */
	private function newCache(): WANObjectCache {
		return new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
	}

	private function build(
		?WANObjectCache $cache = null,
		?WikiPageFactory $wpf = null,
		?TitleFactory $tf = null,
		string $expensePage = 'Project:Expense categories',
		string $incomePage = 'Project:Income categories'
	): CategoryVocabulary {
		return new CategoryVocabulary(
			$cache ?? $this->newCache(),
			$wpf ?? $this->createMock( WikiPageFactory::class ),
			$tf ?? $this->createMock( TitleFactory::class ),
			new ServiceOptions(
				CategoryVocabulary::CONSTRUCTOR_OPTIONS,
				[
					'ReceiptScannerExpenseCategoryPage' => $expensePage,
					'ReceiptScannerIncomeCategoryPage' => $incomePage,
				]
			)
		);
	}

	public function testGetCategoryPageTitleUsesExpenseSettingForExpense(): void {
		$expected = $this->createMock( Title::class );
		$tf = $this->createMock( TitleFactory::class );
		$tf->expects( $this->once() )
			->method( 'newFromText' )
			->with( 'Project:Expense categories' )
			->willReturn( $expected );

		$result = $this->build( null, null, $tf )->getCategoryPageTitle( ReceiptKind::Expense );
		$this->assertSame( $expected, $result );
	}

	public function testGetCategoryPageTitleUsesIncomeSettingForIncome(): void {
		$expected = $this->createMock( Title::class );
		$tf = $this->createMock( TitleFactory::class );
		$tf->expects( $this->once() )
			->method( 'newFromText' )
			->with( 'Project:Income categories' )
			->willReturn( $expected );

		$result = $this->build( null, null, $tf )->getCategoryPageTitle( ReceiptKind::Income );
		$this->assertSame( $expected, $result );
	}

	public function testGetPathsReturnsEmptyWhenTitleUnparseable(): void {
		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( null );

		$this->assertSame( [], $this->build( null, null, $tf )->getPaths() );
	}

	public function testGetPathsCachesUnderPerTitleKey(): void {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'Project:Expense_categories' );

		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( $title );

		// The callback loads the page once; a second getPaths() must hit
		// the cache and not reload — so newFromTitle fires exactly once.
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getContent' )->willReturn(
			new WikitextContent( "* Travel\n** Meals" )
		);
		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->once() )
			->method( 'newFromTitle' )
			->with( $title )
			->willReturn( $page );

		$vocab = $this->build( $this->newCache(), $wpf, $tf );
		$expected = [ 'Travel', 'Travel/Meals' ];
		$this->assertSame( $expected, $vocab->getPaths() );
		$this->assertSame( $expected, $vocab->getPaths(),
			'second call should be served from cache' );
	}

	public function testGetPathsCallbackParsesPageContent(): void {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'X' );

		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( $title );

		$page = $this->createMock( WikiPage::class );
		$page->method( 'getContent' )->willReturn(
			new WikitextContent( "* A\n* B\n** C" )
		);
		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->method( 'newFromTitle' )->with( $title )->willReturn( $page );

		// Real cache: an empty cache misses, so getWithSetCallback runs
		// the callback that parses the page content.
		$this->assertSame(
			[ 'A', 'B', 'B/C' ],
			$this->build( $this->newCache(), $wpf, $tf )->getPaths()
		);
	}

	public function testMaybeInvalidateClearsCacheWhenTitleMatches(): void {
		$vocabTitle = $this->createMock( Title::class );
		$vocabTitle->method( 'getPrefixedDBkey' )->willReturn( 'Project:Expense_categories' );

		$saved = $this->createMock( Title::class );
		// equals() returns true only when compared against the vocab title.
		$saved->method( 'equals' )->willReturnCallback(
			static fn ( $t ) => $t === $vocabTitle
		);

		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( $vocabTitle );

		// The callback loads the page each time the cache is cold. After
		// maybeInvalidate() deletes the entry, getPaths() must reload —
		// so newFromTitle fires twice, once per cache miss.
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getContent' )->willReturn(
			new WikitextContent( "* Travel" )
		);
		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->exactly( 2 ) )
			->method( 'newFromTitle' )
			->willReturn( $page );

		$vocab = $this->build( $this->newCache(), $wpf, $tf );
		$vocab->getPaths();                 // primes the cache
		$vocab->maybeInvalidate( $saved );  // matching title → delete
		$vocab->getPaths();                 // cache miss → callback re-runs
	}

	public function testMaybeInvalidateDoesNothingForUnrelatedPage(): void {
		$saved = $this->createMock( Title::class );
		$saved->method( 'equals' )->willReturn( false );

		$vocabTitle = $this->createMock( Title::class );
		$vocabTitle->method( 'getPrefixedDBkey' )->willReturn( 'Project:Expense_categories' );
		$tf = $this->createMock( TitleFactory::class );
		$tf->method( 'newFromText' )->willReturn( $vocabTitle );

		// Unrelated save leaves the cache intact: the callback runs only
		// on the initial prime, not again after maybeInvalidate().
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getContent' )->willReturn(
			new WikitextContent( "* Travel" )
		);
		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->once() )
			->method( 'newFromTitle' )
			->willReturn( $page );

		$vocab = $this->build( $this->newCache(), $wpf, $tf );
		$vocab->getPaths();                 // primes the cache
		$vocab->maybeInvalidate( $saved );  // no match → no delete
		$vocab->getPaths();                 // still cached → no reload
	}
}
