<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Extension\ReceiptScanner\NotesEscaper;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;

/**
 * In-place bulk field updates across many Expense / Income pages.
 * Pages use a flat `{{Expense|key=value|...}}` template, so targeted
 * regexes suffice; nested templates inside values would break this.
 */
class BulkEditService {

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFactory $titleFactory
	) {
	}

	/**
	 * Set $field to $value across the named pages. Pages without the
	 * field get it inserted before the closing `}}`.
	 *
	 * @param string[] $pages Prefixed page names (e.g. "Expense:123")
	 * @param Authority $authority Acting authority; each page is edited
	 *   only if this authority may edit it. Pages the authority cannot
	 *   edit are counted in `skipped`, not written.
	 * @return array{updated:int, skipped:int, errors:string[]}
	 */
	public function setField(
		array $pages,
		string $field,
		string $value,
		User $user,
		Authority $authority,
		string $summary
	): array {
		$updated = 0;
		$skipped = 0;
		$errors = [];
		// Encode wikitext-significant sequences so a user-typed value
		// can't break out of the template.
		$value = NotesEscaper::encode( $value );

		foreach ( $pages as $pageName ) {
			$title = $this->titleFactory->newFromText( $pageName );
			if ( !$title || !$title->exists() ) {
				$errors[] = $this->errorMessage( 'receiptscanner-bulk-error-not-found', $pageName );
				continue;
			}
			// Only rewrite wikitext on Expense / Income pages — a botched
			// POST naming a random page must not reach the rewrite step.
			if ( !ReceiptKind::tryFromNamespace( $title->getNamespace() ) ) {
				$errors[] = $this->errorMessage( 'receiptscanner-bulk-error-wrong-namespace', $pageName );
				continue;
			}
			// doUserEditContent() doesn't enforce edit permissions; skip
			// pages the actor can't edit rather than failing the batch.
			// authorizeWrite also charges the actor's rate limits, so a
			// 500-page bulk edit is throttle-accounted like normal edits.
			if ( !$authority->authorizeWrite( 'edit', $title ) ) {
				$skipped++;
				continue;
			}
			$page = $this->wikiPageFactory->newFromTitle( $title );
			$content = $page->getContent();
			if ( !$content || !method_exists( $content, 'getText' ) ) {
				$errors[] = $this->errorMessage( 'receiptscanner-bulk-error-not-wikitext', $pageName );
				continue;
			}
			$old = $content->getText();
			$new = self::replaceTemplateField( $old, $field, $value );
			if ( $new === $old ) {
				$skipped++;
				continue;
			}

			$status = $page->doUserEditContent(
				new \WikitextContent( $new ),
				$user,
				$summary
			);
			if ( $status->isOK() ) {
				$updated++;
			} else {
				$errors[] = "$pageName: " . $status->getWikiText( false, false, 'en' );
			}
		}
		return [ 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors ];
	}

	/**
	 * One per-page error line for the bulk-edit flash. Content language
	 * because the lines are stashed in the session and rendered on a
	 * later request. Protected so unit tests (which cannot resolve
	 * messages) can stub the resolution.
	 */
	protected function errorMessage( string $key, string $pageName ): string {
		return wfMessage( $key, $pageName )->inContentLanguage()->text();
	}

	/**
	 * Rewrite a template parameter in flat wikitext: replace the value if
	 * the field exists, else insert before the first closing `}}`.
	 * Public static so the regex behavior is unit-testable.
	 */
	public static function replaceTemplateField(
		string $wikitext,
		string $field,
		string $value
	): string {
		$fieldRe = preg_quote( $field, '/' );
		// Match `|<field>=<value>` up to the next pipe / braces / newline.
		$pattern = '/(\|\s*' . $fieldRe . '\s*=)[^|}\r\n]*/';
		// Callbacks keep `$`/`\` in $value literal (no backreferences).
		if ( preg_match( $pattern, $wikitext ) ) {
			return preg_replace_callback(
				$pattern,
				static fn ( array $m ): string => $m[1] . $value,
				$wikitext,
				1
			);
		}
		// Field absent: insert just before the first closing `}}`.
		if ( preg_match( '/\}\}/', $wikitext ) ) {
			return preg_replace_callback(
				'/\}\}/',
				static fn (): string => "|$field=" . $value . "\n}}",
				$wikitext,
				1
			);
		}
		return $wikitext;
	}

	/**
	 * Pull every `|key=value` from the first template invocation (same
	 * flat single-template assumption as replaceTemplateField).
	 *
	 * @return array<string,string>
	 */
	public static function parseTemplateFields( string $wikitext ): array {
		// Capture the template body between the first | and the }}.
		if ( !preg_match( '/\{\{[^|}]*\|([\s\S]+?)\}\}/', $wikitext, $m ) ) {
			return [];
		}
		$body = $m[1];
		$fields = [];
		// Split on newline+| (PageForms puts each param on its own line),
		// keeping multiline values like notes intact.
		$parts = preg_split( '/\n\s*\|/', $body );
		foreach ( $parts as $part ) {
			if ( preg_match( '/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*([\s\S]*?)\s*$/', $part, $kv ) ) {
				$fields[$kv[1]] = $kv[2];
			}
		}
		return $fields;
	}
}
