@extends('errors.layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('heading')
Slow down a little
@endsection
@section('message')
You've tried too many times in a short while. Please wait a minute and try again.
@endsection
