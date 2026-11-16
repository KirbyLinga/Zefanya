@extends('Buyer.Layouts.app')

@section('title', 'Message with ' . ($conversation->seller->business_name ?? 'Seller'))

@section('content')
    @include('Buyer.Messages._inbox')
@endsection
