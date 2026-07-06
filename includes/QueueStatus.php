<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Lifecycle states for a row in `receipt_scanner_queue`.
 *
 *   Pending → Processing → Ready → Consumed
 *                       ↘ Failed (retryable)
 *
 * Backed by string values matching the historical `rsq_status` column
 * shape, so existing rows roundtrip without migration.
 */
enum QueueStatus: string {
	case Pending    = 'pending';
	case Processing = 'processing';
	case Ready      = 'ready';
	case Failed     = 'failed';
	case Consumed   = 'consumed';
}
