<?php

declare(strict_types=1);

namespace %theme_namespace%\Providers;

use Illuminate\Support\ServiceProvider;
use Pollora\Support\Facades\Action;
use Pollora\Support\Facades\Asset;
use Pollora\Support\Facades\Filter;

/**
 * Registers theme assets and injects runtime configuration into the page.
 *
 * Handles three concerns:
 * - Enqueues the Vite-built JS/CSS bundle on the frontend.
 * - Outputs `window.%theme_camel%Cart` (cart URLs, i18n, add-to-cart modal data)
 *   and `window.%theme_camel%Search` (search suggestion endpoint and settings)
 *   as inline `<script>` blocks in `wp_footer`.
 * - Dequeues WooCommerce's default stylesheets so the theme controls all styling.
 *
 * @see config('theme.woocommerce.single-product.add_to_cart_confirmation') Modal toggle
 * @see config('theme.woocommerce.search') Search suggestions settings
 */
class AssetServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Asset::add('%theme_name%/script', 'app.js')
            ->container('theme')
            ->toFrontend()
            ->useVite();

        // Localize runtime data via wp_footer (after all scripts are registered)
        Action::add('wp_footer', function (): void {
            $confirmationEnabled = (bool) config('theme.woocommerce.single-product.add_to_cart_confirmation.enabled', false);

            $data = [
                'addToCartConfirmation' => $confirmationEnabled,
                'cartUrl'     => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
                'checkoutUrl' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
                'i18n' => [
                    'addedToCart'      => __('%s added to cart.', '%theme_name%'),
                    'productAdded'     => __('Product added to cart.', '%theme_name%'),
                    'dismiss'          => __('Dismiss', '%theme_name%'),
                    'addedToCartTitle' => __('Added to cart', '%theme_name%'),
                    'viewCart'         => __('View cart', '%theme_name%'),
                    'continueShopping' => __('Continue shopping', '%theme_name%'),
                    'checkout'         => __('Checkout', '%theme_name%'),
                    'recentlyViewed'   => __('Recently viewed', '%theme_name%'),
                    'youMayAlsoLike'   => __('You may also like', '%theme_name%'),
                ],
            ];

            if ($confirmationEnabled && function_exists('is_product') && is_product()) {
                $product = wc_get_product(get_the_ID());
                if ($product) {
                    $data['currentProduct'] = [
                        'id'           => $product->get_id(),
                        'name'         => $product->get_name(),
                        'price'        => $product->get_price_html(),
                        'image'        => wp_get_attachment_url($product->get_image_id()) ?: wc_placeholder_img_src(),
                        'description'  => $product->get_short_description(),
                        'url'          => get_permalink(),
                        'crossSellIds' => $product->get_cross_sell_ids(),
                        'upsellIds'    => $product->get_upsell_ids(),
                    ];
                }
            }

            echo '<script>window.%theme_camel%Cart = ' . wp_json_encode($data) . ';</script>' . "\n";

            // Search suggestions config
            $searchConfig = config('theme.woocommerce.search', []);
            if (! empty($searchConfig['suggestions'])) {
                $searchData = [
                    'apiUrl'     => home_url('/api/products/search'),
                    'minChars'   => (int) ($searchConfig['min_chars'] ?? 3),
                    'debounce'   => (int) ($searchConfig['debounce'] ?? 300),
                    'maxResults' => (int) ($searchConfig['max_results'] ?? 6),
                ];
                echo '<script>window.%theme_camel%Search = ' . wp_json_encode($searchData) . ';</script>' . "\n";
            }
        }, 1);

        // Remove default WooCommerce styles
        Filter::add('woocommerce_enqueue_styles', function (array $enqueueStyles) {
            unset($enqueueStyles['woocommerce-general'], $enqueueStyles['woocommerce-layout'], $enqueueStyles['woocommerce-smallscreen']);
            return $enqueueStyles;
        });
    }
}
