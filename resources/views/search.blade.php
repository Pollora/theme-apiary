{{--
 * Search results template
 *
 * @package %theme_namespace%
 --}}
@extends('layouts.app')

@section('content')
    <div data-pollora-template="search">
        <header class="border-b border-outline pt-8 pb-8">
            <h1 class="text-4xl font-extrabold tracking-tight text-foreground">
                {!! sprintf(__('Search results for: "%s"', '%theme_name%'), get_search_query()) !!}
            </h1>
        </header>
        @hasposts
            <div class="divide-y divide-outline">
                @posts
                    <article class="py-8">
                        <h2 class="text-xl font-semibold text-foreground hover:text-primary transition-colors">
                            <a href="@permalink">@title</a>
                        </h2>
                        <p class="mt-2 text-sm text-muted line-clamp-3">@excerpt</p>
                    </article>
                @endposts
            </div>
        @endhasposts
        @noposts
            <div class="py-12 text-center">
                <p class="text-muted">{{ __('No results found.', '%theme_name%') }}</p>
                <p class="mt-2 text-sm text-subtle">{{ __('Try a different search term.', '%theme_name%') }}</p>
            </div>
        @endnoposts
    </div>
@endsection
