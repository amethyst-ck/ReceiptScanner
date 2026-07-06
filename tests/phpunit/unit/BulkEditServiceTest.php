<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

// MainHooks::onRegistration defines these at extension load; unit
// tests run without that registration, so stand-in values matching
// the defaults from $wgReceiptScannerNamespaceIndex are fine.
if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
	define( 'NS_RECEIPTSCANNER_EXPENSE', 3000 );
	define( 'NS_RECEIPTSCANNER_INCOME', 3002 );
}

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\BulkEditService
 */
class BulkEditServiceTest extends MediaWikiUnitTestCase {

	public function testReplacesExistingField(): void {
		$wikitext = "{{Expense\n|date=2026-05-01\n|category=Travel\n|total=120\n}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'Office/Software' );
		$this->assertStringContainsString( '|category=Office/Software', $out );
		$this->assertStringNotContainsString( '|category=Travel', $out );
		// Other fields untouched.
		$this->assertStringContainsString( '|date=2026-05-01', $out );
		$this->assertStringContainsString( '|total=120', $out );
	}

	public function testInsertsAbsentFieldBeforeClosingBraces(): void {
		$wikitext = "{{Expense\n|date=2026-05-01\n|total=120\n}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'Travel/Meals' );
		$this->assertStringContainsString( '|category=Travel/Meals', $out );
		// Inserted before the closing braces, not after.
		$this->assertStringEndsWith( '}}', $out );
	}

	public function testIdempotentWhenValueAlreadyMatches(): void {
		$wikitext = "{{Expense|category=Travel}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'Travel' );
		$this->assertSame( $wikitext, $out );
	}

	public function testPreservesNewlinesAroundValue(): void {
		// The regex must not consume the trailing \n that separates fields.
		$wikitext = "{{Expense\n|category=Old\n|date=2026-05-01\n}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'New' );
		$this->assertStringContainsString( "|category=New\n|date=2026-05-01", $out );
	}

	public function testHandlesDollarSignInValueOnReplacePath(): void {
		// preg_replace treats $N as a backreference; literal $ must be escaped.
		$wikitext = "{{Expense|notes=old}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'notes', 'Cost was $1 each' );
		$this->assertStringContainsString( '|notes=Cost was $1 each', $out );
	}

	public function testHandlesDollarSignInValueOnInsertPath(): void {
		// Same protection must apply when inserting a brand-new field.
		$wikitext = "{{Expense|date=2026-05-01}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'notes', 'Cost was $1 each' );
		$this->assertStringContainsString( '|notes=Cost was $1 each', $out );
	}

	public function testIgnoresWhitespaceAroundFieldName(): void {
		$wikitext = "{{Expense|  category  =  Old  |date=2026-05-01}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'New' );
		$this->assertStringContainsString( 'category', $out );
		$this->assertStringContainsString( 'New', $out );
		$this->assertStringNotContainsString( 'Old', $out );
	}

