<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tour Confirmation Letter</title>
    <style>
        @page {
    margin: 20px;
}

body {
    font-size: 11px;
    zoom: 0.9; /* shrink content to fit */
}
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            padding: 40px;
            line-height: 1.5;
        }

        h2 { margin-bottom: 5px; }
        .header, .footer { text-align: center; }
        .logo { float: left; }
        .booking-number {
            float: right;
            border: 1px solid #000;
            padding: 5px 10px;
            font-weight: bold;
        }
        .clearfix::after { content: ""; display: table; clear: both; }
        .section {
            border: 1px solid black;
            padding: 10px;
            margin-top: 10px;
        }
        .section-title {
            font-weight: bold;
            border-bottom: 1px solid black;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        .bold { font-weight: bold; }
        .small { font-size: 11px; color: #444; }
        .signature-line { margin: 10px 0; line-height: 1;}
        .price { font-size: 14px; font-weight: bold; margin: 0; }
        .footer {
            margin-top: 40px;
            font-size: 11px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        table.details { width: 100%; margin-top: 15px; }
        table.details td { vertical-align: top; padding: 5px; }
        .contact-title { font-weight: bold; }
    </style>
</head>
<body>

<div class="header clearfix">
    <div class="logo">
        <img src="https://i.imgur.com/OzmWjEq.png" alt="Jahongir Travel" height="50">
    </div>
    <div class="booking-number">
        Booking Number: <span>{{ $booking->booking_number }}</span>
    </div>
</div>

<h2>Tour Confirmation Letter - {{ $booking->tour->title ?? 'Unknown Tour' }}</h2>

<p><strong>Todays Date:</strong> {{ \Carbon\Carbon::now()->format('F j, Y') }}</p>
<p><strong>Contact:</strong> {{ $booking->guest->full_name ?? 'Unknown Guest' }}, {{ $booking->guest->phone ?? '(no phone)' }}</p>

<div class="section">
    <table class="details">
        <tr>
            <td>
                <div class="contact-title">Chamber Contact Information:</div>
                Jahongir Travel<br>
                Chirokchi 4, 140100<br>
                Phone: (998) 66 235 78 99
            </td>
            <td>
                <div class="contact-title">Contact Name:</div>
                {{ $booking->guest->full_name ?? 'Guest name' }}
            </td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">
        Tour Name: {{ $booking->tour->title ?? 'Unknown' }} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        Departure Date: {{ \Carbon\Carbon::parse($booking->booking_start_date_time)->format('F j, Y') }}
    </div>

    <p><span class="bold">Deposit Amount & Final Payment Due Date:</span> 0</p>
    <p class="small">
        The total payment is due with completed reservation form.
        <i>Credit cards are acceptable forms of payment for deposit and final payment.</i>
    </p>

    <p class="bold">INCLUDED:</p>
    <p>
        Transport: Air-conditioned vehicle from {{ $booking->pickup_location }} to {{ $booking->dropoff_location }}<br>
        Group: {{ $booking->group_name }} | People: {{ $booking->number_of_people }}<br>
        1. Breakfast &nbsp;&nbsp; 2. Lunch &nbsp;&nbsp; 1. Dinner<br>
        Accommodation: 1 night<br>
        Special Requests: {{ $booking->special_requests ?? 'None' }}
    </p>

    <div style="margin: 10px 0; text-align: left;">______________________________</div>

<p class="price" style="margin: 0;">
    <strong>Price per person</strong><br>
    {{ $booking->amount ? '$' . number_format($booking->amount, 2) : 'TBD' }}
</p>

<div style="margin: 10px 0; text-align: left;">______________________________</div>


    <p class="small">
        <strong>Optional Cancellation Waiver & Travel Protection:</strong> -
        Cancellation Waiver & Travel Protection is <u>HIGHLY RECOMMENDED</u> and is non-refundable.
    </p>

    <p class="signature-line">______________________________</p>
    <p class="small">departure.</p>
</div>

<div class="footer">
    <p>
        Phone: +998 91 555 08 08 &nbsp;&nbsp; Email: contact@jahongir-travel.uz<br>
        Address: 4 Chirokchi str. • Samarkand, 140100 • www.jahongir-travel.uz
    </p>
</div>

</body>
</html>
