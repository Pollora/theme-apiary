# Apiary

A WooCommerce theme built on the [Pollora](https://pollora.dev) framework, featuring Tailwind CSS v4 and Alpine.js.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- [Pollora](https://pollora.dev) framework
- [WooCommerce](https://woocommerce.com/) 8.0+
- Node.js 18+ (for asset compilation)

## Installation

The theme must be installed within a Pollora project.

```bash
# Install Node dependencies
npm install

# Build production assets
npm run build
```

## Development

```bash
# Start Vite dev server with HMR
npm run dev
```

The Vite dev server auto-detects DDEV/Docker environments for proper HMR configuration.

## Structure

```
%theme_name%/
├── app/                    # PHP application logic
│   ├── Cms/                # WordPress CMS integrations
│   ├── Providers/          # Laravel service providers
│   ├── Walkers/            # Custom menu walkers
│   └── inc/                # Hooks, helpers, WooCommerce customizations
├── config/                 # Theme configuration (menus, sidebars, supports, WooCommerce)
├── resources/
│   ├── assets/             # CSS, JavaScript, fonts, images
│   └── views/              # Blade templates (128 files)
│       └── woocommerce/    # Full WooCommerce template overrides
├── languages/              # Translations (text domain: %theme_name%)
├── theme.json              # Base settings; the build adds the design tokens
├── vite.config.js          # Build configuration
└── routes.php              # Theme routes (optional)
```

## Features

- **WooCommerce**: Complete template coverage (cart, checkout, my account, orders, product pages)
- **Tailwind CSS v4**: Design tokens synced with WordPress `theme.json`
- **Alpine.js**: Lightweight interactivity (menus, modals, cart slide-over)
- **Blade templates**: 128 views with component support
- **11 menu locations**: Header, footer, mobile, account, and more
- **Accessibility**: Semantic HTML, ARIA labels, keyboard navigation
- **i18n**: Fully translatable (French included)

## Design tokens

The design lives in the `@theme static` block of `resources/assets/css/app.css`:
colours, type scale, fonts and radii, with concrete values. `npm run build`
writes them into the `theme.json` the editor reads
(`public/build/theme/<slug>/assets/theme.json`), so the page and the editor
always offer the same palette and sizes.

- Change a token in `app.css`, not in `theme.json`: the root `theme.json` is
  only the base (layout, editor settings), and a slug defined there wins over
  `@theme`.
- To keep a value out of Tailwind, define that slug in `theme.json`, or turn a
  whole family off in `vite.config.js` (`disableTailwindColors`,
  `disableTailwindFontSizes`, `disableTailwindFonts`,
  `disableTailwindBorderRadius`).
- Spacing and layout widths are not generated: set them in `theme.json`.

See [Theme.json and Vite Build Integration](https://pollora.dev/theming/theme-structure/)
for the details.

## Gutenberg design system

Every core block is styled in `theme.json` (`styles`: root, elements, blocks),
from the design tokens only, so a paragraph, a quote, a table or a button look
the same in the editor and on the page. Content is no longer styled by
Tailwind Typography (`prose`); product descriptions still are.

- Reference presets by the name WordPress prints: `2xl` becomes
  `var(--wp--preset--font-size--2-xl)`. Never a Tailwind variable: it does not
  exist in the editor. A block's `css` takes one selector per rule.
- Links are styled inside content blocks (paragraph, list, table, verse), not
  globally, so WooCommerce and the templates keep their own link styles.
- `app/Cms/StyleLayers.php` puts WordPress's CSS in cascade layers, declared at
  the top of `app.css`: `theme, base, wp-core, wp, components, utilities`. The
  block library and the global styles beat Tailwind's reset, and a Tailwind
  class always beats them.
- `php bin/tests/design-system.php` (run in CI) checks that every preset and
  custom variable the styles use exists.

## Configuration

All theme behavior is driven by config files in `config/`:

| File               | Purpose                                         |
| ------------------ | ----------------------------------------------- |
| `admin.php`        | WordPress admin customization                   |
| `disable.php`      | Disable unnecessary WP features                 |
| `gutenberg.php`    | Block editor categories                         |
| `images.php`       | Custom image sizes                              |
| `menus.php`        | Menu locations                                  |
| `providers.php`    | Additional service providers                    |
| `sidebars.php`     | Widget areas                                    |
| `supports.php`     | Theme supports (thumbnails, WooCommerce, etc.)  |
| `templates.php`    | Custom page templates                           |
| `woocommerce.php`  | Product grid classes, icons, checkout layout     |

## License

GPL-2.0-or-later
