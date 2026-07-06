<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

/**
 * msg() passthrough to the injected IContextSource, for renderer
 * classes that hold a `private IContextSource $context`.
 */
trait MsgTrait {

	private function msg( string $key, ...$params ) {
		return $this->context->msg( $key, ...$params );
	}
}
