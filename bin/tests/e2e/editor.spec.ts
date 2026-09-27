import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import { runId } from '../../support/site';

/**
 * The block editor, used rather than merely loaded: the theme's styles reach its canvas,
 * and a dynamic block made in the theme by pollora:make:block renders on the post.
 */

async function canvasColor(admin: { createNewPost: () => Promise<void> }, editor: { canvas: import('@playwright/test').FrameLocator }, slug: string): Promise<string> {
    await admin.createNewPost();

    return editor.canvas.locator('body').evaluate(
        (body, name) => getComputedStyle(body).getPropertyValue(`--wp--preset--color--${name}`).trim(),
        slug,
    );
}

test("the theme's palette reaches the editor canvas, and Tailwind's scale does not", async ({ admin, editor }) => {
    expect(await canvasColor(admin, editor, 'surface'), 'a colour of the theme').not.toBe('');
    // The palette once held Tailwind's whole default scale, some 290 colours, offered to authors.
    expect(await canvasColor(admin, editor, 'red-500'), 'a colour of the Tailwind scale').toBe('');
});

test('the semantic colours of the palette have a value', async ({ admin, editor }) => {
    // theme.json once declared primary as var(--wp--preset--color--primary, #1f2937), a variable
    // defined as itself, which CSS discards as a cycle: a block coloured "Primary" was transparent.
    expect(await canvasColor(admin, editor, 'primary')).not.toBe('');
});

// CI scaffolds apiary/ci-dynamic before running these specs (see .github/workflows/tests.yml).
test('a dynamic block made in the theme renders on the published post', async ({ admin, editor, page, requestUtils }) => {
    const types = await requestUtils.rest({ path: '/wp/v2/block-types', params: { namespace: 'apiary' } });
    test.skip(! types.some((type: { name: string }) => type.name === 'apiary/ci-dynamic'), 'apiary/ci-dynamic is not scaffolded on this site');

    await admin.createNewPost({ title: `E2E apiary block ${runId}` });
    await editor.insertBlock({ name: 'apiary/ci-dynamic' });
    const postId = await editor.publishPost();

    try {
        const post = await requestUtils.rest({ path: `/wp/v2/posts/${postId}` });
        await page.goto(post.link);

        await expect(page.locator('.wp-block-apiary-ci-dynamic')).toContainText('CI dynamic');
    } finally {
        await requestUtils.rest({ method: 'DELETE', path: `/wp/v2/posts/${postId}`, params: { force: true } });
    }
});
