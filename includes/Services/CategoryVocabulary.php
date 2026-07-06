<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use WANObjectCache;

/**
 * Parses the per-kind category vocabulary pages (an indented bullet
 * list) into flat hierarchical paths and caches the result.
 */
class CategoryVocabulary {

	public const CONSTRUCTOR_OPTIONS = [
		'ReceiptScannerExpenseCategoryPage',
		'ReceiptScannerIncomeCategoryPage',
	];

	public function __construct(
		private readonly WANObjectCache $cache,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Flatten an indented "*" bullet list into full paths:
	 * "* Travel\n** Meals" → ["Travel", "Travel/Meals"]. Depth jumps
	 * collapse to the next level. Pure function.
	 *
	 * @return string[]
	 */
	public static function parseList( string $wikitext ): array {
		$paths = [];
		$stack = [];
		foreach ( explode( "\n", $wikitext ) as $line ) {
			if ( !preg_match( '/^(\*+)\s*(\S.*?)\s*$/', $line, $m ) ) {
				continue;
			}
			$depth = strlen( $m[1] );
			$label = $m[2];
			$stack = array_slice( $stack, 0, $depth - 1 );
			$stack[] = $label;
			$paths[] = implode( '/', $stack );
		}
		return $paths;
	}

	/** Vocabulary page title for the kind; null if unparseable. */
	public function getCategoryPageTitle( ReceiptKind $kind ): ?Title {
		$setting = $kind === ReceiptKind::Income
			? 'ReceiptScannerIncomeCategoryPage'
			: 'ReceiptScannerExpenseCategoryPage';
		return $this->titleFactory->newFromText( $this->options->get( $setting ) );
	}

	/** @return string[] Flat category paths for the kind (cached). */
	public function getPaths( ReceiptKind $kind = ReceiptKind::Expense ): array {
		$title = $this->getCategoryPageTitle( $kind );
		if ( !$title ) {
			return [];
		}
		return $this->cache->getWithSetCallback(
			$this->cacheKey( $title ),
			WANObjectCache::TTL_DAY,
			function () use ( $title ) {
				$content = $this->wikiPageFactory->newFromTitle( $title )->getContent();
				if ( !$content || !method_exists( $content, 'getText' ) ) {
					return [];
				}
				return self::parseList( $content->getText() );
			}
		);
	}

	/** Invalidate the cache if the title is either vocabulary page. */
	public function maybeInvalidate( Title $title ): void {
		foreach ( ReceiptKind::cases() as $kind ) {
			$vocabTitle = $this->getCategoryPageTitle( $kind );
			if ( $vocabTitle && $title->equals( $vocabTitle ) ) {
				$this->cache->delete( $this->cacheKey( $vocabTitle ) );
				return;
			}
		}
	}

	private function cacheKey( Title $title ): string {
		return $this->cache->makeKey(
			'receiptscanner-categories',
			$title->getPrefixedDBkey()
		);
	}
}
