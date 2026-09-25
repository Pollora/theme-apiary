#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prove the theme's blocks work where people meet them: over HTTP.
 *
 * Run from the Pollora project root, inside the container:
 *
 *   php theme-under-test/bin/ci/check-blocks.php --url=https://site.ddev.site \
 *     --static=apiary/ci-static --dynamic=apiary/ci-dynamic --dynamic-text="CI dynamic"
 *
 * This replaces a `wp eval-file` check, and the context is the whole point.
 * WP-CLI boots WordPress in an order no web request uses: until framework
 * v13.32.0-beta.7, blocks were registered under WP-CLI and nowhere else —
 * absent from the editor, the page and the REST API — and the old check
 * passed throughout. So every assertion here is made on an HTTP response:
 *
 *  - the REST API knows both blocks, and which one is dynamic;
 *  - the block editor enqueues the static block's script from the Vite build
 *    (that lookup broke silently once: a glob that matched no entry built
 *    nothing, and nothing failed);
 *  - a published post shows the dynamic block's server render. That is the
 *    check that found the post template printing get_the_content(), which
 *    never runs the the_content filter, so no dynamic block ever rendered.
 *
 * An administrator and a post are created for the run and deleted at the end.
 */

$options = getopt('', ['url:', 'static:', 'dynamic:', 'dynamic-text:']);

foreach (['url', 'static', 'dynamic', 'dynamic-text'] as $required) {
    if (! is_string($options[$required] ?? null) || $options[$required] === '') {
        fwrite(STDERR, "Usage: php check-blocks.php --url=… --static=ns/slug --dynamic=ns/slug --dynamic-text=…\n");
        exit(2);
    }
}

$baseUrl = rtrim($options['url'], '/');
$staticBlock = $options['static'];
$dynamicBlock = $options['dynamic'];
$dynamicText = $options['dynamic-text'];

$failures = 0;

function ok(string $message): void
{
    echo "  \033[32m✓\033[0m  {$message}\n";
}

function ko(string $message): void
{
    global $failures;
    $failures++;
    echo "  \033[31m✗\033[0m  {$message}\n";
}

/** @return array{code: int, out: string} */
function run(string $command): array
{
    exec($command.' 2>&1', $lines, $code);

    return ['code' => $code, 'out' => trim(implode("\n", $lines))];
}

function wp(string $arguments): string
{
    $result = run('wp '.$arguments);

    if ($result['code'] !== 0) {
        throw new RuntimeException("wp {$arguments} failed: {$result['out']}");
    }

    return $result['out'];
}

/**
 * One cookie jar for the run: the editor needs a session, and the REST API
 * accepts that session once it is given the nonce that goes with it.
 */
final class Browser
{
    private readonly string $jar;

    /** @var list<string> */
    public array $headers = [];

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'apiary-blocks-').'.cookies';
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param  array<string, string>  $post
     * @return array{status: int, body: string}
     */
    public function visit(string $url, array $post = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_USERAGENT => 'apiary-blocks-ci',
        ]);

        if ($post !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("{$url}: {$error}");
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}

echo "\n\033[1m── The theme's blocks, over HTTP ──\033[0m\n";

$adminUser = 'apiary-blocks-ci';
$adminPass = bin2hex(random_bytes(12));
$postId = null;
$browser = new Browser;

run('wp user delete '.escapeshellarg($adminUser).' --yes');

