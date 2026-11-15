@extends('errors.layout')

@section('title', 'Access denied')
@section('code', '403')
@section('heading')
You can't open this page
@endsection
@section('message')
Your account doesn't have permission to view this page. If you think this is a mistake, go back and try another page.
@endsection
