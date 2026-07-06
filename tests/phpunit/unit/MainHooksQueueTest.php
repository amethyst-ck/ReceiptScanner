<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\Hooks\MainHooks;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use ReflectionMethod;
use WikiPage;

/**
 * MainHooks' queue-lifecycle glue: auto-consume on receipt-page save,
 * queue-row cleanup on file deletion, and assignee user-page purging.
 * Private helpers are exercised via reflection (mirroring
 * ReceiptReviewActionsTest).
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Hooks\MainHooks
 */
class MainHooksQueueTest extends MediaWikiUnitTestCase {

	private function build(
		?QueueStore $queueStore = null,
		?TitleFactory $titleFactory = null,
		?WikiPageFactory $wikiPageFactory = null
	): MainHooks {
		return new MainHooks(
			$queueStore ?? $this->createMock( QueueStore::class ),
			$this->createMock( CategoryVocabulary::class ),
			$titleFactory ?? $this->createMock( TitleFactory::class ),
			$wikiPageFactory ?? $this->createMock( WikiPageFactory::class ),
			$this->createMock( RevisionLookup::class )
		);
	}

	private function call( MainHooks $hooks, string $method, ...$args ) {
		$m = new ReflectionMethod( $hooks, $method );
		$m->setAccessible( true );
		return $m->invoke( $hooks, ...$args );
	}

	/** RevisionRecord mock whose main-slot content is the given wikitext. */
	private function makeRevision( string $wikitext ): RevisionRecord {
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getContent' )->with( SlotRecord::MAIN )
			->willReturn( new \WikitextContent( $wikitext ) );
		return $rev;
	}

	// ----- maybeAutoConsume -----

	public function testAutoConsumeConsumesReadyRowNamedByQueueId(): void {
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'get' )->with( 42 )
			->willReturn( [ 'rsq_id' => 42, 'rsq_status' => 'ready' ] );
		$queueStore->expects( $this->once() )
			->method( 'setConsumed' )->with( 42, 123 );

		$title = $this->createMock( Title::class );
		$title->method( 'getArticleID' )->willReturn( 123 );

		$this->call(
			$this->build( $queueStore ),
			'maybeAutoConsume',
			$title,
			$this->makeRevision( "{{Expense\n|date=2026-05-01\n|queue_id=42\n}}" )
		);
	}

	public function testAutoConsumeIgnoresNonReadyRow(): void {
		// Idempotence across subsequent edits: a consumed (or otherwise
		// non-ready) row must not be re-consumed.
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'get' )->with( 42 )
			->willReturn( [ 'rsq_id' => 42, 'rsq_status' => 'consumed' ] );
		$queueStore->expects( $this->never() )->method( 'setConsumed' );

		$this->call(
			$this->build( $queueStore ),
			'maybeAutoConsume',
			$this->createMock( Title::class ),
			$this->makeRevision( "{{Expense\n|queue_id=42\n}}" )
		);
	}

	public function testAutoConsumeIgnoresPagesWithoutQueueIdParam(): void {
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->expects( $this->never() )->method( 'get' );
		$queueStore->expects( $this->never() )->method( 'setConsumed' );

		$this->call(
			$this->build( $queueStore ),
			'maybeAutoConsume',
			$this->createMock( Title::class ),
			$this->makeRevision( "{{Expense\n|date=2026-05-01\n}}" )
		);
	}

	// ----- onFileDeleteComplete -----

	public function testFileDeletionDropsNonConsumedRows(): void {
		$file = $this->createMock( \File::class );
		$file->method( 'getSha1' )->willReturn( 'abc' );
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->expects( $this->once() )
			->method( 'deleteNonConsumedBySha1' )->with( 'abc' );

		$this->build( $queueStore )
			->onFileDeleteComplete( $file, null, null, null, 'reason' );
	}

	public function testOldRevisionDeletionLeavesQueueAlone(): void {
		// $oldimage set means an old file revision was deleted, not the
		// file itself — the queue row must survive.
		$file = $this->createMock( \File::class );
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->expects( $this->never() )->method( 'deleteNonConsumedBySha1' );

		$this->build( $queueStore )
			->onFileDeleteComplete( $file, '20260101000000!R.pdf', null, null, 'reason' );
	}

	// ----- purgeUserPages -----

	public function testPurgeUserPagesDeduplicatesAndSkipsBlanks(): void {
		// Two distinct real names among duplicates, blanks, and nulls →
		// exactly two purges.
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( true );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitleSafe' )
			->with( NS_USER, $this->anything() )
			->willReturn( $title );

		$page = $this->createMock( WikiPage::class );
		$page->expects( $this->exactly( 2 ) )->method( 'doPurge' );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->expects( $this->exactly( 2 ) )
			->method( 'newFromTitle' )->willReturn( $page );

		$this->call(
			$this->build( null, $titleFactory, $wikiPageFactory ),
			'purgeUserPages',
			[ 'Alice', 'Alice', '', null, 'Bob' ]
		);
	}

	public function testPurgeUserPagesSkipsNonexistentUserPages(): void {
		$title = $this->createMock( Title::class );
		$title->method( 'exists' )->willReturn( false );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitleSafe' )->willReturn( $title );

		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->expects( $this->never() )->method( 'newFromTitle' );

		$this->call(
			$this->build( null, $titleFactory, $wikiPageFactory ),
			'purgeUserPages',
			[ 'Ghost' ]
		);
	}
}
