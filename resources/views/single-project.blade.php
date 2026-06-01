{{--
 * Single project post type template
 *
 * @package %theme_namespace%
 --}}
@extends('layouts.app')

@section('content')
    <div data-pollora-template="single-project">
        <h1>@title</h1>
        <div>@content</div>
    </div>
@endsection
