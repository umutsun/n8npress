<?php
/**
 * Build LuwiPress Gold theme ZIP for WordPress upload.
 *
 * Mirrors build-zip.php for the theme path, where the source folder is
 * `themes/luwipress-gold-elementor/` but the inside-ZIP folder must be
 * `luwipress-gold/` so it unzips into wp-content/themes/luwipress-gold/.
 *
 * Usage:
 *   php build-theme-zip.php 1.3.0
 *   php build-theme-zip.php 1.3.0 luwipress-gold-elementor luwipress-gold
 *
 * Args (all optional after version):
 *   1. version      Theme version, defaults to 1.3.0
 *   2. source_slug  Folder under themes/ to read from. Defaults luwipress-gold-elementor.
 *   3. output_slug  Folder name inside the ZIP + filename prefix. Defaults luwipress-gold.
 */

$version     = $argv[1] ?? '1.3.0';
$source_slug = $argv[2] ?? 'luwipress-gold-elementor';
$output_slug = $argv[3] ?? 'luwipress-gold';

$src = __DIR__ . '/themes/' . $source_slug;
$dst = __DIR__ . '/releases/' . $output_slug . '-' . $version . '.zip';

if ( ! is_dir( $src ) ) {
	die( "Source theme directory not found: $src\n" );
}

echo "Theme build:\n";
echo "  source : $src\n";
echo "  output : $dst\n";
echo "  inner  : {$output_slug}/\n";
echo "  version: $version\n\n";

// ── PHP syntax + BOM check ──
echo "Checking PHP syntax + BOM...\n";
$lint_errors = 0;
$bom_files   = array();
$lint_iter = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $lint_iter as $lint_file ) {
	if ( $lint_file->getExtension() !== 'php' ) continue;

	$fh = fopen( $lint_file->getRealPath(), 'rb' );
	if ( $fh ) {
		$head = fread( $fh, 3 );
		fclose( $fh );
		if ( $head === "\xEF\xBB\xBF" ) {
			$bom_files[] = str_replace( $src . DIRECTORY_SEPARATOR, '', $lint_file->getRealPath() );
		}
	}

	$out = array(); $code = 0;
	exec( 'php -l ' . escapeshellarg( $lint_file->getRealPath() ) . ' 2>&1', $out, $code );
	if ( $code !== 0 ) {
		$lint_errors++;
		$rel = str_replace( $src . DIRECTORY_SEPARATOR, '', $lint_file->getRealPath() );
		echo "  FAIL: $rel\n";
		foreach ( $out as $l ) {
			if ( stripos( $l, 'no syntax' ) === false ) echo "    $l\n";
		}
	}
}

if ( $lint_errors > 0 ) {
	die( "\nBLOCKED: $lint_errors syntax error(s).\n" );
}
if ( ! empty( $bom_files ) ) {
	echo "  BOM detected in:\n";
	foreach ( $bom_files as $bf ) echo "    $bf\n";
	die( "\nBLOCKED: " . count( $bom_files ) . " file(s) start with UTF-8 BOM.\n" );
}
echo "  All PHP files OK.\n\n";

// ── Sanity check: style.css Version line matches the build version ──
$style_css = $src . '/style.css';
if ( file_exists( $style_css ) ) {
	$header = file_get_contents( $style_css );
	if ( preg_match( '/^Version:\s*(\S+)/m', $header, $m ) ) {
		$declared = trim( $m[1] );
		if ( $declared !== $version ) {
			echo "  WARNING: style.css declares Version: {$declared} but building as {$version}.\n";
			echo "  Bump style.css to match before shipping.\n\n";
		} else {
			echo "  style.css Version matches: {$declared}.\n\n";
		}
	}
}

// ── Build ZIP ──
if ( ! is_dir( dirname( $dst ) ) ) {
	mkdir( dirname( $dst ), 0755, true );
}
if ( file_exists( $dst ) ) {
	unlink( $dst );
}

$zip = new ZipArchive();
if ( $zip->open( $dst, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	die( "Cannot create ZIP: $dst\n" );
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

$count = 0;
$skipped = 0;
foreach ( $files as $file ) {
	$real = $file->getRealPath();
	$rel  = str_replace( '\\', '/', substr( $real, strlen( $src ) + 1 ) );

	// Skip dotfiles + dev-only artifacts.
	if ( $rel === '' || strpos( $rel, '.' ) === 0 || strpos( $rel, '/.' ) !== false ) {
		$skipped++;
		continue;
	}
	// Skip editor / backup artifacts — never ship .bak / .orig / .tmp / ~
	// files (e.g. elementor-kit/*.json.pre1737.bak rollback copies).
	if ( preg_match( '/\.(bak|orig|tmp)$/i', $rel ) || substr( $rel, -1 ) === '~' ) {
		$skipped++;
		continue;
	}
	// Skip Claude design prompt + screenshot scaffolding (dev-only).
	if ( in_array( $rel, array( 'CLAUDE-DESIGN-PROMPT.md', 'CLAUDE-DESIGN-PROMPT-SHORT.md', 'SCREENSHOT.md' ), true ) ) {
		$skipped++;
		continue;
	}

	$zip->addFromString( $output_slug . '/' . $rel, file_get_contents( $real ) );
	$count++;
}

$zip->close();

$size = round( filesize( $dst ) / 1024 );
echo "ZIP created: $dst\n";
echo "Files included: $count (skipped $skipped dev-only)\n";
echo "Size: {$size} KB\n";
echo "SHA-256[:16]: " . substr( hash_file( 'sha256', $dst ), 0, 16 ) . "\n";
