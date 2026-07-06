<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Extension\ReceiptScanner\Special\LedgerBulkEditController;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\Session;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
	define( 'NS_RECEIPTSCANNER_EXPENSE', 3000 );
	define( 'NS_RECEIPTSCANNER_INCOME', 3002 );
}

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\LedgerBulkEditController
 */
class LedgerBulkEditControllerTest extends MediaWikiUnitTestCase {

	private const PAGE_URL = '/wiki/Special:Ledger';

	/**
	 * Build a controller + the side-channel mocks (OutputPage,
	 * Session) so tests can assert on redirect + session flash.
	 *
	 * @return array{controller:LedgerBulkEditController, out:OutputPage&\PHPUnit\Framework\MockObject\MockObject, session:Session&\PHPUnit\Framework\MockObject\MockObject, bulk:BulkEditService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function buildController(): array {
		$out = $this->createMock( OutputPage::class );
		$session = $this->createMock( Session::class );
		$user = $this->createMock( User::class );

		$authority = $this->createMock( Authority::class );
		$authority->method( 'definitelyCan' )->willReturn( true );

		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getUser' )->willReturn( $user );
		// Controller now pulls the authority off the context and passes it
		// into setField() for the per-page edit check.
		$ctx->method( 'getAuthority' )->willReturn( $authority );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$pageTitle = $this->createMock( Title::class );
		// getLocalURL is called with the filter query array; capture it
		// so tests can assert what the redirect URL looks like.
		$capturedQuery = (object)[ 'value' => null ];
		$pageTitle->method( 'getLocalURL' )
			->willReturnCallback( static function ( $query = '' ) use ( $capturedQuery ) {
				$capturedQuery->value = $query;
				return self::PAGE_URL;
			} );

		$bulk = $this->createMock( BulkEditService::class );
		// Resolve Expense:/Income: prefixes to their namespaces so the
		// per-kind edit paths (party, category) can split selections.
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturnCallback(
			function ( $text ) {
				$title = $this->createMock( Title::class );
				$title->method( 'getNamespace' )->willReturn(
					str_starts_with( $text, 'Income:' )
						? NS_RECEIPTSCANNER_INCOME
						: NS_RECEIPTSCANNER_EXPENSE
				);
				return $title;
			}
		);
		$controller = new LedgerBulkEditController(
			$ctx, $bulk, $titleFactory, $pageTitle
		);

		return [
			'controller' => $controller,
			'out' => $out,
			'session' => $session,
			'bulk' => $bulk,
			'capturedQuery' => $capturedQuery,
		];
	}

	/**
	 * Chainable Message mock whose text-returning methods echo the key
	 * (no i18n loaded in unit tests).
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

	/**
	 * @param array<string,string> $filters rs_filter_<k> => value pairs to
	 *   stamp into the request (simulates the hidden inputs the renderer
	 *   adds for filter-roundtrip).
	 */
	private function makeRequest(
		?string $field, ?string $value, ?array $pages, Session $session, array $filters = []
	): WebRequest {
		$req = $this->createMock( WebRequest::class );
		$req->method( 'getRawVal' )->willReturnCallback(
			static fn ( $k ) => $k === 'bulk_field' ? $field : null
		);
		$req->method( 'getVal' )->willReturnCallback(
			static function ( $k, $default = '' ) use ( $value, $filters ) {
				if ( $k === 'bulk_value' ) {
					return $value;
				}
				if ( str_starts_with( $k, 'rs_filter_' ) ) {
					return $filters[substr( $k, strlen( 'rs_filter_' ) )] ?? $default;
				}
				return $default;
			}
		);
		$req->method( 'getArray' )->willReturnCallback(
			static fn ( $k ) => $k === 'bulk_pages' ? $pages : null
		);
		$req->method( 'getSession' )->willReturn( $session );
		return $req;
	}

	public function testRedirectsAndSkipsBulkOnMissingField(): void {
		$h = $this->buildController();
		$h['bulk']->expects( $this->never() )->method( 'setField' );
		$h['out']->expects( $this->once() )
			->method( 'redirect' )->with( self::PAGE_URL );

		$req = $this->makeRequest( null, 'value', [ 'Expense:1' ], $h['session'] );
		$h['controller']->handle( $req );
	}

