<?php
/**
 * Extension-local PHPUnit bootstrap.
 *
 * Sidesteps MediaWiki's full unit-test runner so the suite can run
 * under Canasta, where `getPHPUnitExtensionsAndSkins.php` sources
 * `LocalSettings.php` → `CanastaDefaultSettings.php` → calls
 * `$wgSettings->loadFile()` before `Setup.php` has initialized
 * `$wgSettings`, causing a fatal. Until that's fixed upstream, this
 * bootstrap loads only the minimum needed for `MediaWikiUnitTestCase`
 * subclasses and registers a tiny autoloader for the extension's own
 * namespaces.
 *
 * Run with:
 *   cd <MW-root>
 *   vendor/bin/phpunit -c extensions/ReceiptScanner/tests/phpunit/phpunit.xml.dist
 */

$mwRoot = realpath( __DIR__ . '/../../../..' );
if ( $mwRoot === false || !file_exists( "$mwRoot/tests/phpunit/MediaWikiUnitTestCase.php" ) ) {
	fwrite( STDERR,
		"ReceiptScanner test bootstrap: could not locate MediaWiki at "
		. ( $mwRoot ?: '(realpath failed)' ) . "\n"
	);
	exit( 1 );
}

require_once "$mwRoot/tests/phpunit/bootstrap.common.php";

// Mirror the "no integration tests" branch of tests/phpunit/bootstrap.php:
// register MediaWiki's own autoloader + a few core files, then apply
// MainConfigSchema defaults. Skipped here is the extension-discovery
// proc_open of getPHPUnitExtensionsAndSkins.php (which is what trips on
// Canasta's $wgSettings = null state).
$GLOBALS['wgAutoloadClasses'] = [];
$GLOBALS['wgBaseDirectory'] = MW_INSTALL_PATH;
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/AutoLoader.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/tests/common/TestsAutoLoader.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/Defines.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/GlobalFunctions.php' );
foreach ( MediaWiki\MainConfigSchema::listDefaultValues( 'wg' ) as $var => $value ) {
	$GLOBALS[$var] = $value;
}

require_once "$mwRoot/tests/phpunit/MediaWikiCoversValidator.php";
require_once "$mwRoot/tests/phpunit/MediaWikiTestCaseTrait.php";
require_once "$mwRoot/tests/phpunit/MediaWikiUnitTestCase.php";

// Tiny autoloader for the extension's classes and unit-test classes.
// extension.json's AutoloadNamespaces / TestAutoloadNamespaces aren't
// applied here because we skipped the MW extension-discovery step.
$extensionRoot = realpath( __DIR__ . '/../..' );
spl_autoload_register( static function ( $class ) use ( $extensionRoot ) {
	$prefixes = [
		'MediaWiki\\Extension\\ReceiptScanner\\Tests\\Unit\\' => $extensionRoot . '/tests/phpunit/unit/',
		'MediaWiki\\Extension\\ReceiptScanner\\Tests\\Integration\\' => $extensionRoot . '/tests/phpunit/integration/',
		'MediaWiki\\Extension\\ReceiptScanner\\' => $extensionRoot . '/includes/',
	];
	foreach ( $prefixes as $prefix => $dir ) {
		if ( str_starts_with( $class, $prefix ) ) {
			$rel = substr( $class, strlen( $prefix ) );
			$file = $dir . str_replace( '\\', '/', $rel ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
} );
