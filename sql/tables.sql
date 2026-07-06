-- Queue of receipts awaiting OCR/parse and user review.
-- State machine: pending → processing → ready → consumed
--                                     ↘ failed (retryable)

CREATE TABLE /*_*/receipt_scanner_queue (
  rsq_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rsq_file_sha1     VARBINARY(32) NOT NULL,
  rsq_file_name     VARBINARY(255) NOT NULL,
  rsq_uploader      INT UNSIGNED NOT NULL,
  -- pending | processing | ready | failed | consumed
  rsq_status        VARBINARY(16) NOT NULL,
  -- 'expense' | 'income' — the user's intent at upload time, used to
  -- pick Form:Expense vs Form:Income on review.
  rsq_kind          VARBINARY(16) NOT NULL DEFAULT 'expense',
  rsq_text_source   VARBINARY(32) DEFAULT NULL,
  rsq_response      MEDIUMBLOB DEFAULT NULL,
  rsq_error         VARBINARY(255) DEFAULT NULL,
  rsq_enqueued_at   BINARY(14) NOT NULL,
  rsq_processed_at  BINARY(14) DEFAULT NULL,
  rsq_consumed_at   BINARY(14) DEFAULT NULL,
  rsq_receipt_page  INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (rsq_id)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/rsq_file_sha1 ON /*_*/receipt_scanner_queue (rsq_file_sha1);
CREATE INDEX /*i*/rsq_status ON /*_*/receipt_scanner_queue (rsq_status);
CREATE INDEX /*i*/rsq_uploader ON /*_*/receipt_scanner_queue (rsq_uploader);
