import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import { homeUrl, runId } from '../../support/site';

/**
 * What a visitor sees before Alpine starts. With the theme's scripts held back, as they
 * are for a moment on every load, nothing Alpine hides with x-show may be on screen:
 * every such element carries x-cloak, which the theme's stylesheet hides until Alpine
 * removes it. Without that, the cart drawer, the mobile menu, the add-to-cart modal and
 * the product accordion flashed on each page load.
 */

type Product = { id: number; permalink: string };

let product: Product;

test.beforeAll(async ({ requestUtils }) => {
    product = await requestUtils.rest({
        method: 'POST',
        path: '/wc/v3/products',
        data: { name: `E2E cloak ${runId}`, type: 'simple', regular_price: '5.00', status: 'publish', description: 'Described.' },
    });
});

test.afterAll(async ({ requestUtils }) => {
    await requestUtils.rest({ method: 'DELETE', path: `/wc/v3/products/${product.id}`, params: { force: true } });
});

test.use({ storageState: { cookies: [], origins: [] } });

async function visibleBeforeAlpine(page: Page, url: string): Promise<string[]> {
    // The theme's built scripts, Alpine among them; its stylesheet still loads.
    await page.route(/\/build\/theme\/[^/]+\/assets\/.+\.js(\?.*)?$/, (route) => route.abort());
    await page.goto(url);

    return page.evaluate(() => [...document.querySelectorAll('[x-show]')]
        .filter((element) => element.checkVisibility())
        .map((element) => `<${element.tagName.toLowerCase()} x-show="${element.getAttribute('x-show')}">`));
}

test('the home page shows nothing Alpine hides, before Alpine starts', async ({ page }) => {
    expect(await visibleBeforeAlpine(page, homeUrl('/'))).toEqual([]);
});

test('a product page shows nothing Alpine hides, before Alpine starts', async ({ page }) => {
    expect(await visibleBeforeAlpine(page, product.permalink)).toEqual([]);
});
