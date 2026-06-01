{{--
 * Single post template
 *
 * @package %theme_namespace%
 --}}
@extends('layouts.app')

@section('content')
<!-- data-pollora-template="single" -->
    @posts
    @include('parts.content')
    @endposts
@endsection
