<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;

/**
 * A self-submitting inline POST form: one action parameter, optional
 * extra hidden fields, a CSRF token, and a single submit button.
 * Shared by Special:ReceiptReview's row actions and
 * Special:UnlinkedFiles' per-kind Process buttons.
 */
class CsrfPostButton {

	/**
	 * @param IContextSource $context Source of the CSRF token.
	 * @param Title $action Page the form POSTs back to.
	 * @param string $field Name of the action parameter.
	 * @param string $value Value of the action parameter.
	 * @param string $label Button label.
	 * @param array<string,string> $extraHidden Additional hidden fields,
	 *   emitted between the action parameter and the CSRF token.
	 * @param array<string,string> $formAttribs Attributes appended after
	 *   method/action on the <form> (e.g. a styling class).
	 */
	public static function render(
		IContextSource $context,
		Title $action,
		string $field,
		string $value,
		string $label,
		array $extraHidden = [],
		array $formAttribs = [ 'style' => 'display:inline' ]
	): string {
		$hidden = Html::hidden( $field, $value );
		foreach ( $extraHidden as $name => $hiddenValue ) {
			$hidden .= Html::hidden( $name, $hiddenValue );
		}
		return Html::rawElement(
			'form',
			[
				'method' => 'post',
				'action' => $action->getLocalURL(),
			] + $formAttribs,
			$hidden
			. Html::hidden( 'wpEditToken', $context->getCsrfTokenSet()->getToken() )
			. Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button',
			], $label )
		);
	}
}
