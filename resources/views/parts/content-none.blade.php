{{--
 * No content found partial
 *
 * @package %theme_namespace%
 --}}
<section class="no-results not-found">
    <header class="page-header">
        <h1 class="page-title">{{ esc_html__('Nothing Found', '%theme_name%') }}</h1>
    </header><!-- .page-header -->
    <div class="page-content">
        @if(is_home() && current_user_can('publish_posts'))
            <p>
                {!! sprintf(
                    wp_kses(
                        __('Ready to publish your first post? <a href="%1$s">Get started here</a>.', '%theme_name%'),
                        [
                            'a' => [
                                'href' => []
                            ]
                        ]
                    ),
                    esc_url(admin_url('post-new.php'))
                ) !!}
            </p>
        @elseif(is_search())
            <p>{{ esc_html__('Sorry, but nothing matched your search terms. Please try again with some different keywords.', '%theme_name%') }}</p>
            {!! get_search_form(false) !!}
        @else
            <p>{{ esc_html__('It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', '%theme_name%') }}</p>
            {!! get_search_form(false) !!}
        @endif
    </div><!-- .page-content -->
</section><!-- .no-results -->
