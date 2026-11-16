@extends('errors.layout')

@section('title', 'Something went wrong')
@section('code', '500')
@section('heading')
Something went wrong on our side
@endsection
@section('message')
We couldn't finish that request. Nothing was lost; please try again in a moment.
@endsection
