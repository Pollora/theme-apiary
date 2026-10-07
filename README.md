# %theme_name%

%theme_description%

Built with [Pollora](https://pollora.dev) from the [Apiary](https://github.com/Pollora/theme-apiary) template: a WooCommerce storefront in Blade, Tailwind CSS v4 and Alpine.js. It needs the WooCommerce plugin, active.

## What's inside

```
%theme_name%/
├── app/                 # PHP classes, namespace %theme_namespace%
│   ├── Cms/             # WordPress and WooCommerce integrations
│   ├── Http/Controllers # Controllers, including the product search API
│   ├── Services/        # Product price, search and URL helpers
│   └── inc/             # Hooks, helpers, WooCommerce customizations
├── config/              # menus, sidebars, supports, image sizes, WooCommerce, login screen
├── languages/           # Translations (text domain: %theme_name%)
├── resources/
│   ├── assets/          # CSS (design tokens in app.css), JS, fonts, images
│   └── views/           # Blade templates
│       └── woocommerce/ # WooCommerce template overrides
├── routes/api.php       # Theme API routes, under /api
├── style.css            # WordPress theme header
├── theme.json           # Base editor settings; the build adds the design tokens
└── vite.config.js       # Asset build
```

## Commands

Run from `themes/%theme_name%`:

```bash
npm run dev      # Vite dev server with hot reload
npm run build    # production assets
```

From the project root:

```bash
php artisan pollora:make:block my-block --theme=%theme_name%   # a new Gutenberg block
php artisan discovery:clear                                    # after adding attribute-based classes
php artisan pollora:doctor                                     # when something fails without an error
```

Change colors, font sizes, fonts and radii in the `@theme static` block of `resources/assets/css/app.css`, not in `theme.json`: `npm run build` writes them into the `theme.json` the editor reads.

## Read more

- [Themes](https://pollora.dev/theming/theme-structure/): structure, template hierarchy, `theme.json` and the Vite build
- [Assets and Vite](https://pollora.dev/theming/assets-vite/)
- [Theme API routes](https://pollora.dev/routing/wordpress-routes/#theme--plugin-api-routes)
