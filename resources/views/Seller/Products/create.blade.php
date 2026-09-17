@extends('Layouts.seller')

@section('title', 'Add Product')

@section('content')
    <h1 class="admin-page-title">Add Product</h1>
    <p class="admin-page-subtitle">List a new item in your store.</p>

    <form method="POST" action="{{ route('seller.products.store') }}" enctype="multipart/form-data" class="sp-form">
        @include('Seller.Products._form')
    </form>
@endsection