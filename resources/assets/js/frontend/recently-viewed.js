/**
 * Recently Viewed Products — localStorage tracker
 *
 * Stores visited product data on single product pages.
 * Exposes window.%theme_camel%RecentlyViewed.get(excludeId) for consumers.
 */

const STORAGE_KEY = '%theme_name%_recently_viewed';
const MAX_ITEMS = 12;

const load = () => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
    } catch {
        return [];
    }
};

const save = (items) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch { /* quota exceeded — silent fail */ }
};

/**
 * Track the current product page.
 * Reads data from the DOM and %theme_camel%Cart.currentProduct.
 */
const trackCurrentProduct = () => {
    const data = window.%theme_camel%Cart?.currentProduct;
    if (!data?.id) return;

    const entry = {
        id: data.id,
        name: data.name,
        price: data.price,
        image: data.image,
        url: data.url,
    };

    let items = load();

    // Remove duplicate
    items = items.filter(item => item.id !== entry.id);

    // Prepend (most recent first)
    items.unshift(entry);

    // Trim to max
    if (items.length > MAX_ITEMS) {
        items = items.slice(0, MAX_ITEMS);
    }

    save(items);
};

/**
 * Get recently viewed products, excluding a given product ID.
 *
 * @param {number|null} excludeId — Product ID to exclude (e.g. the just-added product)
 * @param {number} limit — Max items to return
 * @returns {Array<{id:number, name:string, price:string, image:string, url:string}>}
 */
const get = (excludeId = null, limit = 6) => {
    let items = load();
    if (excludeId) {
        items = items.filter(item => item.id !== excludeId);
    }
    return items.slice(0, limit);
};

// Auto-track on single product pages
if (document.querySelector('.single-product') && window.%theme_camel%Cart?.currentProduct) {
    trackCurrentProduct();
}

// Expose API
window.%theme_camel%RecentlyViewed = { get };
