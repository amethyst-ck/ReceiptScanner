<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use JobQueueGroup;
use JobSpecification;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Services\UnlinkedFilesStore;
use MediaWiki\Extension\ReceiptScanner\Special\SpecialUnlinkedFiles;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use ReflectionMethod;
use RepoGroup;

/**
 * Special:UnlinkedFiles' list markup and the enqueue-existing-file
 * action semantics, exercised via reflection on the private methods
 * (mirroring ReceiptReviewActionsTest).
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialUnlinkedFiles
 */
class SpecialUnlinkedFilesTest extends MediaWikiUnitTestCase {

	/**
	 * Build the special page with the given collaborators, a mocked
	 * context, and getPageTitle() pinned to a Title mock (the real one
	 * resolves through the SpecialPageFactory service, unavailable in
	 * unit tests).
	 *
	 * @return array{page:SpecialUnlinkedFiles, html:object, out:OutputPage}
	 */
	private function build(
		?UnlinkedFilesStore $store = null,
		?RepoGroup $repoGroup = null,
		?QueueStore $queueStore = null,
		?JobQueueGroup $jobQueueGroup = null,
		?TitleFactory $titleFactory = null,
		bool $canDelete = false
	): array {
		$html = (object)[ 'value' => '', 'wikitext' => '' ];
		$out = $this->createMock( OutputPage::class );
		$out->method( 'addHTML' )->willReturnCallback(
			static function ( $h ) use ( $html ) {
				$html->value .= $h;
			}
		);
		$out->method( 'addWikiTextAsInterface' )->willReturnCallback(
			static function ( $w ) use ( $html ) {
				$html->wikitext .= $w;
			}
		);

		$token = $this->createMock( \MediaWiki\Session\Token::class );
		$token->method( '__toString' )->willReturn( 'fake-token' );
		$csrf = $this->createMock( CsrfTokenSet::class );
		$csrf->method( 'getToken' )->willReturn( $token );

		$authority = $this->createMock( Authority::class );
		$authority->method( 'probablyCan' )->willReturn( $canDelete );

		$user = $this->createMock( User::class );
		$user->method( 'getId' )->willReturn( 7 );

		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getCsrfTokenSet' )->willReturn( $csrf );
		$ctx->method( 'getAuthority' )->willReturn( $authority );
		$ctx->method( 'getUser' )->willReturn( $user );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		$pageTitle = $this->createMock( Title::class );
		$pageTitle->method( 'getLocalURL' )->willReturn( '/wiki/Special:UnlinkedFiles' );

		$page = new class(
			$store ?? $this->createMock( UnlinkedFilesStore::class ),
			$repoGroup ?? $this->createMock( RepoGroup::class ),
			$queueStore ?? $this->createMock( QueueStore::class ),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class ),
			$titleFactory ?? $this->createMock( TitleFactory::class ),
			$pageTitle
		) extends SpecialUnlinkedFiles {
			public function __construct(
				UnlinkedFilesStore $store,
				RepoGroup $repoGroup,
				QueueStore $queueStore,
				JobQueueGroup $jobQueueGroup,
				TitleFactory $titleFactory,
				private readonly Title $fixedPageTitle
			) {
				parent::__construct(
					$store, $repoGroup, $queueStore, $jobQueueGroup, $titleFactory
				);
			}

			public function getPageTitle( $subpage = false ) {
				return $this->fixedPageTitle;
			}
		};
		$page->setContext( $ctx );

		$linkRenderer = $this->createMock( LinkRenderer::class );
		$linkRenderer->method( 'makeLink' )->willReturnCallback(
			static fn ( $target, $text = null ) => '<a class="rs-test-name-link">' . $text . '</a>'
		);
		$page->setLinkRenderer( $linkRenderer );

