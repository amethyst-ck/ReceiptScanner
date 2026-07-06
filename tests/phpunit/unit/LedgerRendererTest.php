<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\LedgerStore;
use MediaWiki\Extension\ReceiptScanner\Special\LedgerRenderer;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\Session\Session;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\LedgerRenderer
 */
class LedgerRendererTest extends MediaWikiUnitTestCase {

	/**
	 * Capture all HTML written to OutputPage::addHTML during the
	 * supplied callback, concatenated.
	 *
	 * @return array{html:string, jsVars:array}
	 */
	private function captureRender(
		callable $invoke,
		array $rendererArgs = []
	): array {
		$html = '';
		$jsVars = [];

		$out = $this->createMock( OutputPage::class );
		$out->method( 'addHTML' )->willReturnCallback(
			static function ( $h ) use ( &$html ) {
				$html .= $h;
			}
		);
		$out->method( 'addJsConfigVars' )->willReturnCallback(
			static function ( $v ) use ( &$jsVars ) {
				$jsVars = array_merge( $jsVars, (array)$v );
			}
		);

		$session = $this->createMock( Session::class );
		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );

		$csrf = $this->createMock( CsrfTokenSet::class );
		$csrf->method( 'getToken' )->willReturn( $this->createMockToken() );

		$title = $this->createMock( Title::class );
		$title->method( 'getLocalURL' )->willReturn( '/wiki/Special:Ledger' );

		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );
		$ctx->method( 'getTitle' )->willReturn( $title );
		$ctx->method( 'getCsrfTokenSet' )->willReturn( $csrf );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$vocabulary = $rendererArgs['vocabulary']
			?? $this->stubVocabulary( [], [] );
		$userStore = $rendererArgs['userStore']
			?? $this->stubUserStore( [] );
		$query = $rendererArgs['query']
			?? $this->stubQuery( [] );

		$renderer = new LedgerRenderer(
			$ctx, $this->createMock( LinkRenderer::class ),
			$this->createMock( TitleFactory::class ),
			$query, $vocabulary, $userStore,
			$rendererArgs['currency'] ?? 'USD'
		);
		$invoke( $renderer );

		return [ 'html' => $html, 'jsVars' => $jsVars, 'session' => $session ];
	}

	private function createMockToken() {
		$token = $this->createMock( \MediaWiki\Session\Token::class );
		$token->method( '__toString' )->willReturn( 'fake-token' );
		return $token;
	}

	/**
	 * Chainable Message mock whose text-returning methods echo the key,
	 * so assertions can match on the message key rather than localized
	 * text (unit tests have no i18n loaded).
	 */
	private function mockMessage( string $key ): Message {
		$m = $this->createMock( Message::class );
		$m->method( 'text' )->willReturn( $key );
		$m->method( 'parse' )->willReturn( $key );
		$m->method( 'plain' )->willReturn( $key );
		$m->method( 'escaped' )->willReturn( $key );
		foreach ( [ 'numParams', 'params', 'rawParams', 'plaintextParams', 'inContentLanguage', 'title' ] as $chain ) {
			$m->method( $chain )->willReturnSelf();
		}
		return $m;
	}

	private function stubVocabulary( array $expense, array $income ): CategoryVocabulary {
		$v = $this->createMock( CategoryVocabulary::class );
		$v->method( 'getPaths' )->willReturnCallback(
			static fn ( $kind ) => $kind === \MediaWiki\Extension\ReceiptScanner\ReceiptKind::Income ? $income : $expense
		);
		return $v;
	}

	private function stubUserStore( array $names ): UserStore {
		$u = $this->createMock( UserStore::class );
		$u->method( 'getUsernames' )->willReturn( $names );
		return $u;
	}

	private function stubQuery( array $parties ): LedgerStore {
		$q = $this->createMock( LedgerStore::class );
		$q->method( 'getParties' )->willReturn( $parties );
		return $q;
	}

	private function sampleFilters( array $overrides = [] ): array {
		return $overrides + [
			'preset' => 'all', 'kind' => 'both',
			'from' => null, 'to' => null,
			'min' => null, 'max' => null,
			'category' => null, 'assignee' => null, 'party' => null,
		];
	}

	private function sampleRow( array $overrides = [] ): array {
		// page=null and assignee=null avoid Title operations in the
		// renderer that need the MW service container. Testing the
		// link-rendering branches (page link, user link) requires an
		// integration test once CanastaBase #167 unblocks it.
		return $overrides + [
			'kind' => 'expense', 'id' => 1, 'page' => null,
			'date' => '2026-05-01', 'party' => 'Acme', 'total' => '120.00',
			'currency' => 'USD', 'total_system' => '120.00',
			'category' => 'Travel', 'assignee' => null,
		];
	}

	// ----- list view -----

	public function testListViewIncludesFilterFormResultsAndBulk(): void {
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView(
				[ $this->sampleRow() ], $this->sampleFilters()
			)
		);
		$this->assertStringContainsString( 'rs-ledger-form', $r['html'] );
		$this->assertStringContainsString( 'rs-ledger-table', $r['html'] );
		$this->assertStringContainsString( 'rs-ledger-bulk-form', $r['html'] );
	}

	public function testListViewHasNoInlineEventHandlers(): void {
		// We moved all JS to the RL module; rendered HTML must stay clean.
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView(
				[ $this->sampleRow() ], $this->sampleFilters()
			)
		);
		$this->assertStringNotContainsString( 'addEventListener', $r['html'] );
		$this->assertStringNotContainsString( 'onclick=', $r['html'] );
		$this->assertStringNotContainsString( '<script', $r['html'] );
	}

	public function testListViewEmptyResultsShowsNoEntriesMessage(): void {
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView( [], $this->sampleFilters() )
		);
		$this->assertStringContainsString( 'receiptscanner-ledger-no-results', $r['html'] );
		// And no table when there are no rows.
		$this->assertStringNotContainsString( 'rs-ledger-table', $r['html'] );
	}

	public function testListViewSummaryReflectsRowTotals(): void {
		$rows = [
			$this->sampleRow( [ 'kind' => 'expense', 'total_system' => '120.00' ] ),
			$this->sampleRow( [ 'kind' => 'income', 'total_system' => '500.00' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView( $rows, $this->sampleFilters() )
		);
		// Net = 500 - 120 = 380 — positive. Currency symbol on every amount.
		$this->assertStringContainsString( '$120.00', $r['html'] );
		$this->assertStringContainsString( '$500.00', $r['html'] );
		$this->assertStringContainsString( '$380.00', $r['html'] );
	}

	public function testListViewNegativeNetUsesParens(): void {
		$rows = [
			$this->sampleRow( [ 'kind' => 'expense', 'total_system' => '500.00' ] ),
			$this->sampleRow( [ 'kind' => 'income', 'total_system' => '120.00' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView( $rows, $this->sampleFilters() )
		);
		// Net = 120 - 500 = -380. Accounting format: ($380.00), no minus sign.
		$this->assertStringContainsString( '($380.00)', $r['html'] );
		$this->assertStringNotContainsString( '-380', $r['html'] );
		$this->assertStringNotContainsString( '−380', $r['html'] );
	}

	public function testListViewExpenseRowShowsAmountInParens(): void {
		// Expense per-row: $5.30 expense → ($5.30) in the Amount column.
		$rows = [
			$this->sampleRow( [ 'kind' => 'expense', 'total_system' => '5.30' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView( $rows, $this->sampleFilters() )
		);
		$this->assertStringContainsString( '($5.30)', $r['html'] );
	}

	public function testListViewIncomeRowShowsAmountWithSymbolNoParens(): void {
		$rows = [
			$this->sampleRow( [ 'kind' => 'income', 'total_system' => '500.00' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView( $rows, $this->sampleFilters() )
		);
		$this->assertStringContainsString( '$500.00', $r['html'] );
		$this->assertStringNotContainsString( '($500.00)', $r['html'] );
	}

	public function testBulkFormExposesPartiesViaJsVars(): void {
		$query = $this->stubQuery( [ 'Acme Corp', 'Beta LLC' ] );
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderListView(
				[ $this->sampleRow() ], $this->sampleFilters()
			),
			[ 'query' => $query ]
		);
		$this->assertSame(
			[ 'Acme Corp', 'Beta LLC' ],
			$r['jsVars']['wgReceiptScannerBulkParties']
		);
	}

	// ----- summary view -----

	public function testSummaryViewRendersNetAndCategoryGroups(): void {
		$rows = [
			$this->sampleRow( [ 'kind' => 'expense', 'category' => 'Travel', 'total_system' => '100.00' ] ),
			$this->sampleRow( [ 'kind' => 'expense', 'category' => 'Travel', 'total_system' => '50.00' ] ),
			$this->sampleRow( [ 'kind' => 'income', 'category' => 'Sales', 'total_system' => '200.00' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderSummaryView(
				$rows, [ 'preset' => 'all', 'from' => null, 'to' => null ]
			)
		);
		$this->assertStringContainsString( 'rs-ledger-summary-doc', $r['html'] );
		$this->assertStringContainsString( 'receiptscanner-ledger-summary-net-income', $r['html'] );
		$this->assertStringContainsString( 'Travel', $r['html'] );
		$this->assertStringContainsString( 'Sales', $r['html'] );
		// Travel total = 150, Sales = 200, net = 50. Symbol prefix on every amount.
		$this->assertStringContainsString( '$150.00', $r['html'] );
		$this->assertStringContainsString( '$200.00', $r['html'] );
		$this->assertStringContainsString( '$50.00', $r['html'] );
	}

	public function testSummaryViewUncategorisedFallback(): void {
		$rows = [
			$this->sampleRow( [ 'kind' => 'expense', 'category' => null, 'total_system' => '42.00' ] ),
		];
		$r = $this->captureRender(
			fn ( $renderer ) => $renderer->renderSummaryView(
				$rows, [ 'preset' => 'all', 'from' => null, 'to' => null ]
			)
		);
		$this->assertStringContainsString( 'receiptscanner-ledger-rollup-uncategorised', $r['html'] );
	}

	// ----- bulk-edit flash -----

	public function testFlashRendersWhenSessionHasOne(): void {
		$out = $this->createMock( OutputPage::class );
		$html = '';
		$out->method( 'addHTML' )->willReturnCallback(
			static function ( $h ) use ( &$html ) {
				$html .= $h;
			}
		);
		$session = $this->createMock( Session::class );
		$session->method( 'get' )->with( 'rs-ledger-bulkedit-flash' )->willReturn( [
			'updated' => 2, 'skipped' => 1, 'errors' => [],
			'field' => 'category', 'value' => 'Travel',
		] );
		$session->expects( $this->once() )
			->method( 'remove' )
			->with( 'rs-ledger-bulkedit-flash' );

		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$renderer = new LedgerRenderer(
			$ctx,
			$this->createMock( LinkRenderer::class ),
			$this->createMock( TitleFactory::class ),
			$this->stubQuery( [] ),
			$this->stubVocabulary( [], [] ),
			$this->stubUserStore( [] ),
			'USD'
		);
		$renderer->renderBulkEditFlash();

		$this->assertStringContainsString( 'receiptscanner-ledger-bulk-flash-main', $html );
		$this->assertStringContainsString( 'receiptscanner-ledger-bulk-flash-skipped', $html );
	}

	/**
	 * Render the flash with the given error list and return the HTML.
	 */
	private function renderFlashWithErrors( array $errors ): string {
		$out = $this->createMock( OutputPage::class );
		$html = '';
		$out->method( 'addHTML' )->willReturnCallback(
			static function ( $h ) use ( &$html ) {
				$html .= $h;
			}
		);
		$session = $this->createMock( Session::class );
		$session->method( 'get' )->with( 'rs-ledger-bulkedit-flash' )->willReturn( [
			'updated' => 1, 'skipped' => 0, 'errors' => $errors,
			'field' => 'category', 'value' => 'Travel',
		] );

		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$renderer = new LedgerRenderer(
			$ctx,
			$this->createMock( LinkRenderer::class ),
			$this->createMock( TitleFactory::class ),
			$this->stubQuery( [] ),
			$this->stubVocabulary( [], [] ),
			$this->stubUserStore( [] ),
			'USD'
		);
		$renderer->renderBulkEditFlash();
		return $html;
	}

	public function testFlashListsErrorsEscaped(): void {
		$html = $this->renderFlashWithErrors( [
			'Expense:1: not found',
			'Expense:<b>2</b>: not found',
		] );
		$this->assertStringContainsString( 'receiptscanner-ledger-bulk-flash-errors', $html );
		$this->assertStringContainsString( '<li>Expense:1: not found</li>', $html );
		// Error strings are escaped, never raw HTML (MW's Html::element
		// escapes the opening angle bracket, which is what defuses tags).
		$this->assertStringNotContainsString( '<b>2</b>', $html );
		$this->assertStringContainsString( '&lt;b>2&lt;/b>', $html );
		// No ellipsis below the cap.
		$this->assertStringNotContainsString( '…', $html );
	}

	public function testFlashErrorListCappedAtTenWithEllipsis(): void {
		$errors = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$errors[] = "Expense:$i: not found";
		}
		$html = $this->renderFlashWithErrors( $errors );
		// 10 error items + 1 ellipsis item.
		$this->assertSame( 11, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( '<li>Expense:10: not found</li>', $html );
		$this->assertStringNotContainsString( 'Expense:11', $html );
		$this->assertStringContainsString( '<li>…</li>', $html );
	}

	public function testFlashIsNoopWhenSessionEmpty(): void {
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->never() )->method( 'addHTML' );

		$session = $this->createMock( Session::class );
		$session->method( 'get' )->willReturn( null );
		$session->expects( $this->never() )->method( 'remove' );

		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$renderer = new LedgerRenderer(
			$ctx,
			$this->createMock( LinkRenderer::class ),
			$this->createMock( TitleFactory::class ),
			$this->stubQuery( [] ),
			$this->stubVocabulary( [], [] ),
			$this->stubUserStore( [] ),
			'USD'
		);
		$renderer->renderBulkEditFlash();
	}
}
