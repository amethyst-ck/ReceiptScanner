<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use RuntimeException;
use Throwable;

/**
 * Sidecar-pipeline failure. `$errorCode` is UI-translatable (see
 * ReviewQueueRenderer::ERROR_MESSAGES); getMessage() carries the
 * English debug text for the log.
 */
class SidecarException extends RuntimeException {

	public function __construct(
		public readonly string $errorCode,
		string $debugMessage,
		?Throwable $previous = null
	) {
		parent::__construct( $debugMessage, 0, $previous );
	}
}
