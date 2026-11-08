<?php

// The gate's build, which scripts/gates.sh runs in PHP's image: every PHP file of the SDK, its specs
// and its example read by this PHP without a fault, and every class of the SDK loaded, by Composer's
// autoloader, by the name its path under src/ gives it. Each fault is named, and any fails the
// build.

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * The PHP files under a folder of the repository, sorted.
 *
 * @return list<string>
 */
function phpFilesUnder(string $folder): array
{
    if (!is_dir($folder)) {
        return [];
    }
    $files = [];
    $found = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($found as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

$faults = [];

$sources = phpFilesUnder("$root/src");
$files = [...$sources, ...phpFilesUnder("$root/tests"), ...phpFilesUnder("$root/examples")];
foreach ($files as $file) {
    $read = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $read, $status);
    if ($status !== 0) {
        $faults[] = implode("\n", $read);
    }
}

$composer = json_decode((string) file_get_contents("$root/composer.json"), true, 512, JSON_THROW_ON_ERROR);
$namespace = array_search('src/', $composer['autoload']['psr-4'], true);
if (!is_string($namespace)) {
    $faults[] = 'composer.json loads no namespace from src/.';
} else {
    require "$root/vendor/autoload.php";
    foreach ($sources as $file) {
        $name = $namespace . str_replace('/', '\\', substr($file, strlen("$root/src/"), -strlen('.php')));
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
            $faults[] = "$file doesn't define $name, the name its path gives it.";
        }
    }
}

if ($faults !== []) {
    fwrite(STDERR, implode("\n", $faults) . "\n");
    exit(1);
}
printf("Read %d files and loaded %d classes.\n", count($files), count($sources));
