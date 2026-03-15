<?php

declare(strict_types=1);

const DEFAULT_VERSION = '0.0.1';

$options = parseOptions($argv);
$rootDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
if ($rootDir === false) {
    throw new RuntimeException('Unable to resolve project root.');
}

$version = resolveVersion($options, $rootDir);
$distDir = $rootDir . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($distDir) && !mkdir($distDir, 0777, true) && !is_dir($distDir)) {
    throw new RuntimeException('Unable to create dist directory: ' . $distDir);
}

$outputFile = $options['output'] ?? ($distDir . DIRECTORY_SEPARATOR . sprintf('cloudreve_epay_bridge-v%s.php', $version));
$bundleFile = $distDir . DIRECTORY_SEPARATOR . sprintf('bundle-v%s.zip', $version);

buildBundle($rootDir, $bundleFile);
writeStandaloneFile($bundleFile, $outputFile, $version);

@unlink($bundleFile);

fwrite(STDOUT, "Built standalone file: {$outputFile}" . PHP_EOL);

function parseOptions(array $argv): array
{
    $options = [];

    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }

        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if ($arg === 'help') {
            fwrite(STDOUT, usage());
            exit(0);
        }

        [$key, $value] = array_pad(explode('=', $arg, 2), 2, null);
        if ($key !== '') {
            $options[$key] = $value ?? '1';
        }
    }

    return $options;
}

function resolveVersion(array $options, string $rootDir): string
{
    if (!empty($options['version'])) {
        return trim((string)$options['version']);
    }

    $versionFile = $rootDir . DIRECTORY_SEPARATOR . 'VERSION';
    if (is_file($versionFile)) {
        $version = trim((string)file_get_contents($versionFile));
        if ($version !== '') {
            return $version;
        }
    }

    return DEFAULT_VERSION;
}

