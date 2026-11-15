@extends('errors.layout')

@section('title', 'Action not available')
@section('code', '405')
@section('heading')
That action isn't available here
@endsection
@section('message')
This address can't be opened directly. Go back and use the buttons on the page instead.
@endsection
