@extends('Layouts.seller')

@section('title', 'Edit Product')

@section('content')
    <h1 class="admin-page-title">Edit Product</h1>
    <p class="admin-page-subtitle">{{ $product->name }}</p>

    <form method="POST" action="{{ route('seller.products.update', $product) }}" enctype="multipart/form-data" class="sp-form">
        @include('Seller.Products._form')
    </form>
@endsection