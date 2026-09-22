#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Put the commit under test on a site, the way a user would receive it.
 *
 * Run from the Pollora project root, inside the container:
 *
 *   php theme-under-test/bin/ci/install.php \
 *       --url=https://site.ddev.site [--slug=apiary] [--source=/path/to/this/repo]
 *
 * This theme is a template, not a directory to copy: `style.css` carries
 * `%theme_name%` and the providers carry `%theme_namespace%`, and a user only
 * ever sees them substituted, by `pollora:make:theme --repository`.
 *
 * So CI scaffolds the published tag the way a user does, then overlays the
 * commit under test on top with the same substitution. Without the overlay a
 * pull request would measure the last release and report it as the branch
 * being green; without the scaffold it would measure a theme no user receives.
 *
 * It installs and activates. It asserts nothing: bin/tests/sweep.php does that,
 * and it does it against a site rather than against a directory.
 */

require_once dirname(__DIR__).'/replacements.php';

$options = getopt('', ['url:', 'slug::', 'source::']);

if (! isset($options['url'])) {
    fwrite(STDERR, "\nUsage: php bin/ci/install.php --url=<site url> [--slug=apiary] [--source=<theme repo>]\n\n");
    exit(2);
}

$baseUrl = rtrim((string) $options['url'], '/');
$slug = (string) ($options['slug'] ?? 'apiary');
$source = isset($options['source']) ? rtrim((string) $options['source'], '/') : null;

$host = (string) parse_url($baseUrl, PHP_URL_HOST);

// This script activates a theme and flushes rewrite rules. Neither belongs on
// a site anyone is using.
if (! str_ends_with($host, '.ddev.site') && $host !== 'localhost' && ! str_starts_with($host, '127.')) {
    fwrite(STDERR, "\n\033[31mRefusing to run against {$host}.\033[0m\nThis script activates a theme; it only runs against a local or CI host.\n\n");
    exit(2);
}

/** @return array{code: int, out: string} */
function run(string $command): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command.' 2>&1', $descriptors, $pipes);

    if (! is_resource($process)) {
        return ['code' => 1, 'out' => 'could not start: '.$command];
    }

    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out];
}

function step(string $message): void
{
    echo "  \033[2m→ {$message}\033[0m\n";
}

function fail(string $title, string $detail): never
{
    fwrite(STDERR, "\n\033[31m{$title}\033[0m\n{$detail}\n\n");
    exit(1);
}

/**
 * Copy the commit under test over the generated theme, substituting the
 * placeholders the scaffolder would have substituted.
 *
 * `bin` and `.github` are skipped: the scaffolder strips them too, so a user
 * never receives them and neither should the theme CI installs.
 */
function overlaySource(string $source, string $themeDir, string $slug): int
{
    $replacements = scaffolderReplacements($slug);
    $skip = ['.git', 'node_modules', 'bin', '.github', 'package-lock.json', 'yarn.lock', 'dist', 'public'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), $skip, true)
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $copied = 0;

    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $target = $themeDir.'/'.$relative;

        if ($file->isDir()) {
            is_dir($target) || mkdir($target, 0755, true);

            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // Binary files carry no placeholders and must not be rewritten.
        if (preg_match('//u', $contents) === 1) {
            $contents = str_replace(array_keys($replacements), array_values($replacements), $contents);
        }

        file_put_contents($target, $contents);
        $copied++;
    }

    return $copied;
}

echo "\n\033[1m=== theme-apiary — install ===\033[0m\n";
echo "\033[2m{$baseUrl} · theme {$slug}\033[0m\n\n";

// The command was renamed in 13.32; the alias is still registered, but asking
// is cheaper than assuming.
$list = run('php artisan list --raw');
$command = str_contains($list['out'], 'pollora:make:theme') ? 'pollora:make:theme' : 'pollora:make-theme';

$themeDir = getcwd().'/themes/'.$slug;

if (is_dir($themeDir)) {
    step("removing the previous {$slug}");
    run('rm -rf '.escapeshellarg($themeDir));
}

step("scaffolding with {$command} --repository=pollora/theme-apiary");

// The scaffolder fetches an archive from GitHub, so this can fail for reasons
// that have nothing to do with the commit under test. One retry, then the
// failure stands: retrying forever would turn a broken template into a slow
// green build.
$scaffold = ['code' => 1, 'out' => ''];

foreach ([1, 2] as $attempt) {
    $scaffold = run('php artisan '.$command.' '.escapeshellarg($slug)
        .' --repository=pollora/theme-apiary'
        .' --theme-author=Pollora --theme-description='.escapeshellarg('Theme under test')
        .' --theme-version=1.0.0 --force --no-interaction');

    if ($scaffold['code'] === 0) {
        break;
    }

    if ($attempt === 1) {
        echo "  \033[33m→ scaffolding failed, retrying once\033[0m\n";
        sleep(10);
    }
}

if ($scaffold['code'] !== 0) {
    fail('Scaffolding failed twice:', $scaffold['out']);
}

if (! is_dir($themeDir)) {
    fail('The scaffolder reported success but the theme is not there:', $themeDir.' does not exist.');
}

if ($source !== null) {
    $copied = overlaySource($source, $themeDir, $slug);
    step("overlaid {$copied} files from the commit under test");
}

// A theme whose assets were never built renders, but its editor bundle and its
// stylesheet do not exist — and the sweep asks for both.
if (is_file($themeDir.'/package.json')) {
    step('building assets');
    $build = run('cd '.escapeshellarg($themeDir).' && npm install --no-audit --no-fund && npm run build');

    if ($build['code'] !== 0) {
        fail('The theme build failed:', $build['out']);
    }
}

$activated = run('wp theme activate '.escapeshellarg($slug));

if ($activated['code'] !== 0) {
    fail('Could not activate the theme:', $activated['out']);
}

step('activated');

// Product and category URLs are permalink-shaped. Without this the sweep's
// /product/<slug>/ checks answer 404 for a reason that is not the theme's.
run('wp rewrite structure '.escapeshellarg('/%postname%/').' --hard');
run('wp rewrite flush --hard');
step('permalinks set and flushed');

echo "\n\033[32mInstalled.\033[0m\n\n";
