@extends('filament::page')

@section('content')
    <div class="p-6 bg-white shadow rounded">
        <h1 class="text-xl font-bold">Print Utility Usage</h1>

        <p><strong>Hotel Name:</strong> {{ $record->hotel->name ?? 'N/A' }}</p>
        <p><strong>Utility Name:</strong> {{ $record->utility->name ?? 'N/A' }}</p>
        <p><strong>Meter Serial Number:</strong> {{ $record->meter->meter_serial_number ?? 'N/A' }}</p>
        <p><strong>Usage Date:</strong> {{ $record->usage_date }}</p>
        <p><strong>Meter Latest Reading:</strong> {{ $record->meter_latest }}</p>
        <p><strong>Meter Previous Reading:</strong> {{ $record->meter_previous }}</p>
        <p><strong>Meter Difference:</strong> {{ $record->meter_difference }}</p>
    </div>

    <button onclick="window.print()" class="mt-4 px-4 py-2 bg-blue-500 text-white rounded">
        Print
    </button>
@endsection
