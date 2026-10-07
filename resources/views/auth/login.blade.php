@extends('layouts.app')

@section('title', 'Login')

@section('content')
    <div class="flex items-center justify-center min-h-screen">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-lg shadow p-8">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-blue-700">ERP RPA</h1>
                    <p class="text-gray-600 mt-2">Timbang Ayam Hidup</p>
                </div>

                @if ($errors->any())
                    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form action="{{ route('login') }}" method="POST" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                        Login
                    </button>
                </form>

                <div class="mt-6 p-4 bg-blue-50 rounded text-sm text-blue-800">
                    <p class="font-semibold mb-2">Demo Account:</p>
                    <p>Email: <code class="bg-white px-2 py-1 rounded">admin@rpa.test</code></p>
                    <p>Password: <code class="bg-white px-2 py-1 rounded">password</code></p>
                </div>
            </div>
        </div>
    </div>
@endsection
