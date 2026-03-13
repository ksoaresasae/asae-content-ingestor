<?php
/**
 * Builds the release zip with forward slashes for WordPress compatibility.
 *
 * Run from the plugin root directory:
 *   cd asae-content-ingestor && php build-zip.php
 *
 * Or from the repo root:
 *   php asae-content-ingestor/build-zip.php
 *
 * Output: releases/asae-content-ingestor.zip
 *
 * IMPORTANT: Do NOT use PowerShell's Compress-Archive — it writes backslash
 * path separators which break the WordPress plugin installer.
 *
 * @package ASAE_Content_Ingestor
 */

// Resolve paths relative to this script's location (the plugin root).
$pluginDir = __DIR__;
$repoRoot  = dirname( $pluginDir );
$zipPath   = $pluginDir . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'asae-content-ingestor.zip';

// Directories and files to exclude from the zip.
$exclude = [ '.git', '.claude', 'releases', 'instructions', 'node_modules', '.gitignore', 'build-zip.php' ];

$zip = new ZipArchive();
if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	echo "ERROR: Could not create zip at: $zipPath\n";
	exit( 1 );
}

// Walk the plugin directory tree.
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $pluginDir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $file ) {
	// Build a relative path with forward slashes, prefixed with the plugin folder name.
	$relative = 'asae-content-ingestor/' . str_replace( '\\', '/', $iterator->getSubPathname() );
	$parts    = explode( '/', $relative );

	// Check exclusions.
	$skip = false;
	foreach ( $parts as $part ) {
		if ( in_array( $part, $exclude, true ) ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		continue;
	}

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative . '/' );
	} else {
		$zip->addFile( $file->getPathname(), $relative );
	}
}

echo 'Files in zip: ' . $zip->numFiles . "\n";
$zip->close();
echo "Release zip built: $zipPath\n";
