{{-- Payment receipt PDF (dompdf-compatible, A5 portrait) --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt {{ $receipt->ref }} — Jahongir Travel</title>
<style>
  @page { margin: 14mm 14mm 14mm 14mm; size: A5 portrait; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }
  .brand-bar { border-bottom: 3px solid #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
  .brand-name { font-size: 14pt; font-weight: bold; color: #0f766e; }
  .brand-tag  { font-size: 8pt; color: #6b7280; margin-top: 2px; }
  h2.title { font-size: 14pt; color: #111827; margin: 0 0 14px 0; text-align: center; letter-spacing: 1px; text-transform: uppercase; }
  .section { margin-bottom: 12px; }
  .section-title { font-size: 7.5pt; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; margin-bottom: 7px; }
  table.info { width: 100%; border-collapse: collapse; }
  table.info td { padding: 2px 0; font-size: 9.5pt; vertical-align: top; }
  table.info td.label { color: #6b7280; width: 38%; }
  table.info td.value { font-weight: bold; }
  table.amounts { width: 100%; border-collapse: collapse; margin-top: 4px; }
  table.amounts td { padding: 4px 0; font-size: 10pt; }
  table.amounts td.amount { text-align: right; font-weight: bold; font-size: 11pt; }
  table.amounts tr.total td { border-top: 2px solid #0f766e; padding-top: 6px; color: #0f766e; }
  table.amounts tr.balance td { color: #b45309; }
  table.amounts tr.paid-row td { color: #065f46; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 8.5pt; font-weight: bold; background: #d1fae5; color: #065f46; }
  .badge.partial { background: #fef3c7; color: #92400e; }
  .footer { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 8px; text-align: center; font-size: 8pt; color: #9ca3af; }
</style>
</head>
<body>
<div class="brand-bar">
  <div class="brand-name">Jahongir Travel</div>
  <div class="brand-tag">Tour Operator &middot; Samarkand, Uzbekistan &middot; +998 91 555 08 08</div>
</div>
<h2 class="title">Payment Receipt</h2>
<div class="section">
  <div class="section-title">Receipt Details</div>
  <table class="info">
    <tr><td class="label">Receipt No</td><td class="value">{{ $receipt->ref }}</td></tr>
    <tr><td class="label">Date</td><td class="value">{{ $receipt->payment_date }}</td></tr>
    <tr><td class="label">Booking Ref</td><td class="value">{{ $receipt->booking_ref }}</td></tr>
    <tr><td class="label">Method</td><td class="value">{{ $receipt->method }}</td></tr>
    <tr><td class="label">Status</td><td class="value"><span class="badge {{ $receipt->is_partial ? 'partial' : '' }}">{{ $receipt->is_partial ? 'Partial payment' : 'Paid in full' }}</span></td></tr>
  </table>
</div>
<div class="section">
  <div class="section-title">Guest &amp; Tour</div>
  <table class="info">
    <tr><td class="label">Guest Name</td><td class="value">{{ $receipt->guest_name }}</td></tr>
    <tr><td class="label">Guests</td><td class="value">{{ $receipt->pax }}</td></tr>
    <tr><td class="label">Tour</td><td class="value">{{ $receipt->tour_name }}</td></tr>
    <tr><td class="label">Travel Date</td><td class="value">{{ $receipt->travel_date }}</td></tr>
  </table>
</div>
<div class="section">
  <div class="section-title">Payment Summary</div>
  <table class="amounts">
    <tr class="paid-row">
      <td class="label">Amount Received</td>
      <td class="amount">$ {{ number_format($receipt->amount_paid, 2) }}</td>
    </tr>
    <tr>
      <td class="label">Tour Total</td>
      <td class="amount" style="font-size:10pt;">$ {{ number_format($receipt->tour_total, 2) }}</td>
    </tr>
    @if($receipt->is_partial)
    <tr class="balance">
      <td class="label">Balance Remaining</td>
      <td class="amount">$ {{ number_format($receipt->balance, 2) }}</td>
    </tr>
    @endif
  </table>
</div>
<div class="footer">
  Thank you for choosing Jahongir Travel!<br>
  jahongir-travel.uz &middot; +998 91 555 08 08
</div>
</body>
</html>