function buildBundle(string $rootDir, string $bundleFile): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required for build-single script.');
    }

    if (file_exists($bundleFile)) {
        @unlink($bundleFile);
    }

    $excludeRoots = [
        '.git',
        '.github',
        '.idea',
        '.vscode',
        'dist',
        'node_modules',
        'vendor',
        'logs',
    ];

    $excludeFiles = [
        'database/orders.db',
        'database/orders.db-journal',
        'database/orders.db-shm',
        'database/orders.db-wal',
    ];

    $zip = new ZipArchive();
    $result = $zip->open($bundleFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($result !== true) {
        throw new RuntimeException('Unable to create bundle zip file.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();
        $relative = normalizePath(substr($path, strlen($rootDir) + 1));

        if ($relative === '' || shouldExclude($relative, $excludeRoots, $excludeFiles)) {
            continue;
        }

        if ($fileInfo->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }

        $zip->addFile($path, $relative);
    }

    $zip->close();
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', ltrim($path, '\\/'));
}

function shouldExclude(string $relative, array $excludeRoots, array $excludeFiles): bool
{
    $cleanPath = trim($relative, '/');
    if ($cleanPath === '') {
        return true;
    }

    if (in_array($cleanPath, $excludeFiles, true)) {
        return true;
    }

    $root = explode('/', $cleanPath)[0] ?? '';
    if (in_array($root, $excludeRoots, true)) {
        return true;
    }

    if (str_ends_with($cleanPath, '.log')) {
        return true;
    }

    return false;
}

function writeStandaloneFile(string $bundleFile, string $outputFile, string $version): void
{
    $launcher = getLauncherTemplate($version);
    file_put_contents($outputFile, $launcher);

    $source = fopen($bundleFile, 'rb');
    $target = fopen($outputFile, 'ab');

    if ($source === false || $target === false) {
        throw new RuntimeException('Unable to open bundle or output file.');
    }

    stream_copy_to_stream($source, $target);
    fclose($source);
    fclose($target);

    @chmod($outputFile, 0755);
}

function getLauncherTemplate(string $version): string
{
    $template = <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

const APP_VERSION = '__APP_VERSION__';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This file must be run in CLI mode." . PHP_EOL);
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required to extract this standalone package." . PHP_EOL);
    exit(1);
}

$options = parseRuntimeOptions($argv);
if (isset($options['help'])) {
    fwrite(STDOUT, runtimeUsage());
    exit(0);
}

$host = (string)($options['host'] ?? getenv('HOST') ?: '0.0.0.0');
$port = (string)($options['port'] ?? getenv('PORT') ?: '8080');
$runtimeBase = (string)($options['dir'] ?? getenv('CLOUDREVE_EPAY_HOME') ?: defaultRuntimeBase());
$targetDir = rtrim($runtimeBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cloudreve_epay_bridge-' . APP_VERSION;
$versionMarker = $targetDir . DIRECTORY_SEPARATOR . '.version';

$needsExtract = !is_dir($targetDir)
    || !is_file($versionMarker)
    || trim((string)@file_get_contents($versionMarker)) !== APP_VERSION;

if ($needsExtract) {
    if (is_dir($targetDir)) {
        deleteDirectory($targetDir);
    }

    if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Failed to create runtime directory: {$targetDir}" . PHP_EOL);
        exit(1);
    }

    $archivePath = $targetDir . DIRECTORY_SEPARATOR . '.bundle.zip';
    if (!extractEmbeddedArchive(__FILE__, $archivePath)) {
        fwrite(STDERR, "Failed to extract embedded archive." . PHP_EOL);
        exit(1);
    }

    $zip = new ZipArchive();
    $opened = $zip->open($archivePath);
    if ($opened !== true) {
        fwrite(STDERR, "Failed to open extracted archive." . PHP_EOL);
        exit(1);
    }

    if (!$zip->extractTo($targetDir)) {
        $zip->close();
        fwrite(STDERR, "Failed to unpack application files." . PHP_EOL);
        exit(1);
    }

    $zip->close();
    @unlink($archivePath);
    file_put_contents($versionMarker, APP_VERSION);
}

fwrite(STDOUT, sprintf("Cloudreve Epay Bridge %s" . PHP_EOL, APP_VERSION));
fwrite(STDOUT, sprintf("Serving at http://%s:%s" . PHP_EOL, $host, $port));

$cmd = quoteArg(PHP_BINARY) . ' -S ' . $host . ':' . $port . ' -t ' . quoteArg($targetDir);
passthru($cmd, $exitCode);
exit((int)$exitCode);

function parseRuntimeOptions(array $argv): array
{
    $options = [];

    foreach ($argv as $index => $arg) {
        if ($index === 0 || !str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, null);
        if ($key !== '') {
            $options[$key] = $value ?? '1';
        }
    }

    return $options;
}

function runtimeUsage(): string
{
    return <<<TXT
Usage: php cloudreve_epay_bridge-vX.Y.Z.php [options]

Options:
  --host=0.0.0.0   Bind host (default: 0.0.0.0)
  --port=8080      Bind port (default: 8080)
  --dir=/path      Runtime extract base directory
  --help           Show this help

TXT;
}

function defaultRuntimeBase(): string
{
    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
    return rtrim((string)$home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.cloudreve_epay_bridge';
}

function quoteArg(string $value): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    return escapeshellarg($value);
}

function extractEmbeddedArchive(string $sourceFile, string $archivePath): bool
{
    $in = fopen($sourceFile, 'rb');
    $out = fopen($archivePath, 'wb');

    if ($in === false || $out === false) {
        return false;
    }

    fseek($in, __COMPILER_HALT_OFFSET__);
    stream_copy_to_stream($in, $out);

    fclose($in);
    fclose($out);

    return true;
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

__halt_compiler();
PHP;

    return str_replace('__APP_VERSION__', $version, $template);
}

function usage(): string
{
    return <<<TXT
Usage: php scripts/build-single.php [options]

Options:
  --version=0.0.1          Build version. Defaults to VERSION file or 0.0.1.
  --output=dist/app.php    Output standalone file path.
  --help                   Show this help.

TXT;
}
