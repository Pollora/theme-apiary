<?php

declare(strict_types=1);

/**
 * The placeholders the scaffolder substitutes when it generates a theme.
 *
 * Mirrors MakeThemeCommand::getReplacements() in the framework. This theme is
 * a template: `style.css` says `Theme Name: %theme_name%`, and a user receives
 * it only after `pollora:make:theme` has rewritten those tokens.
 *
 * bin/ci/install.php needs the same map, because it overlays the commit under
 * test onto a theme the scaffolder generated from the published tag — without
 * the substitution, CI would install a theme whose header is a placeholder.
 *
 * @return array<string, string>  placeholder => a plausible substituted value
 */
function scaffolderReplacements(string $slug = 'apiary'): array
{
    $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));

    return [
        '%theme_name%' => $slug,
        '%theme_camel%' => lcfirst($studly),
        '%theme_namespace%' => 'Theme\\'.$studly,
        '%theme_author%' => 'Pollora',
        '%theme_author_uri%' => 'https://pollora.dev',
        '%theme_uri%' => 'https://pollora.dev',
        '%theme_description%' => 'Theme under test',
        '%theme_version%' => '1.0.0',
    ];
}
