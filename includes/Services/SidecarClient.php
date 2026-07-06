<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Http\HttpRequestFactory;

/**
 * HTTP client for the receipt-scanner sidecar. Multipart bodies are
 * built by hand (MWHttpRequest has no helper). Errors raise
 * {@see SidecarException} with a code SpecialReceiptReview can translate.
 */
class SidecarClient {

	public const ERR_UNREADABLE = 'unreadable';
	public const ERR_REQUEST = 'request';
	public const ERR_BAD_RESPONSE = 'badresponse';

	public const CONSTRUCTOR_OPTIONS = [
		'ReceiptScannerSidecarUrl',
		'ReceiptScannerSidecarTimeout',
		'ReceiptScannerSidecarSecret',
	];

	private const MIME_BY_EXT = [
		'pdf' => 'application/pdf',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'heic' => 'image/heic',
		'heif' => 'image/heif',
	];

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * POST a file to /parse and return the decoded JSON response.
	 *
	 * @param string $filePath Local filesystem path to the file.
	 * @param string $fileName Name to send in the multipart part.
	 * @param ReceiptKind $kind Sidecar branches its heuristics based on
	 *   this (payee vs payer).
	 * @return array<string,mixed>
	 * @throws SidecarException on read error, non-2xx, timeout, or bad JSON.
	 */
	public function parse(
		string $filePath, string $fileName, ReceiptKind $kind = ReceiptKind::Expense
	): array {
		$contents = file_get_contents( $filePath );
		if ( $contents === false ) {
			throw new SidecarException(
				self::ERR_UNREADABLE,
				"could not read file: $filePath"
			);
		}

		// Escape quotes / strip CR-LF so the filename can't corrupt the
		// multipart header; the sanitized value is also what gets signed.
		$sanitizedFileName = str_replace(
			[ '"', "\r", "\n" ],
			[ '%22', '', '' ],
			$fileName
		);

		$boundary = '----ReceiptScanner' . bin2hex( random_bytes( 12 ) );
		$body = $this->buildMultipart( $boundary, $sanitizedFileName, $contents, $kind->value );

		$url = rtrim( $this->options->get( 'ReceiptScannerSidecarUrl' ), '/' ) . '/parse';
		$req = $this->httpRequestFactory->create(
			$url,
			[
				'method' => 'POST',
				'timeout' => $this->options->get( 'ReceiptScannerSidecarTimeout' ),
				'postData' => $body,
			],
			__METHOD__
		);
		$req->setHeader( 'Content-Type', "multipart/form-data; boundary=$boundary" );

		$secret = $this->options->get( 'ReceiptScannerSidecarSecret' );
		if ( $secret !== '' ) {
			// Bind kind + filename + body into a canonical signed string:
			//   kind + "\n" + filename + "\n" + contents
			$mac = hash_hmac(
				'sha256',
				$kind->value . "\n" . $sanitizedFileName . "\n" . $contents,
				$secret
			);
			$req->setHeader( 'X-ReceiptScanner-HMAC', $mac );
		}

		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new SidecarException(
				self::ERR_REQUEST,
				'sidecar request failed: ' . $status->__toString()
			);
		}

		$decoded = json_decode( $req->getContent(), true );
		if ( !is_array( $decoded ) || !isset( $decoded['text_source'] ) ) {
			throw new SidecarException(
				self::ERR_BAD_RESPONSE,
				'malformed sidecar response'
			);
		}
		return $decoded;
	}

	private function buildMultipart(
		string $boundary,
		string $fileName,
		string $contents,
		string $kind
	): string {
		$ext = strtolower( pathinfo( $fileName, PATHINFO_EXTENSION ) );
		$mime = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
		return "--$boundary\r\n"
			. "Content-Disposition: form-data; name=\"kind\"\r\n\r\n"
			. $kind . "\r\n"
			. "--$boundary\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"$fileName\"\r\n"
			. "Content-Type: $mime\r\n\r\n"
			. $contents . "\r\n"
			. "--$boundary--\r\n";
	}
}
