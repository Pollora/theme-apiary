import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import { homeUrl, runId, wp } from '../../support/site';

/**
 * The shop as a customer walks it, in a browser: product page, variations, cart,
 * checkout (never submitted: an order sends mail and leaves records), search.
 */

type Product = { id: number; name: string; permalink: string };

const simpleName = `E2E simple ${runId}`;
const variableName = `E2E variable ${runId}`;
const products: number[] = [];

// The cart and checkout slugs follow the site's language.
const cartUrl = wp('eval', 'echo wc_get_page_permalink("cart");');
const checkoutUrl = wp('eval', 'echo wc_get_page_permalink("checkout");');

// Classic templates or the Cart and Checkout blocks, whichever the pages hold; the blocks
// render their contents after load.
const cart = '.woocommerce-cart-form, .wp-block-woocommerce-cart';
const checkout = '#order_review, .wp-block-woocommerce-checkout';

let simple: Product;
let variable: Product;

/** Wait until the cart holds a line: the add-to-cart may be a form post or Ajax. */
async function expectCartLines(page: Page, count: number): Promise<void> {
    await expect
        .poll(async () => ((await (await page.request.get(homeUrl('/wp-json/wc/store/v1/cart'))).json()).items ?? []).length, {
            message: 'lines in the cart (Store API)',
        })
        .toBe(count);
}

function watchErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    return errors;
}

test.beforeAll(async ({ requestUtils }) => {
    simple = await requestUtils.rest({
        method: 'POST',
        path: '/wc/v3/products',
        data: { name: simpleName, type: 'simple', regular_price: '12.00', status: 'publish' },
    });
    products.push(simple.id);

    variable = await requestUtils.rest({
        method: 'POST',
        path: '/wc/v3/products',
        data: {
            name: variableName,
            type: 'variable',
            status: 'publish',
            attributes: [{ name: 'Size', options: ['Small', 'Large'], variation: true, visible: true }],
        },
    });
    products.push(variable.id);

    for (const [option, price] of [['Small', '10.00'], ['Large', '14.00']]) {
        await requestUtils.rest({
            method: 'POST',
            path: `/wc/v3/products/${variable.id}/variations`,
            data: { regular_price: price, attributes: [{ name: 'Size', option }] },
        });
    }
});

test.afterAll(async ({ requestUtils }) => {
    for (const id of products) {
        await requestUtils.rest({ method: 'DELETE', path: `/wc/v3/products/${id}`, params: { force: true } });
    }
});

// A fresh visitor per test: the cart lives in the session cookie.
test.use({ storageState: { cookies: [], origins: [] } });

test('a simple product goes from its page to the cart and the checkout', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto(simple.permalink);
    await expect(page.locator('h1.product_title')).toHaveText(simpleName);
    await page.locator('form.cart .single_add_to_cart_button').click();
    await expectCartLines(page, 1);

    // Scoped to the cart itself: an empty cart lists new products, this one among them.
    await page.goto(cartUrl);
    await expect(page.locator(cart).first()).toContainText(simpleName, { timeout: 15_000 });

    await page.goto(checkoutUrl);
    await expect(page.locator(checkout).first()).toContainText(simpleName, { timeout: 15_000 });
    await expect(page.locator('#billing_first_name, .wc-block-checkout').first()).toBeVisible();

    expect(errors, 'no uncaught page error').toEqual([]);
});

test('a variation is chosen, priced and carried to the cart', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto(variable.permalink);
    const addToCart = page.locator('form.variations_form .single_add_to_cart_button');

    // WooCommerce keeps the button disabled until a variation is chosen.
    await expect(addToCart).toHaveClass(/disabled/);
    await page.locator('form.variations_form select[name="attribute_size"]').selectOption('Large');
    await expect(page.locator('.woocommerce-variation-price')).toContainText('14');
    await expect(addToCart).not.toHaveClass(/disabled/);

    await addToCart.click();
    await expectCartLines(page, 1);

    await page.goto(cartUrl);
    await expect(page.locator(cart).first()).toContainText(variableName, { timeout: 15_000 });
    await expect(page.locator(cart).first()).toContainText('Large');

    expect(errors, 'no uncaught page error').toEqual([]);
});

test('a product search finds the product', async ({ page }) => {
    const errors = watchErrors(page);

    // A search with a single product result goes straight to that product (WooCommerce's
    // woocommerce_redirect_single_search_result), which is what this name gives.
    await page.goto(homeUrl(`/?s=${encodeURIComponent(simpleName)}&post_type=product`));
    await expect(page).toHaveURL(simple.permalink);
    await expect(page.locator('h1.product_title')).toHaveText(simpleName);

    expect(errors, 'no uncaught page error').toEqual([]);
});