	public function testReturnsUnchangedWhenNoTemplate(): void {
		$wikitext = "plain text, no template";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'Travel' );
		$this->assertSame( $wikitext, $out );
	}

	public function testOnlyReplacesFirstTemplateInvocation(): void {
		// We ship single-template pages, but document the limit.
		$wikitext = "{{Expense|category=A}}\n{{Other|category=B}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'category', 'NEW' );
		// First match changed, second left alone.
		$this->assertStringContainsString( '{{Expense|category=NEW}}', $out );
		$this->assertStringContainsString( '{{Other|category=B}}', $out );
	}

	public function testFieldNameWithSpecialCharsIsQuoted(): void {
		// preg_quote handles regex metacharacters in the field name.
		$wikitext = "{{Expense|foo.bar=old}}";
		$out = BulkEditService::replaceTemplateField( $wikitext, 'foo.bar', 'new' );
		$this->assertStringContainsString( '|foo.bar=new', $out );
	}

	/**
	 * Build a Title mock with the given namespace, exists-state, and (when
	 * the wikitext-rewrite path will be exercised) prefixed text.
	 */
	private function makeTitle( int $namespace, bool $exists = true ): Title {
		$t = $this->createMock( Title::class );
		$t->method( 'getNamespace' )->willReturn( $namespace );
		$t->method( 'exists' )->willReturn( $exists );
		return $t;
	}

	/**
	 * Authority mock whose per-page edit check returns $can. setField()
	 * gained an Authority param and skips pages the actor cannot edit.
	 */
	private function makeAuthority( bool $can = true ): Authority {
		$authority = $this->createMock( Authority::class );
		$authority->method( 'authorizeWrite' )->willReturn( $can );
		return $authority;
	}

	/**
	 * BulkEditService whose error lines skip i18n resolution (unit
	 * tests cannot reach the service container wfMessage needs) and
	 * return "<message key>: <page name>" instead, so assertions can
	 * pin which message each error path picked.
	 */
	private function makeErrorProbingService(
		WikiPageFactory $wpf, TitleFactory $titleFactory
	): BulkEditService {
		return new class( $wpf, $titleFactory ) extends BulkEditService {
			protected function errorMessage( string $key, string $pageName ): string {
				return "$key: $pageName";
			}
		};
	}

	public function testSetFieldErrorsOnTitleNotFound(): void {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( null );

		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->never() )->method( 'newFromTitle' );

		$service = $this->makeErrorProbingService( $wpf, $titleFactory );
		$result = $service->setField(
			[ 'Garbage::page' ], 'category', 'X',
			$this->createMock( User::class ), $this->makeAuthority(), 'summary'
		);
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame(
			'receiptscanner-bulk-error-not-found: Garbage::page',
			$result['errors'][0]
		);
	}

	public function testSetFieldRejectsNonExpenseOrIncomeNamespace(): void {
		// Help: namespace = 12 (core MediaWiki). Defense-in-depth check
		// must drop the page before the wikitext-rewrite step runs.
		$title = $this->makeTitle( 12 );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $title );

		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->never() )->method( 'newFromTitle' );

		$service = $this->makeErrorProbingService( $wpf, $titleFactory );
		$result = $service->setField(
			[ 'Help:Receipts' ], 'category', 'X',
			$this->createMock( User::class ), $this->makeAuthority(), 'summary'
		);
		$this->assertSame( 0, $result['updated'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame(
			'receiptscanner-bulk-error-wrong-namespace: Help:Receipts',
			$result['errors'][0]
		);
	}

	public function testParseTemplateFieldsExtractsFlatPairs(): void {
		$wikitext = "{{Expense\n|date=2026-05-01\n|category=Travel/Meals\n|total=42.00\n|currency=USD\n}}";
		$out = BulkEditService::parseTemplateFields( $wikitext );
		$this->assertSame( '2026-05-01', $out['date'] );
		$this->assertSame( 'Travel/Meals', $out['category'] );
		$this->assertSame( '42.00', $out['total'] );
		$this->assertSame( 'USD', $out['currency'] );
		$this->assertCount( 4, $out );
	}

	public function testParseTemplateFieldsReturnsEmptyForNonTemplate(): void {
		$this->assertSame(
			[],
			BulkEditService::parseTemplateFields( 'plain text, no template' )
		);
	}

	public function testParseTemplateFieldsHandlesMultilineNotes(): void {
		// notes is the one field that can carry newlines through PageForms
		// — the split-on /\n\s*\|/ tolerates that.
		$wikitext = "{{Expense\n|date=2026-05-01\n|notes=Line 1\nLine 2\nLine 3\n|category=A\n}}";
		$out = BulkEditService::parseTemplateFields( $wikitext );
		$this->assertSame( "Line 1\nLine 2\nLine 3", $out['notes'] );
		$this->assertSame( 'A', $out['category'] );
	}

	public function testParseTemplateFieldsOnlyFirstTemplate(): void {
		$wikitext = "{{Expense|date=2026-05-01}}\n{{Other|date=1999-01-01}}";
		$out = BulkEditService::parseTemplateFields( $wikitext );
		$this->assertSame( '2026-05-01', $out['date'] );
		// Second template's fields don't leak in.
		$this->assertCount( 1, $out );
	}

	public function testSetFieldAcceptsExpenseNamespace(): void {
		// Expense-namespace title with a wikitext page that has the
		// template + the target field — the service should request the
		// rewrite through WikiPageFactory. We don't fully exercise the
		// save path (status mocking is heavy); the assertion is that the
		// namespace check lets the request through.
		$title = $this->makeTitle( NS_RECEIPTSCANNER_EXPENSE );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $title );

		$wpf = $this->createMock( WikiPageFactory::class );
		// expects(once) is the meaningful assertion: the namespace check
		// did NOT short-circuit.
		$wpf->expects( $this->once() )->method( 'newFromTitle' )
			->willThrowException( new \RuntimeException( 'stop here' ) );

		$service = new BulkEditService( $wpf, $titleFactory );
		// PHPUnit's exception mock will propagate; just confirm the call
		// flowed through to the WikiPageFactory step.
		$this->expectException( \RuntimeException::class );
		$service->setField(
			[ 'Expense:1' ], 'category', 'X',
			$this->createMock( User::class ), $this->makeAuthority(), 'summary'
		);
	}
}
