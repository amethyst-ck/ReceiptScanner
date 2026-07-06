<?php

namespace MediaWiki\Extension\ReceiptScanner;

use BitmapHandler;
use MediaWiki\Shell\Shell;

/**
 * BitmapHandler for HEIC/HEIF: PHP's getimagesize() can't decode HEIF
 * (uploads would get width=height=0), so size detection shells out to
 * ImageMagick's libheif-aware `identify`. Registered via
 * $wgMediaHandlers in settings/Settings.php.
 */
class HeicHandler extends BitmapHandler {

	public function getSizeAndMetadata( $state, $path ) {
		$identify = $GLOBALS['wgImageMagickIdentifyCommand'] ?? '/usr/bin/identify';
		$result = Shell::command( $identify, '-format', '%w %h', $path . '[0]' )
			->execute();
		if (
			$result->getExitCode() !== 0
			|| !preg_match( '/^(\d+)\s+(\d+)/', $result->getStdout(), $m )
		) {
			return [ 'width' => 0, 'height' => 0, 'metadata' => [] ];
		}
		return [
			'width' => (int)$m[1],
			'height' => (int)$m[2],
			'metadata' => [],
		];
	}

	/** Thumbs come out as JPEG — browsers can't decode HEIC inline. */
	public function getThumbType( $ext, $mime, $params = null ) {
		return [ 'jpg', 'image/jpeg' ];
	}

	/** Always thumbnail; never link straight to the HEIC source. */
	public function mustRender( $file ) {
		return true;
	}
}
