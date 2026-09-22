#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Walk an Apiary site the way a person does, and check what comes back.
 *
 * Run from the Pollora project root, inside the container:
 *
 *   php themes/apiary/bin/tests/sweep.php [--url=https://site.ddev.site]
 *
 * Why it exists: the theme was swept once by hand — home, cart, checkout,
 * products, shop, search, 404, category, author, archives — and that sweep was
 * anonymous and read-only. No cart with anything in it, no checkout funnel, no
 * wp-admin, no block editor. Those are exactly the places where the v13.32
 * theme fixes could have broken something, and none of them was looked at.
 *
 * So this does four things the old sweep did not:
 *
 *  - fills a cart and asks the cart and checkout pages to render it;
 *  - logs in and walks wp-admin, including the block editor;
 *  - checks the two theme fixes on this theme rather than on the starter —
 *    the theme root WordPress resolves, and Blade views winning over the PHP
 *    files sitting next to them at the theme root;
 *  - fails on an empty 200. A page that answers with nothing is the failure
 *    mode this whole family of bugs had, and it looks like success to curl.
 *
 * The admin account is created for the run and deleted at the end. Nothing
 * else is written: no order is placed, because that sends mail and leaves
 * records behind. The funnel is walked as far as the page that would submit.
 */

$options = getopt('', ['url::', 'keep-user']);

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function section(string $title): void
{
    echo "\n\033[1m── {$title} ──\033[0m\n";
}

/** @param callable():(true|string) $fn */
function test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $result = $fn();
    } catch (\Throwable $e) {
        echo "  \033[31m✗\033[0m  {$name} — {$e->getMessage()}\n";
        $failed++;

        return;
    }

    if ($result === true) {
        echo "  \033[32m✓\033[0m  {$name}\n";
        $passed++;

        return;
    }

    $reason = is_string($result) && $result !== '' ? " — {$result}" : '';
    echo "  \033[31m✗\033[0m  {$name}{$reason}\n";
    $failed++;
}

/** @return array{code: int, out: string} */
function run(string $command): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command.' 2>&1', $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new \RuntimeException("could not run: {$command}");
    }

    $out = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => trim($out)];
}

function wpEval(string $php): string
{
    $result = run('wp eval '.escapeshellarg($php));

    if ($result['code'] !== 0) {
        throw new \RuntimeException("wp eval failed: {$result['out']}");
    }

    return $result['out'];
}

/**
 * One browser, one cookie jar, for the whole run.
 *
 * The cart lives in a session cookie and the admin in an auth cookie; a
 * stateless request cannot see either, which is why the previous sweep could
 * only ever look at anonymous pages.
 */
final class Browser
{
    private readonly string $jar;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'apiary-sweep-').'.cookies';
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param  array<string, string>  $post
     * @return array{status: int, body: string, url: string}
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
            CURLOPT_USERAGENT => 'apiary-sweep',
        ]);

        if ($post !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new \RuntimeException("{$url}: {$error}");
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'url' => $final];
    }
}

/**
 * The checks every page owes, whatever it is.
 *
 * An empty 200 counts as a failure here. That is the shape every bug in this
 * family took — a template that resolved to nothing — and it is indentical to
 * success for anything that only reads the status line.
 *
 * @param  array{status: int, body: string, url: string}  $response
 */
function pageIsSound(array $response, int $expected = 200): bool|string
{
    if ($response['status'] !== $expected) {
        return "answered {$response['status']} (expected {$expected})";
    }

    $bytes = strlen(trim($response['body']));

    if ($bytes < 500) {
        return "answered {$expected} with {$bytes} bytes";
    }

    if (! str_contains($response['body'], '</html>')) {
        return "answered {$bytes} bytes that are not a document";
    }

    // A Blade directive in the output means the file was served instead of
    // rendered — which is what the install root did before fix 3's sibling.
    foreach (['@extends', '@section(', '@endsection', '@php'] as $directive) {
        if (str_contains($response['body'], $directive)) {
            return "served Blade source: found {$directive}";
        }
    }

    foreach (['Fatal error', 'Parse error', 'Uncaught ', 'Whoops', 'ViewException'] as $leak) {
        if (str_contains($response['body'], $leak)) {
            return "leaked {$leak}";
        }
    }

    foreach (['Warning:', 'Notice:', 'Deprecated:'] as $noise) {
        if (str_contains($response['body'], '<b>'.$noise) || str_contains($response['body'], '<br />'.PHP_EOL.$noise)) {
            return "leaked a PHP {$noise} into the page";
        }
    }

    return true;
}