try {
    wp('user create '.escapeshellarg($adminUser).' apiary-blocks-ci@example.test'
        .' --role=administrator --user_pass='.escapeshellarg($adminPass).' --porcelain');

    $adminUrl = rtrim(wp("eval 'echo admin_url();'"), '/');

    $browser->visit(wp("eval 'echo wp_login_url();'"), [
        'log' => $adminUser,
        'pwd' => $adminPass,
        'wp-submit' => 'Log In',
        'testcookie' => '1',
    ]);

    // Handed out to a logged-in session only: an empty answer means the login
    // did not take, and every check below would fail for that reason alone.
    $nonce = trim($browser->visit($adminUrl.'/admin-ajax.php?action=rest-nonce')['body']);

    if (! preg_match('/^[a-f0-9]{10}$/', $nonce)) {
        throw new RuntimeException('no REST nonce for the CI account — the login did not establish a session');
    }

    $browser->headers = ['X-WP-Nonce: '.$nonce];
    $restUrl = wp("eval 'echo rest_url();'");

    // ── The REST API: the context the WP-CLI check could not see ─────────

    $editorHandles = [];

    foreach ([$staticBlock => false, $dynamicBlock => true] as $blockName => $isDynamic) {
        $response = $browser->visit($restUrl.'wp/v2/block-types/'.$blockName);
        $type = json_decode($response['body'], true);

        if ($response['status'] !== 200 || ! is_array($type)) {
            ko("{$blockName} is not known to the REST API (answered {$response['status']})");

            continue;
        }

        ok("{$blockName} is known to the REST API");

        if (($type['is_dynamic'] ?? null) === $isDynamic) {
            ok($isDynamic ? "{$blockName} is dynamic: the server renders it" : "{$blockName} is static: WordPress serves the markup save() stored");
        } else {
            ko($isDynamic ? "{$blockName} is not dynamic — its render.blade.php is not wired" : "{$blockName} was given a render callback, which would replace the markup save() stored");
        }

        if (($type['editor_script_handles'] ?? []) === []) {
            ko("{$blockName} has no editor script handle — the block has no JavaScript in the editor");
        } elseif (! $isDynamic) {
            $editorHandles = $type['editor_script_handles'];
        }
    }

    // ── The block editor: the script comes from the Vite build ───────────

    $editor = $browser->visit($adminUrl.'/post-new.php')['body'];

    foreach ($editorHandles as $handle) {
        if (! preg_match('/<script\b[^>]*\bid="'.preg_quote($handle, '/').'-js"[^>]*>/', $editor, $tag)) {
            ko("the block editor does not enqueue {$handle}");

            continue;
        }

        $src = preg_match('/\bsrc="([^"]*)"/', $tag[0], $m) === 1 ? html_entity_decode($m[1]) : '';

        str_contains($src, '/build/')
            ? ok("the block editor loads {$handle} from the build: {$src}")
            : ko("{$handle} does not point into the build: '{$src}' — the Vite manifest has no entry for it");
    }

    // ── A published post: the dynamic block renders on the page ──────────

    $postId = wp('post create --post_type=post --post_status=publish --porcelain'
        .' --post_title='.escapeshellarg('CI blocks '.bin2hex(random_bytes(4)))
        .' --post_content='.escapeshellarg('<!-- wp:'.$dynamicBlock.' /-->'));

    $page = $browser->visit(wp('eval '.escapeshellarg('echo get_permalink('.(int) $postId.');')));
    $wrapperClass = 'wp-block-'.str_replace('/', '-', $dynamicBlock);

    if ($page['status'] !== 200) {
        ko("the post answered {$page['status']}");
    } elseif (! str_contains($page['body'], $wrapperClass)) {
        ko("the post does not show {$dynamicBlock}: no .{$wrapperClass} on the page");
    } elseif (! str_contains($page['body'], $dynamicText)) {
        ko("the post shows .{$wrapperClass} without its render: '{$dynamicText}' is missing");
    } else {
        ok("a published post shows {$dynamicBlock}'s server render");
    }
} catch (Throwable $e) {
    ko($e->getMessage());
} finally {
    if ($postId !== null) {
        run('wp post delete '.escapeshellarg($postId).' --force');
    }

    run('wp user delete '.escapeshellarg($adminUser).' --yes');
}

if ($failures > 0) {
    printf("\n\033[31m%d check%s failed.\033[0m\n\n", $failures, $failures === 1 ? '' : 's');
    exit(1);
}

echo "\n\033[32mThe theme's blocks register, load and render over HTTP.\033[0m\n\n";
