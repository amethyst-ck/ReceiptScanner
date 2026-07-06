<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarClient;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\SidecarClient
 */
class SidecarClientTest extends MediaWikiUnitTestCase {

	private function newClient( HttpRequestFactory $factory, array $config = [] ): SidecarClient {
		$config += [
			'ReceiptScannerSidecarUrl' => 'http://sidecar:8000',
			'ReceiptScannerSidecarTimeout' => 15,
			'ReceiptScannerSidecarSecret' => '',
		];
		return new SidecarClient(
			$factory,
			new ServiceOptions( SidecarClient::CONSTRUCTOR_OPTIONS, $config )
		);
	}

	/**
	 * @return MWHttpRequest&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockRequest( bool $ok, string $content, array &$headers = [] ) {
		$req = $this->createMock( MWHttpRequest::class );
		$req->method( 'execute' )->willReturn(
			$ok ? StatusValue::newGood() : StatusValue::newFatal( 'http-request-error' )
		);
		$req->method( 'getContent' )->willReturn( $content );
		$req->method( 'setHeader' )->willReturnCallback(
			static function ( $name, $value ) use ( &$headers ) {
				$headers[$name] = $value;
			}
		);
		return $req;
	}

	private function tempFile( string $contents = 'PDFBYTES' ): string {
		$path = tempnam( sys_get_temp_dir(), 'rs-test' );
		file_put_contents( $path, $contents );
		return $path;
	}

	public function testParseReturnsDecodedJson(): void {
		$json = json_encode( [
			'text_source' => 'text-layer',
			'fields' => [ 'total' => [ 'value' => '17.20', 'source' => 'generic' ] ],
		] );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $this->mockRequest( true, $json ) );

		$path = $this->tempFile();
		try {
			$result = $this->newClient( $factory )->parse( $path, 'receipt.pdf' );
		} finally {
			unlink( $path );
		}

		$this->assertSame( 'text-layer', $result['text_source'] );
		$this->assertSame( '17.20', $result['fields']['total']['value'] );
	}

	public function testThrowsOnNonOkStatus(): void {
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $this->mockRequest( false, '' ) );

		$path = $this->tempFile();
		try {
			$this->newClient( $factory )->parse( $path, 'receipt.pdf' );
			$this->fail( 'expected SidecarException' );
		} catch ( SidecarException $e ) {
			$this->assertSame( SidecarClient::ERR_REQUEST, $e->errorCode );
		} finally {
			unlink( $path );
		}
	}

	public function testThrowsOnMalformedJson(): void {
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $this->mockRequest( true, 'not json' ) );

		$path = $this->tempFile();
		try {
			$this->newClient( $factory )->parse( $path, 'receipt.pdf' );
			$this->fail( 'expected SidecarException' );
		} catch ( SidecarException $e ) {
			$this->assertSame( SidecarClient::ERR_BAD_RESPONSE, $e->errorCode );
		} finally {
			unlink( $path );
		}
	}

	public function testHmacHeaderSetWhenSecretConfigured(): void {
		$headers = [];
		$json = json_encode( [ 'text_source' => 'text-layer', 'fields' => [] ] );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $this->mockRequest( true, $json, $headers ) );

		$path = $this->tempFile( 'KNOWN-BYTES' );
		try {
			$this->newClient( $factory, [ 'ReceiptScannerSidecarSecret' => 'shh' ] )
				->parse( $path, 'receipt.pdf' );
		} finally {
			unlink( $path );
		}

		$this->assertArrayHasKey( 'X-ReceiptScanner-HMAC', $headers );
		// HMAC binds kind + filename + body: kind\nfilename\ncontents.
		$this->assertSame(
			hash_hmac( 'sha256', "expense\nreceipt.pdf\nKNOWN-BYTES", 'shh' ),
			$headers['X-ReceiptScanner-HMAC']
		);
	}

	public function testKindIncludedInMultipartBody(): void {
		$bodies = [];
		$json = json_encode( [ 'text_source' => 'text-layer', 'fields' => [] ] );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			function ( $url, $options, $caller ) use ( &$bodies, $json ) {
				$bodies[] = $options['postData'] ?? '';
				return $this->mockRequest( true, $json );
			}
		);

		$path = $this->tempFile();
		try {
			$this->newClient( $factory )->parse( $path, 'receipt.pdf', ReceiptKind::Income );
		} finally {
			unlink( $path );
		}

		$this->assertCount( 1, $bodies );
		$this->assertStringContainsString( "name=\"kind\"\r\n\r\nincome", $bodies[0] );
	}

	public function testKindDefaultsToExpense(): void {
		$bodies = [];
		$json = json_encode( [ 'text_source' => 'text-layer', 'fields' => [] ] );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			function ( $url, $options, $caller ) use ( &$bodies, $json ) {
				$bodies[] = $options['postData'] ?? '';
				return $this->mockRequest( true, $json );
			}
		);

		$path = $this->tempFile();
		try {
			$this->newClient( $factory )->parse( $path, 'receipt.pdf' );
		} finally {
			unlink( $path );
		}

		$this->assertStringContainsString( "name=\"kind\"\r\n\r\nexpense", $bodies[0] );
	}

	public function testNoHmacHeaderWhenSecretEmpty(): void {
		$headers = [];
		$json = json_encode( [ 'text_source' => 'text-layer', 'fields' => [] ] );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $this->mockRequest( true, $json, $headers ) );

		$path = $this->tempFile();
		try {
			$this->newClient( $factory )->parse( $path, 'receipt.pdf' );
		} finally {
			unlink( $path );
		}

		$this->assertArrayNotHasKey( 'X-ReceiptScanner-HMAC', $headers );
	}
}