/** Which Blade template answered, if the theme says. */
function templateOf(string $body): ?string
{
    return preg_match('/data-pollora-template="([^"]+)"/', $body, $m) === 1 ? $m[1] : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Setup
// ─────────────────────────────────────────────────────────────────────────────

$baseUrl = rtrim((string) ($options['url'] ?? wpEval('echo home_url();')), '/');

$host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
$disposable = str_ends_with($host, '.ddev.site')
    || str_ends_with($host, '.test')
    || str_ends_with($host, '.localhost')
    || in_array($host, ['localhost', '127.0.0.1'], true);

// The run creates an administrator and fills a cart. Neither belongs on
// anything but a development site.
if (! $disposable) {
    fwrite(STDERR, "\n\033[31mRefusing to run against {$host}.\033[0m\nThis sweep logs in and fills a cart; it only runs against a local host.\n\n");
    exit(2);
}

echo "\n\033[1m=== theme-apiary — authenticated sweep ===\033[0m\n";
echo "\033[2m{$baseUrl}\033[0m\n";

$stylesheet = wpEval('echo get_stylesheet();');

if ($stylesheet !== 'apiary') {
    fwrite(STDERR, "\n\033[31mThe active theme is {$stylesheet}, not apiary.\033[0m\n\n");
    exit(2);
}

$browser = new Browser;

// ─────────────────────────────────────────────────────────────────────────────

section('Theme resolution — fixes 3 and 4, on this theme rather than the starter');

test('wp_get_theme() finds apiary', function () {
    return wpEval('echo wp_get_theme()->exists() ? "yes" : "no";') === 'yes'
        ? true
        : 'wp_get_theme() cannot find it — the admin will report the theme missing';
});

test('The theme root and get_stylesheet_directory() agree', function () {
    $fromTheme = wpEval('echo wp_get_theme()->get_stylesheet_directory();');
    $fromHelper = wpEval('echo get_stylesheet_directory();');

    return $fromTheme === $fromHelper
        ? true
        : "wp_get_theme() says {$fromTheme}, get_stylesheet_directory() says {$fromHelper}";
});

test('apiary is listed by wp_get_themes()', function () use ($stylesheet) {
    $listed = explode(',', wpEval('echo implode(",", array_keys(wp_get_themes()));'));

    return in_array($stylesheet, $listed, true)
        ? true
        : "apiary is absent from the themes list (".implode(', ', $listed).")";
});

// Fix 3 inverted the registration order so a theme's Blade views win over the
// PHP files sitting beside them. apiary ships index.php at its root — the stub
// WordPress requires themes to have — and a resources/views tree. If the PHP
// file wins, every page renders whatever index.php contains.
test('Blade views win over the PHP files at the theme root', function () use ($browser, $baseUrl) {
    $root = wpEval('echo get_stylesheet_directory();');

    if (! is_file($root.'/index.php')) {
        return 'apiary no longer ships index.php, so this check proves nothing';
    }

    $response = $browser->visit($baseUrl.'/');
    $sound = pageIsSound($response);

    if ($sound !== true) {
        return "the homepage {$sound}";
    }

    // index.php is WordPress's required stub — "Silence is golden" and
    // nothing else. If the PHP file won, that is what the page would be.
    // The template marker is deliberately not the signal here: apiary's front
    // page does not emit one, while its search results do.
    if (str_contains($response['body'], 'Silence is golden')) {
        return 'the index.php stub rendered — the PHP file won over the Blade view';
    }

    return strlen($response['body']) > 5000
        ? true
        : 'the homepage is '.strlen($response['body']).' bytes, which is not a rendered theme';
});

// ─────────────────────────────────────────────────────────────────────────────

section('Anonymous — the pages the previous sweep covered, with empty 200s failing');

$productSlug = wpEval('$p = wc_get_products(["limit" => 1, "status" => "publish", "type" => "simple"]); echo $p ? $p[0]->get_slug() : "";');
$categorySlug = (function (): string {
    $terms = run('wp term list product_cat --hide_empty=1 --field=slug --number=1');

    return $terms['code'] === 0 ? trim(explode("\n", $terms['out'])[0] ?? '') : '';
})();
$postSlug = wpEval('$p = get_posts(["numberposts" => 1]); echo $p ? $p[0]->post_name : "";');

$anonymous = [
    'Homepage' => ['/', 200],
    'Shop' => [wpEval('echo wc_get_page_permalink("shop");'), 200],
    'Single product' => [$productSlug === '' ? '' : '/product/'.$productSlug.'/', 200],
    'Product category' => [$categorySlug === '' ? '' : '/product-category/'.$categorySlug.'/', 200],
    'Empty cart' => [wpEval('echo wc_get_page_permalink("cart");'), 200],
    'Single post' => [$postSlug === '' ? '' : '/'.$postSlug.'/', 200],
    'Blog archive' => ['/category/uncategorized/', 200],
    'Search results' => ['/?s=shirt', 200],
    'Unknown URL' => ['/no-such-page-'.bin2hex(random_bytes(4)).'/', 404],
];

foreach ($anonymous as $label => [$path, $expected]) {
    test("{$label} renders", function () use ($browser, $baseUrl, $path, $expected, $label) {
        if ($path === '') {
            return "no URL for {$label} — the fixture it needs is missing, so this check would pass on an empty site";
        }

        $url = str_starts_with($path, 'http') ? $path : $baseUrl.$path;

        return pageIsSound($browser->visit($url), $expected);
    });
}

// ─────────────────────────────────────────────────────────────────────────────

section('Cart and checkout — with something actually in the cart');

$productId = (int) wpEval('$p = wc_get_products(["limit" => 1, "status" => "publish", "type" => "simple"]); echo $p ? $p[0]->get_id() : 0;');
$productName = $productId === 0 ? '' : wpEval('echo wc_get_product('.$productId.')->get_name();');
$cartUrl = wpEval('echo wc_get_page_permalink("cart");');
$checkoutUrl = wpEval('echo wc_get_page_permalink("checkout");');

test('A product can be added to the cart', function () use ($browser, $baseUrl, $productId) {
    if ($productId === 0) {
        return 'no purchasable simple product on the site';
    }

    $response = $browser->visit($baseUrl.'/?add-to-cart='.$productId);

    return pageIsSound($response);
});

test('The cart page shows the product', function () use ($browser, $cartUrl, $productName) {
    if ($productName === '') {
        return 'no product to look for';
    }

    $response = $browser->visit($cartUrl);
    $sound = pageIsSound($response);

    if ($sound !== true) {
        return "the cart {$sound}";
    }

    return str_contains($response['body'], $productName)
        ? true
        : "the cart rendered without \"{$productName}\" in it — the item was not added, or the template does not list it";
});

test('The cart page is not the empty-cart page', function () use ($browser, $cartUrl) {
    $body = $browser->visit($cartUrl)['body'];

    // WooCommerce swaps the whole template when the cart is empty, so a theme
    // can look fine here while its filled-cart template is broken.
    return str_contains($body, 'cart-empty') || str_contains($body, 'wc-empty-cart-message')
        ? 'the cart still renders its empty state, so the filled template was never exercised'
        : true;
});

test('The checkout page renders the order review', function () use ($browser, $checkoutUrl, $productName) {
    $response = $browser->visit($checkoutUrl);
    $sound = pageIsSound($response);

    if ($sound !== true) {
        return "the checkout {$sound}";
    }

    // Two checkouts exist and they put the order review in different places.
    // The block checkout ships an empty container and fills it from the Store
    // API, so looking for the product name in the HTML would fail on a page
    // that is perfectly fine — and pass on a classic checkout that is not.
    $isBlockCheckout = str_contains($response['body'], 'wp-block-woocommerce-checkout');

    if ($isBlockCheckout) {
        return str_contains($response['body'], 'data-block-name="woocommerce/checkout-order-summary-block"')
            || str_contains($response['body'], 'wc-block-checkout')
            ? true
            : 'the block checkout rendered without its order summary block';
    }

    if ($productName !== '' && ! str_contains($response['body'], $productName)) {
        return "the classic checkout rendered without \"{$productName}\" in the order review";
    }

    return true;
});

// The block checkout's contents come from the Store API, so that is where the
// filled cart has to be visible. Without this the funnel is only checked as
// far as an empty container.
test('The Store API sees the filled cart', function () use ($browser, $baseUrl, $productName) {
    $response = $browser->visit($baseUrl.'/cms/?rest_route=/wc/store/v1/cart');

    if ($response['status'] !== 200) {
        return "the Store API answered {$response['status']}";
    }

    $cart = json_decode($response['body'], true);

    if (! is_array($cart) || ! isset($cart['items'])) {
        return 'the Store API did not answer with a cart';
    }

    if ($cart['items'] === []) {
        return 'the Store API reports an empty cart — the block checkout would render nothing to buy';
    }

    if ($productName === '') {
        return true;
    }

    foreach ($cart['items'] as $item) {
        if (($item['name'] ?? '') === $productName) {
            return true;
        }
    }

    return "the cart holds items but not \"{$productName}\"";
});

test('The checkout carries a form that could be submitted', function () use ($browser, $checkoutUrl) {
    $body = $browser->visit($checkoutUrl)['body'];

    foreach (['billing_first_name', 'wc-block-checkout', 'woocommerce-checkout'] as $marker) {
        if (str_contains($body, $marker)) {
            return true;
        }
    }

    return 'no checkout form, classic or block, reached the page';
});

// ─────────────────────────────────────────────────────────────────────────────

section('Login screen — the one page of the site nobody had ever looked at');

// Anonymous on purpose, and before the sweep logs in: this is the screen a
// visitor meets, and a logged-in request is redirected away from it.
$loginUrl = trim(wpEval('echo wp_login_url();'));
$loginPage = (new Browser)->visit($loginUrl);

test('It answers 200 with a form', function () use ($loginPage, $loginUrl) {
    if ($loginPage['status'] !== 200) {
        // It answered 404 with a perfectly good form until 13.32.0-beta.4:
        // the URL was resolved as a content request and matched the
        // attachment rewrite rule.
        return "{$loginUrl} answered {$loginPage['status']}";
    }

    return str_contains($loginPage['body'], 'name="loginform"')
        ? true
        : 'it answered 200 without a login form';
});

test("The theme's config/login.php reaches it", fn () => str_contains($loginPage['body'], 'pollora-login')
    ? true
    : 'the theme ships a config/login.php and none of it reached the screen');

test("It wears this theme's colours, resolved through their var() fallbacks", function () use ($loginPage) {
    if (preg_match('/--pollora-login-primary:\s*([^;]+);/', $loginPage['body'], $m) !== 1) {
        return 'no --pollora-login-primary was printed';
    }

    $primary = trim($m[1]);

    // This theme.json declares `primary` as
    // var(--wp--preset--color--primary,#1f2937) — a reference to itself, which
    // CSS discards as a cycle. #1f2937 is the only colour actually in the
    // data, and the login screen carries none of WordPress's preset variables
    // anyway, so that is what has to come out.
    return $primary === '#1f2937'
        ? true
        : "primary resolved to {$primary}; the var() fallback in theme.json is #1f2937";
});

test('The logo is inlined, not linked to a URL that does not exist', function () use ($loginPage) {
    // A file inside the theme has no URL here: only the Vite build output is
    // web-served, and get_theme_file_uri() answers an empty string.
    return str_contains($loginPage['body'], '--pollora-login-logo: url("data:image/svg+xml;base64,')
        ? true
        : 'no inlined logo reached the screen';
});

test('It sends people to this site, not to wordpress.org', fn () => str_contains($loginPage['body'], 'wordpress.org')
    ? 'the logo still points at wordpress.org'
    : true);

test('The lost-password screen is dressed too', function () use ($loginUrl) {
    $body = (new Browser)->visit($loginUrl.'?action=lostpassword')['body'];

    return str_contains($body, 'pollora-login') && str_contains($body, '<style id="pollora-login">')
        ? true
        : 'the screens that share login_head are not all covered';
});

// ─────────────────────────────────────────────────────────────────────────────

section('Back office — logged in, including the block editor');

$adminUser = 'apiary-sweep';
$adminPass = bin2hex(random_bytes(12));

$existing = run('wp user get '.escapeshellarg($adminUser).' --field=ID');

if ($existing['code'] === 0 && $existing['out'] !== '') {
    run('wp user delete '.escapeshellarg($adminUser).' --yes');
}

$created = run('wp user create '.escapeshellarg($adminUser).' apiary-sweep@example.test'
    .' --role=administrator --user_pass='.escapeshellarg($adminPass).' --porcelain');

$loginUrl = wpEval('echo wp_login_url();');
$adminUrl = rtrim(wpEval('echo admin_url();'), '/');

try {
    test('The sweep account can log in', function () use ($browser, $loginUrl, $adminUser, $adminPass, $created) {
        if ($created['code'] !== 0) {
            return "could not create the sweep account: {$created['out']}";
        }

        $adminUrl = rtrim(wpEval('echo admin_url();'), '/');

        $browser->visit($loginUrl, [
            'log' => $adminUser,
            'pwd' => $adminPass,
            'wp-submit' => 'Log In',
            'redirect_to' => $adminUrl.'/profile.php',
            'testcookie' => '1',
        ]);

        // What the login page says about itself proves nothing: wp-login.php
        // renders a perfectly ordinary document whether the attempt worked or
        // not, and a different one again for a visitor who is already signed
        // in. The only answer that means anything is whether a screen that
        // requires a session hands one over.
        $profile = $browser->visit($adminUrl.'/profile.php');

        if (str_contains($profile['url'], 'wp-login')) {
            return 'wp-admin bounced back to the login form — no session was established';
        }

        return str_contains($profile['body'], $adminUser)
            ? true
            : "the profile screen loaded but does not name {$adminUser}";
    });

    $screens = [
        'Dashboard' => '/',
        'Themes' => '/themes.php',
        'Plugins' => '/plugins.php',
        'Posts list' => '/edit.php',
        'Pages list' => '/edit.php?post_type=page',
        'Products list' => '/edit.php?post_type=product',
        'WooCommerce orders' => '/edit.php?post_type=shop_order',
        'WooCommerce settings' => '/admin.php?page=wc-settings',
        'Block editor (new post)' => '/post-new.php',
        'Block editor (new page)' => '/post-new.php?post_type=page',
        'Widgets' => '/widgets.php',
        'Menus' => '/nav-menus.php',
    ];

    foreach ($screens as $label => $path) {
        test("wp-admin: {$label}", function () use ($browser, $adminUrl, $path, $label) {
            $response = $browser->visit($adminUrl.$path);

            if ($response['status'] === 404) {
                return "answered 404 — the screen is not there on this install";
            }

            // wp-admin bounces to the login form when the session is gone,
            // and that page is perfectly well-formed.
            if (str_contains($response['body'], 'name="pwd"') && str_contains($response['url'], 'wp-login')) {
                return 'bounced to the login form — the session was lost';
            }

            $sound = pageIsSound($response);

            if ($sound !== true) {
                return $sound;
            }

            return str_contains($response['body'], 'wp-admin')
                ? true
                : "the response is a document but does not look like wp-admin";
        });
    }

    test('The block editor loads its editor bundle', function () use ($browser, $adminUrl) {
        $body = $browser->visit($adminUrl.'/post-new.php')['body'];

        // Only the bootstrap can be seen from here. The editor's error
        // boundary — "the editor has encountered an unexpected error" — ships
        // inside the bundle as a translatable string, so its presence in the
        // HTML means nothing at all; an editor that breaks at run time does so
        // in JavaScript, where this has no eyes. That gap needs a browser.
        foreach (['wp-block-editor', 'block-editor-writing-flow', 'wp-edit-post'] as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return 'no block editor asset reached the page';
    });

    test("The theme's editor styles reach the editor", function () use ($browser, $adminUrl) {
        $body = $browser->visit($adminUrl.'/post-new.php')['body'];

        return str_contains($body, 'wp--preset--color') || str_contains($body, 'editor-styles-wrapper') || str_contains($body, 'apiary')
            ? true
            : "nothing from the theme reached the editor — theme.json and add_editor_style are both silent there";
    });
} finally {
    if (! isset($options['keep-user'])) {
        run('wp user delete '.escapeshellarg($adminUser).' --yes');
        echo "  \033[2m→ sweep account removed\033[0m\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────

$total = $passed + $failed;
echo "\n";
echo $failed === 0
    ? "\033[32m{$total} checks, all passed.\033[0m\n\n"
    : "\033[31m{$total} checks, {$failed} failed.\033[0m\n\n";

exit($failed === 0 ? 0 : 1);
