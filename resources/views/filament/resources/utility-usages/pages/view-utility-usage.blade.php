<style>
    .custom-invoice-wrapper {
        padding: 20px;
    }

    .custom-invoice {
        font-family: Arial, sans-serif;
        line-height: 1.5;
        font-size: 14px;
        color: #333;
        background-color: #fff;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 20px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .custom-invoice .table {
        width: 100%;
        margin-bottom: 1rem;
        border-collapse: collapse;
    }

    .custom-invoice .table-bordered {
        border: 1px solid #ddd;
    }

    .custom-invoice .table-bordered th,
    .custom-invoice .table-bordered td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }

    .custom-invoice .card-header {
        text-align: center;
        font-weight: bold;
        margin-bottom: 20px;
        font-size: 18px;
    }

    .custom-invoice .no-print {
        margin-top: 20px;
    }

    @media print {
        /* Hide everything except the custom-invoice-wrapper */
        body * {
            visibility: hidden;
        }

        .custom-invoice-wrapper {
            visibility: visible;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }

        .custom-invoice-wrapper * {
            visibility: visible;
        }

        .custom-invoice .no-print {
            display: none;
        }
    }
</style>
@php
    \Carbon\Carbon::setLocale('uz'); // Set locale to Uzbek
@endphp

<div class="custom-invoice-wrapper">
    <div class="custom-invoice">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12" id="invoice">
                    <!-- Original Invoice Content -->
                    <div class="invoice p-3 mb-3">
                        <div class="row invoice-info">
                            <div class="col-12 table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tbody>
                                        <tr><td>Мижоз:</td><td>{{ $record->hotel->official_name }}</td></tr>
                                        <tr><td>Шартнома:</td><td>{{ $record->meter->contract_number }} sana {{ \Carbon\Carbon::parse($record->meter->contract_date)->format('d/m/Y') }}
                                        <tr><td>Х/р:</td><td>{{ $record->hotel->account_number }}</td></tr>
                                        <tr><td>Банк номи:</td><td>{{ $record->hotel->bank_name }}</td></tr>
                                        <tr><td>Банк коди:</td><td>{{ $record->hotel->bank_mfo}}</td></tr>
                                        <tr><td>ИНН:</td><td>{{ $record->hotel->inn }}</td></tr>
                                        <tr><td>Манзил:</td><td>{{ $record->hotel->address }}</td></tr>
                                        <tr><td>Тел:</td><td>{{ $record->hotel->phone }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3>Сарфланган {{ $record->meter->utility->name }} буйича хисобот</h3>
                                <h3>{{ strtoupper(\Carbon\Carbon::parse($record->usage_date)->translatedFormat('F')) }} {{ \Carbon\Carbon::parse($record->usage_date)->year }} учун</h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Объектни номи ва манзили</th>
                                            <th>Курсатгич завод раками</th>
                                            <th>Олдинги курсатгич</th>
                                            <th>Охирги курсатгич</th>
                                            <th>Фарки</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>{{ $record->hotel->address }}</td>
                                            <td>{{ $record->meter->meter_serial_number }}</td>
                                            <td>{{ $record->meter_previous }}</td>
                                            <td>{{ $record->meter_latest }}</td>
                                            <td>{{ $record->meter_difference }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="table-borderless">
                            <table>
                                <tbody>
                                    <tr>
                                        <td>{{ $record->hotel->official_name }}</td>
                                        <td style="text-align:right;">Tabiiy Gaz</td>
                                    </tr>
                                    <tr>
                                        <td>Жавобгар шахс:</td>
                                        <td style="text-align:right;">Кабул килувчи:</td>
                                    </tr>
                                    <tr>
                                        <td>Имзо_____________________</td>
                                        <td style="text-align:right;">Имзо_____________________</td>
                                    </tr>
                                    <tr>
                                        <td>Сана: {{ \Carbon\Carbon::parse($record->usage_date)->format('d/m/Y') }}</td>
                                        <td style="text-align:right;">Сана: {{ \Carbon\Carbon::parse($record->usage_date)->format('d/m/Y') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <br>
                    <hr style="height:5px;border-width:0;color:gray;background-color:gray">
                    <!-- Duplicate Invoice Content -->
                    <br>
                    <div class="invoice p-3 mb-3">
                        <div class="row invoice-info">
                            <div class="col-12 table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tbody>
                                        <tr><td>Мижоз:</td><td>{{ $record->hotel->official_name }}</td></tr>
                                        <tr><td>Шартнома:</td><td>{{ $record->meter->contract_number }} sana {{ \Carbon\Carbon::parse($record->meter->contract_date)->format('d/m/Y') }}
                                        <tr><td>Х/р:</td><td>{{ $record->hotel->account_number }}</td></tr>
                                        <tr><td>Банк номи:</td><td>{{ $record->hotel->bank_name }}</td></tr>
                                        <tr><td>Банк коди:</td><td>{{ $record->hotel->bank_mfo}}</td></tr>
                                        <tr><td>ИНН:</td><td>{{ $record->hotel->inn }}</td></tr>
                                        <tr><td>Манзил:</td><td>{{ $record->hotel->address }}</td></tr>
                                        <tr><td>Тел:</td><td>{{ $record->hotel->phone }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3>Сарфланган {{ $record->meter->utility->name }} буйича хисобот</h3>
                                <h3>{{ strtoupper(\Carbon\Carbon::parse($record->usage_date)->translatedFormat('F')) }} {{ \Carbon\Carbon::parse($record->usage_date)->year }} учун</h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Объектни номи ва манзили</th>
                                            <th>Курсатгич завод раками</th>
                                            <th>Олдинги курсатгич</th>
                                            <th>Охирги курсатгич</th>
                                            <th>Фарки</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>{{ $record->hotel->address }}</td>
                                            <td>{{ $record->meter->meter_serial_number }}</td>
                                            <td>{{ $record->meter_previous }}</td>
                                            <td>{{ $record->meter_latest }}</td>
                                            <td>{{ $record->meter_difference }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="table-borderless">
                            <table>
                                <tbody>
                                    <tr>
                                        <td>{{ $record->hotel->official_name }}</td>
                                        <td style="text-align:right;">Tabiiy Gaz</td>
                                    </tr>
                                    <tr>
                                        <td>Жавобгар шахс:</td>
                                        <td style="text-align:right;">Кабул килувчи:</td>
                                    </tr>
                                    <tr>
                                        <td>Имзо_____________________</td>
                                        <td style="text-align:right;">Имзо_____________________</td>
                                    </tr>
                                    <tr>
                                        <td>Сана: {{ \Carbon\Carbon::parse($record->usage_date)->format('d/m/Y') }}</td>
                                        <td style="text-align:right;">Сана: {{ \Carbon\Carbon::parse($record->usage_date)->format('d/m/Y') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>



