{{--
 * Example custom Gutenberg block
 *
 * @package %theme_namespace%
 --}}
<div class="wp-block-group block-example">
    <h2 class="has-text-align-center has-tertiary-color has-text-color">{{ get_field('title') }}</h2>
    <InnerBlocks />
</div>