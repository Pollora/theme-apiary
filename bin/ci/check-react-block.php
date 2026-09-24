<?php

/**
 * Prove a classic Gutenberg React block works, end to end.
 *
 * Run inside WordPress, from the Pollora project root, in the container:
 *
 *   wp eval-file theme-under-test/bin/ci/check-react-block.php <namespace/slug>
 *
 * A classic block is the plain Gutenberg shape: a save.jsx that writes markup
 * into post_content, and no `render` in block.json. Everything else in this
 * theme is either a Blade-rendered block or, in the case of the shipped
 * example, an ACF one — so the native path had no coverage anywhere: not the
 * scaffolding, not the Vite entry it adds, not the manifest lookup that turns
 * that entry into a URL WordPress can enqueue.
 *
 * That last link is the one worth watching. It broke once already, silently:
 * vite.config.js globbed a blocks directory that did not exist, so no block
 * asset was ever built, and nothing failed — a build that matches no entry
 * simply builds no entry.
 *
 * No `declare(strict_types=1)`: `wp eval-file` evaluates the contents rather
 * than including the file, and that declaration is only legal as the very
 * first statement of a script.
 */

$blockName = $args[0] ?? '';

if ($blockName === '') {
    fwrite(STDERR, "Usage: wp eval-file .../check-react-block.php <namespace/slug>\n");
    exit(2);
}

$failures = [];

function ok(string $message): void
{
    echo "  \033[32m✓\033[0m  {$message}\n";
}

function ko(string $message): void
{
    global $failures;
    $failures[] = $message;
    echo "  \033[31m✗\033[0m  {$message}\n";
}

echo "\n\033[1m── A classic React block, end to end ──\033[0m\n";

$type = WP_Block_Type_Registry::get_instance()->get_registered($blockName);

if (! $type instanceof WP_Block_Type) {
    ko("{$blockName} is not registered");

    // Everything needed to tell which link of the chain broke, printed here
    // rather than guessed at from outside.
    // The framework registers the theme's resources/views/blocks by
    // convention since v13.32.0-beta.7: no provider is involved.
    $theme = get_stylesheet_directory();
    $blockDir = $theme.'/resources/views/blocks/'.basename(str_replace('/', '-', $blockName));

    echo "\n\033[1mWhere the chain stands\033[0m\n";
    echo '  theme directory          '.$theme."\n";

    echo '  blocks directory         '.(is_dir(dirname($blockDir)) ? implode(', ', array_diff(scandir(dirname($blockDir)), ['.', '..'])) : 'ABSENT')."\n";

    // The decisive one: a class that autoloads is not the same as a provider
    // Laravel has registered and booted.
    $loaded = array_keys(app()->getLoadedProviders());
    $themeProviders = array_values(array_filter($loaded, fn ($c) => str_starts_with($c, 'Theme\\')));
    echo '  theme providers Laravel loaded   '.($themeProviders === [] ? '(none)' : implode(', ', $themeProviders))."\n";
    echo '  app.debug                '.(config('app.debug') ? 'true' : 'false')."\n";
    echo '  register_block_type()    '.(function_exists('register_block_type') ? 'available' : 'ABSENT')."\n";

    $all = array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered());
    $mine = array_values(array_filter(
        $all,
        fn ($n) => ! str_starts_with($n, 'core/') && ! str_starts_with($n, 'woocommerce/')
    ));
    echo '  blocks that are neither core nor woocommerce  '.($mine === [] ? '(none)' : implode(', ', $mine))."\n";

    echo "\n\033[31m1 check failed.\033[0m\n\n";
    exit(1);
}

ok("{$blockName} is registered");

// The defining property of a classic block: WordPress serves the markup save()
// stored in post_content. A render_callback would override it — with nothing,
// since there is no render file to call.
if (is_callable($type->render_callback)) {
    ko('it was given a render_callback, which would replace the markup save() stored');
} else {
    ok('no render_callback: WordPress serves the markup save() stored');
}

$handles = (array) $type->editor_script_handles;

if ($handles === []) {
    ko('no editor script handle — the block has no JavaScript in the editor');
} else {
    ok('an editor script handle is registered: '.implode(', ', $handles));
}

// The link that broke silently before. A handle whose src is empty, or which
// still points at a source file rather than a built asset, means the Vite
// entry never reached the manifest.
foreach ($handles as $handle) {
    $registered = wp_scripts()->registered[$handle] ?? null;
    $src = $registered->src ?? '';

    if (! is_string($src) || $src === '') {
        ko("{$handle} resolved to an empty URL — the Vite manifest has no entry for it");

        continue;
    }

    if (str_contains($src, '/build/')) {
        ok("{$handle} resolves to a built asset: {$src}");
    } else {
        ko("{$handle} does not point into the build: {$src}");
    }
}

if ($failures !== []) {
    printf("\n\033[31m%d check%s failed.\033[0m\n\n", count($failures), count($failures) === 1 ? '' : 's');
    exit(1);
}

echo "\n\033[32mA classic React block scaffolds, builds, registers and enqueues.\033[0m\n\n";