		return [ 'page' => $page, 'html' => $html, 'out' => $out ];
	}

	/** Chainable Message mock whose text-returning methods echo the key. */
	private function mockMessage( string $key ): Message {
		$m = $this->createMock( Message::class );
		$m->method( 'text' )->willReturn( $key );
		$m->method( 'plain' )->willReturn( $key );
		foreach ( [ 'numParams', 'params', 'plaintextParams' ] as $chain ) {
			$m->method( $chain )->willReturnSelf();
		}
		return $m;
	}

	private function call( SpecialUnlinkedFiles $page, string $method, ...$args ) {
		$m = new ReflectionMethod( $page, $method );
		$m->setAccessible( true );
		return $m->invoke( $page, ...$args );
	}

	/** File-page Title whose delete URL is distinguishable. */
	private function makeFileTitle(): Title {
		$t = $this->createMock( Title::class );
		$t->method( 'getLocalURL' )->willReturnCallback(
			static fn ( $query = '' ) => is_array( $query ) && ( $query['action'] ?? '' ) === 'delete'
				? '/wiki/File:X?action=delete'
				: '/wiki/File:X'
		);
		return $t;
	}

	// ----- renderList -----

	public function testRenderListEmptyState(): void {
		$h = $this->build();
		$this->call( $h['page'], 'renderList', [] );
		$this->assertSame( '', $h['html']->value );
		$this->assertStringContainsString(
			'receiptscanner-unlinked-empty', $h['html']->wikitext
		);
	}

	public function testRenderListRowMarkupWithoutDeleteRight(): void {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturn( $this->makeFileTitle() );
		// findFile → null skips the synchronous-thumbnail branch.
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( null );

		$h = $this->build( null, $repoGroup, null, null, $titleFactory, false );
		$this->call( $h['page'], 'renderList', [ 'My_receipt.pdf' ] );
		$html = $h['html']->value;

		$this->assertStringContainsString( 'receiptscanner-unlinked-intro', $html );
		// Name link uses the display (spaces) form.
		$this->assertStringContainsString(
			'<a class="rs-test-name-link">My receipt.pdf</a>', $html
		);
		// Both per-kind Process buttons: shared form class, kind fields,
		// CSRF token, and their labels.
		$this->assertSame( 2, substr_count( $html, 'rs-unlinked-process-form' ) );
		$this->assertStringContainsString( 'receiptscanner-unlinked-process-expense', $html );
		$this->assertStringContainsString( 'receiptscanner-unlinked-process-income', $html );
		$this->assertSame( 2, substr_count( $html, 'name="kind"' ) );
		$this->assertStringContainsString( 'value="expense"', $html );
		$this->assertStringContainsString( 'value="income"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="process"' ) );
		$this->assertSame( 2, substr_count( $html, 'value="My receipt.pdf"' ) );
		$this->assertSame( 2, substr_count( $html, 'name="wpEditToken"' ) );
		// No delete right → no Delete link.
		$this->assertStringNotContainsString( 'mw-ui-destructive', $html );
		$this->assertStringNotContainsString( 'receiptscanner-unlinked-delete', $html );
	}

	public function testRenderListShowsDeleteLinkForDeleters(): void {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturn( $this->makeFileTitle() );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( null );

		$h = $this->build( null, $repoGroup, null, null, $titleFactory, true );
		$this->call( $h['page'], 'renderList', [ 'My_receipt.pdf' ] );
		$html = $h['html']->value;

		$this->assertStringContainsString( 'mw-ui-destructive', $html );
		$this->assertStringContainsString( 'receiptscanner-unlinked-delete', $html );
		// Routed into core's ?action=delete confirmation flow.
		$this->assertStringContainsString( 'action=delete', $html );
	}

	public function testRenderListSkipsThumbWhenTransformFails(): void {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturn( $this->makeFileTitle() );
		$file = $this->createMock( \File::class );
		$file->method( 'transform' )->willReturn( false );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );

		$h = $this->build( null, $repoGroup, null, null, $titleFactory, false );
		$this->call( $h['page'], 'renderList', [ 'My_receipt.pdf' ] );

		// Row still renders, thumb cell is just empty.
		$this->assertStringContainsString(
			'<td class="rs-unlinked-thumb-cell"></td>', $h['html']->value
		);
		$this->assertStringContainsString( 'rs-unlinked-process-form', $h['html']->value );
	}

	// ----- enqueueExistingFile -----

	/** File mock for the enqueue path. */
	private function makeFile(): \File {
		$file = $this->createMock( \File::class );
		$file->method( 'getSha1' )->willReturn( 'abc' );
		$file->method( 'getName' )->willReturn( 'r.pdf' );
		$file->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		return $file;
	}

	public function testEnqueueSkipsWhenFileMissing(): void {
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( null );
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->expects( $this->never() )->method( 'enqueue' );

		$h = $this->build( null, $repoGroup, $queueStore );
		$this->call( $h['page'], 'enqueueExistingFile', 'r.pdf', ReceiptKind::Expense );
	}

	public function testEnqueueSkipsWhenActiveRowCoversTheFile(): void {
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $this->makeFile() );
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'findActiveBySha1' )->with( 'abc' )
			->willReturn( [ 'rsq_id' => 99 ] );
		$queueStore->expects( $this->never() )->method( 'enqueue' );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )->method( 'lazyPush' );

		$h = $this->build( null, $repoGroup, $queueStore, $jobQueueGroup );
		$this->call( $h['page'], 'enqueueExistingFile', 'r.pdf', ReceiptKind::Expense );
	}

	public function testEnqueuePushesJobWhenNoActiveRow(): void {
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $this->makeFile() );
		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'findActiveBySha1' )->willReturn( null );
		$queueStore->expects( $this->once() )->method( 'enqueue' )
			->with( 'abc', 'r.pdf', 7, ReceiptKind::Income )
			->willReturn( 5 );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'lazyPush' )
			->with( $this->callback(
				static fn ( $job ) => $job instanceof JobSpecification
					&& $job->getParams()['rsq_id'] === 5
			) );

		$h = $this->build( null, $repoGroup, $queueStore, $jobQueueGroup );
		$this->call( $h['page'], 'enqueueExistingFile', 'r.pdf', ReceiptKind::Income );
	}
}
