#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The Gutenberg design system in theme.json points at what exists.
 *
 * Usage:  php bin/tests/design-system.php
 *
 * WordPress drops a style whose value is an undefined variable, silently: a
 * preset referenced by its raw slug (`5xl` where WordPress prints `5-xl`), a
 * Tailwind variable (`var(--text-xl)`, undefined in the editor), or a block
 * `css` rule written as a selector list, which WordPress breaks apart. None of
 * it errors; the block just looks wrong in the editor or on the page.
 */

$root = dirname(__DIR__, 2);
$failures = 0;

function check(string $name, bool|string $result): void
{
    global $failures;

    if ($result === true) {
        echo "  \033[32m✓\033[0m  {$name}\n";

        return;
    }

    $failures++;
    echo "  \033[31m✗\033[0m  {$name} — {$result}\n";
}
/**
 * WordPress's slug to CSS-variable conversion (_wp_to_kebab_case): `5xl` → `5-xl`.
 */
function wpKebab(string $slug): string
{
    $slug = preg_replace('/([a-z])([A-Z])/', '$1-$2', $slug) ?? $slug;
    $slug = preg_replace('/([0-9])([a-zA-Z])/', '$1-$2', $slug) ?? $slug;
    $slug = preg_replace('/([a-zA-Z])([0-9])/', '$1-$2', $slug) ?? $slug;

    return strtolower($slug);
}

/**
 * @return array<string, list<string>>  preset kind => CSS-variable slugs WordPress will print
 */
function designSystemPresets(array $themeJson): array
{
    $css = (string) file_get_contents($GLOBALS['root'].'/resources/assets/css/app.css');
    $vite = (string) file_get_contents($GLOBALS['root'].'/vite.config.js');
    $presets = ['color' => [], 'font-size' => [], 'font-family' => [], 'border-radius' => [], 'spacing' => []];

    if (preg_match('/@theme static\s*\{(.*?)\n\}/s', $css, $block)) {
        preg_match_all('/--(color|text|radius|font)-([a-z0-9-]+?)\s*:/', $block[1], $tokens, PREG_SET_ORDER);

        foreach ($tokens as [, $family, $slug]) {
            if (str_contains($slug, '--')) {
                continue;
            }

            $kind = ['color' => 'color', 'text' => 'font-size', 'radius' => 'border-radius', 'font' => 'font-family'][$family];

            if ($kind === 'font-family' && str_contains($vite, 'disableTailwindFonts: true')) {
                continue;
            }

            $presets[$kind][] = wpKebab($slug);
        }
    }

    $settings = $themeJson['settings'] ?? [];
    foreach ([
        'color' => $settings['color']['palette'] ?? [],
        'font-size' => $settings['typography']['fontSizes'] ?? [],
        'font-family' => $settings['typography']['fontFamilies'] ?? [],
        'border-radius' => $settings['border']['radiusSizes'] ?? [],
        'spacing' => $settings['spacing']['spacingSizes'] ?? [],
    ] as $kind => $entries) {
        foreach ($entries as $entry) {
            $presets[$kind][] = wpKebab((string) ($entry['slug'] ?? ''));
        }
    }

    return $presets;
}

/**
 * @return list<string>  the --wp--custom-- variables settings.custom defines
 */
function designSystemCustomVariables(array $custom, string $prefix = ''): array
{
    $names = [];

    foreach ($custom as $key => $value) {
        $name = $prefix.'--'.wpKebab((string) $key);
        $names = [...$names, ...(is_array($value) ? designSystemCustomVariables($value, $name) : [$name])];
    }

    return $names;
}

function checkDesignSystem(): void
{

    $themeJson = json_decode((string) file_get_contents($GLOBALS['root'].'/theme.json'), true);

    check('theme.json parses', is_array($themeJson) ?: 'theme.json is not valid JSON');

    if (! is_array($themeJson)) {
        return;
    }

    $styles = json_encode($themeJson['styles'] ?? [], JSON_UNESCAPED_SLASHES);
    $presets = designSystemPresets($themeJson);

    check('Every preset the styles use is generated', (function () use ($styles, $presets) {
        preg_match_all('/var\(--wp--preset--(color|font-size|font-family|border-radius|spacing)--([a-z0-9-]+)\)/', $styles, $refs, PREG_SET_ORDER);
        $missing = [];

        foreach ($refs as [, $kind, $slug]) {
            if (! in_array($slug, $presets[$kind], true)) {
                $missing[] = "--wp--preset--{$kind}--{$slug}";
            }
        }

        // WordPress prints `5xl` as `5-xl`: a reference to the raw slug is silently ignored.
        return $missing === [] ? true : 'no such preset, the value is dropped: '.implode(', ', array_unique($missing));
    })());

    check('Every custom variable the styles use is defined', (function () use ($styles, $themeJson) {
        $defined = designSystemCustomVariables($themeJson['settings']['custom'] ?? []);
        preg_match_all('/var\(--wp--custom(--[a-z0-9-]+)\)/', $styles, $refs);
        $missing = array_diff(array_unique($refs[1]), $defined);

        return $missing === [] ? true : 'not in settings.custom: '.implode(', ', $missing);
    })());

    check('The styles use no Tailwind-only variable', (function () use ($styles) {
        // The editor loads theme.json, not app.css: var(--text-xl) is undefined there.
        preg_match_all('/var\(--(?!wp--)[a-z][a-z0-9-]*/', $styles, $refs);

        return $refs[0] === [] ? true : 'undefined in the editor: '.implode(', ', array_unique($refs[0]));
    })());

    check('No block css uses a selector list', (function () use ($themeJson) {
        $broken = [];

        foreach ($themeJson['styles']['blocks'] ?? [] as $block => $style) {
            foreach (explode('}', (string) ($style['css'] ?? '')) as $rule) {
                $selector = explode('{', $rule)[0];

                // WordPress wraps each selector in :root :where(…) and breaks on `a, b`.
                if (str_contains(preg_replace('/\([^)]*\)/', '', $selector) ?? $selector, ',')) {
                    $broken[] = $block.': '.trim($selector);
                }
            }
        }

        return $broken === [] ? true : 'one rule per selector: '.implode('; ', $broken);
    })());
}

echo "\n\033[1m=== Design system — theme.json styles point at what exists ===\033[0m\n";
checkDesignSystem();
echo $failures === 0 ? "\n\033[32mAll passed.\033[0m\n" : "\n\033[31m{$failures} failed.\033[0m\n";
exit($failures === 0 ? 0 : 1);
