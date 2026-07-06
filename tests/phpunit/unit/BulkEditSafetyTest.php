<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Storage\PageUpdateStatus;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use WikiPage;

// MainHooks::onRegistration defines these at extension load; unit tests
// run without that registration, so stand-in values matching the
// defaults from $wgReceiptScannerNamespaceIndex are fine.
if ( !defined( 'NS_RECEIPTSCANNER_EXPENSE' ) ) {
	define( 'NS_RECEIPTSCANNER_EXPENSE', 3000 );
	define( 'NS_RECEIPTSCANNER_INCOME', 3002 );
}

/**
 * Safety-focused coverage for BulkEditService: template-breaking values
 * are neutralized, backreference-shaped values are literal, and pages the
 * actor cannot edit are skipped.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\BulkEditService
 */
class BulkEditSafetyTest extends MediaWikiUnitTestCase {

	private function makeTitle( int $namespace = NS_RECEIPTSCANNER_EXPENSE ): Title {
		$t = $this->createMock( Title::class );
		$t->method( 'getNamespace' )->willReturn( $namespace );
		$t->method( 'exists' )->willReturn( true );
		return $t;
	}

	/**
	 * Build a service whose one page carries $existingWikitext, capturing
	 * the wikitext handed to doUserEditContent into $captured.
	 *
	 * @param string $existingWikitext
	 * @param object $captured Holds ->text after the edit runs.
	 * @param Authority|null $authority Defaults to allow-all.
	 * @return BulkEditService
	 */
	private function makeService(
		string $existingWikitext, object $captured, ?Authority $authority = null
	): array {
		$title = $this->makeTitle();
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $title );

		$content = new \WikitextContent( $existingWikitext );
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getContent' )->willReturn( $content );
		$page->method( 'doUserEditContent' )->willReturnCallback(
			static function ( $newContent ) use ( $captured ) {
				$captured->text = $newContent->getText();
				return PageUpdateStatus::newGood();
			}
		);

		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->method( 'newFromTitle' )->willReturn( $page );

		if ( $authority === null ) {
			$authority = $this->createMock( Authority::class );
			$authority->method( 'authorizeWrite' )->willReturn( true );
		}

		$service = new BulkEditService( $wpf, $titleFactory );
		return [ $service, $authority ];
	}

	public function testPipeInValueIsNeutralized(): void {
		$captured = (object)[ 'text' => null ];
		[ $service, $authority ] = $this->makeService(
			'{{Expense|category=Old|date=2026-05-01}}', $captured
		);
		$service->setField(
			[ 'Expense:1' ], 'category', 'Evil|total=999',
			$this->createMock( User::class ), $authority, 'summary'
		);
		// The literal pipe must not survive as a template delimiter.
		$this->assertStringNotContainsString( 'Evil|total=999', $captured->text );
		$this->assertStringContainsString( '&#124;total=999', $captured->text );
		// A stray second parameter was not injected.
		$this->assertStringContainsString( '|date=2026-05-01', $captured->text );
	}

	public function testTemplateBraceSequencesAreNeutralized(): void {
		$captured = (object)[ 'text' => null ];
		[ $service, $authority ] = $this->makeService(
			'{{Expense|category=Old}}', $captured
		);
		$service->setField(
			[ 'Expense:1' ], 'category', '}}{{Delete}}',
			$this->createMock( User::class ), $authority, 'summary'
		);
		$this->assertStringNotContainsString( '}}{{Delete}}', $captured->text );
		$this->assertStringContainsString( '&#125;&#125;&#123;&#123;', $captured->text );
	}

	public function testBackreferenceShapedValueIsLiteral(): void {
		// `\1` / `$1` must not be interpreted as a regex backreference by
		// the replacement step — the value is written verbatim.
		$captured = (object)[ 'text' => null ];
		[ $service, $authority ] = $this->makeService(
			'{{Expense|category=Old}}', $captured
		);
		$service->setField(
			[ 'Expense:1' ], 'category', 'A\1B$1C',
			$this->createMock( User::class ), $authority, 'summary'
		);
		$this->assertStringContainsString( '|category=A\1B$1C', $captured->text );
	}

	public function testNormalValueUnchanged(): void {
		$captured = (object)[ 'text' => null ];
		[ $service, $authority ] = $this->makeService(
			'{{Expense|category=Old}}', $captured
		);
		$result = $service->setField(
			[ 'Expense:1' ], 'category', 'Travel/Meals',
			$this->createMock( User::class ), $authority, 'summary'
		);
		$this->assertSame( 1, $result['updated'] );
		$this->assertStringContainsString( '|category=Travel/Meals', $captured->text );
	}

	public function testDeniedPageIsSkippedNotEdited(): void {
		$captured = (object)[ 'text' => null ];
		$authority = $this->createMock( Authority::class );
		$authority->method( 'authorizeWrite' )->willReturn( false );
		[ $service ] = $this->makeService(
			'{{Expense|category=Old}}', $captured, $authority
		);
		$result = $service->setField(
			[ 'Expense:1' ], 'category', 'New',
			$this->createMock( User::class ), $authority, 'summary'
		);
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );
		// Nothing was written.
		$this->assertNull( $captured->text );
	}

	public function testDeniedPageNeverReachesWikiPageFactory(): void {
		$title = $this->makeTitle();
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $title );

		$authority = $this->createMock( Authority::class );
		$authority->method( 'authorizeWrite' )->willReturn( false );

		$wpf = $this->createMock( WikiPageFactory::class );
		$wpf->expects( $this->never() )->method( 'newFromTitle' );

		$service = new BulkEditService( $wpf, $titleFactory );
		$result = $service->setField(
			[ 'Expense:1' ], 'category', 'New',
			$this->createMock( User::class ), $authority, 'summary'
		);
		$this->assertSame( 1, $result['skipped'] );
	}
}
