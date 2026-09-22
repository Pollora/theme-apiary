<?php

declare(strict_types=1);

/**
 * Give the sweep a shop to walk.
 *
 * Run inside WordPress, from the Pollora project root, in the container:
 *
 *   wp eval-file theme-under-test/bin/ci/seed-shop.php
 *
 * bin/tests/sweep.php asks WooCommerce for a published simple product and a
 * non-empty product category, and reports a missing fixture as a failure
 * rather than a skip — deliberately, since every one of its checks would pass
 * on an empty site. A fresh WooCommerce has neither, so CI has to create them.
 *
 * Idempotent: re-running it finds what it made last time.
 */

if (! class_exists(\WooCommerce::class)) {
    fwrite(STDERR, "WooCommerce is not loaded — activate it before seeding.\n");
    exit(1);
}

// Activation schedules the page creation rather than doing it inline, so on a
// site nobody has opened in a browser the shop, cart and checkout pages can
// still be missing. The sweep asks for all three by permalink.
if (wc_get_page_id('shop') <= 0 || wc_get_page_id('cart') <= 0 || wc_get_page_id('checkout') <= 0) {
    \WC_Install::create_pages();
    echo "  → WooCommerce pages created\n";
}

$categorySlug = 'ci-category';
$term = get_term_by('slug', $categorySlug, 'product_cat');

if (! $term instanceof WP_Term) {
    $created = wp_insert_term('CI Category', 'product_cat', ['slug' => $categorySlug]);

    if (is_wp_error($created)) {
        fwrite(STDERR, 'Could not create the product category: '.$created->get_error_message()."\n");
        exit(1);
    }

    $termId = (int) $created['term_id'];
    echo "  → product category {$categorySlug} created\n";
} else {
    $termId = (int) $term->term_id;
}

$productSlug = 'ci-test-product';
$existing = get_page_by_path($productSlug, OBJECT, 'product');

if ($existing instanceof WP_Post) {
    echo "  → product {$productSlug} already there (#{$existing->ID})\n";
    exit(0);
}

$product = new WC_Product_Simple;
$product->set_name('CI Test Product');
$product->set_slug($productSlug);
$product->set_status('publish');
$product->set_catalog_visibility('visible');
$product->set_regular_price('12.00');
$product->set_stock_status('instock');
$product->set_description('A product the sweep can put in a cart.');
$product->set_short_description('Seeded by CI.');
$product->set_category_ids([$termId]);

$id = $product->save();

if ($id <= 0) {
    fwrite(STDERR, "Could not create the product.\n");
    exit(1);
}

// The category has to be non-empty for `wp term list --hide_empty=1` to
// return it, and the count is only recomputed when something asks.
wp_update_term_count_now([$termId], 'product_cat');

echo "  → product {$productSlug} created (#{$id}), in {$categorySlug}\n";