	public function testRejectsDisallowedFieldName(): void {
		$h = $this->buildController();
		$h['bulk']->expects( $this->never() )->method( 'setField' );

		$req = $this->makeRequest( 'evil_column', 'x', [ 'Expense:1' ], $h['session'] );
		$h['controller']->handle( $req );
	}

	public function testNoopWhenPagesEmpty(): void {
		$h = $this->buildController();
		$h['bulk']->expects( $this->never() )->method( 'setField' );

		$req = $this->makeRequest( 'category', 'Travel', [], $h['session'] );
		$h['controller']->handle( $req );
	}

	public function testCategoryEditUsesKindParameter(): void {
		$h = $this->buildController();
		$h['bulk']->expects( $this->once() )
			->method( 'setField' )
			->with(
				[ 'Expense:1', 'Expense:2' ],
				'expense_category',
				'Travel/Meals',
				// user
				$this->anything(),
				// authority (pulled from context)
				$this->anything(),
				// Summary derives from the edit-summary message; plain()
				// echoes the key in unit tests.
				$this->stringContains( 'receiptscanner-ledger-bulk-edit-summary' )
			)
			->willReturn( [ 'updated' => 2, 'skipped' => 0, 'errors' => [] ] );

		$h['session']->expects( $this->once() )
			->method( 'set' )
			->with( 'rs-ledger-bulkedit-flash', $this->callback(
				static fn ( $v ) => $v['field'] === 'category'
					&& $v['value'] === 'Travel/Meals'
					&& $v['updated'] === 2
			) );

		$req = $this->makeRequest(
			'category', 'Travel/Meals',
			[ 'Expense:1', 'Expense:2' ],
			$h['session']
		);
		$h['controller']->handle( $req );
	}

	public function testCategoryEditSplitsMixedKinds(): void {
		$h = $this->buildController();
		$calls = [];
		$h['bulk']->expects( $this->exactly( 2 ) )
			->method( 'setField' )
			->willReturnCallback( static function ( $pages, $param ) use ( &$calls ) {
				$calls[] = [ $pages, $param ];
				return [ 'updated' => count( $pages ), 'skipped' => 0, 'errors' => [] ];
			} );

		$req = $this->makeRequest(
			'category', 'Subscriptions',
			[ 'Expense:1', 'Income:2' ],
			$h['session']
		);
		$h['controller']->handle( $req );

		$this->assertContains( [ [ 'Expense:1' ], 'expense_category' ], $calls );
		$this->assertContains( [ [ 'Income:2' ], 'income_category' ], $calls );
	}

	public function testFlashPreservesErrorsFromService(): void {
		$h = $this->buildController();
		$h['bulk']->method( 'setField' )->willReturn( [
			'updated' => 1, 'skipped' => 0, 'errors' => [ 'Expense:2: not found' ],
		] );

		$h['session']->expects( $this->once() )
			->method( 'set' )
			->with( 'rs-ledger-bulkedit-flash', $this->callback(
				static fn ( $v ) => $v['errors'] === [ 'Expense:2: not found' ]
			) );

		$req = $this->makeRequest(
			'assignee', 'alice', [ 'Expense:1', 'Expense:2' ], $h['session']
		);
		$h['controller']->handle( $req );
	}

	public function testRedirectPreservesFilterQuery(): void {
		$h = $this->buildController();
		$h['bulk']->method( 'setField' )->willReturn( [
			'updated' => 1, 'skipped' => 0, 'errors' => [],
		] );

		$req = $this->makeRequest(
			'category', 'Travel', [ 'Expense:1' ], $h['session'],
			[
				'kind' => 'expense',
				'range' => 'custom',
				'from' => '2026-01-01',
				'to' => '2026-01-31',
				'category' => 'Old',
				// empty filters should be dropped from the redirect URL
				'assignee' => '',
			]
		);
		$h['controller']->handle( $req );

		// pageTitle::getLocalURL was called with the redirect-query
		// array; the controller should have stripped the rs_filter_
		// prefix and dropped the empty value.
		$this->assertEquals(
			[
				'kind' => 'expense',
				'range' => 'custom',
				'from' => '2026-01-01',
				'to' => '2026-01-31',
				'category' => 'Old',
			],
			$h['capturedQuery']->value,
			'redirect query should preserve non-empty filters with rs_filter_ prefix stripped'
		);
	}
}
