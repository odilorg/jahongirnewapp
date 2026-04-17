{{-- Tour datasheet PDF (dompdf-compatible, A4 portrait) --}}
{{-- Rendered by App\Services\Pdf\TourPdfExportService --}}
{{-- Only reads from $tour (TourPdfViewModel). No DB access. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $tour->title }} — Jahongir Travel</title>
<style>
  @page { margin: 18mm 16mm 20mm 16mm; }

  body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 10pt;
    color: #1f2937;
    line-height: 1.45;
  }

  .brand-bar {
    border-bottom: 3px solid #0f766e;
    padding-bottom: 8px;
    margin-bottom: 14px;
  }
  .brand-bar .brand-name {
    font-size: 13pt;
    font-weight: bold;
    color: #0f766e;
    letter-spacing: 0.5px;
  }
  .brand-bar .brand-tag {
    font-size: 8.5pt;
    color: #6b7280;
    margin-top: 2px;
  }

  h1.tour-title {
    font-size: 18pt;
    color: #0f766e;
    margin: 4px 0 6px 0;
    line-height: 1.2;
  }

  .meta-line {
    font-size: 9.5pt;
    color: #4b5563;
    margin-bottom: 14px;
  }
  .meta-line .label { color: #6b7280; }
  .meta-line .dot { color: #9ca3af; padding: 0 6px; }

  .section-title {
    font-size: 11pt;
    font-weight: bold;
    color: #0f766e;
    border-bottom: 1px solid #d1d5db;
    padding-bottom: 3px;
    margin: 14px 0 7px 0;
  }

  p.description {
    margin: 0 0 10px 0;
    text-align: justify;
  }

  ul.bullets {
    margin: 0 0 0 0;
    padding-left: 16px;
  }
  ul.bullets li { margin: 2px 0; }

  .two-col {
    width: 100%;
    border-collapse: collapse;
    margin-top: 4px;
  }
  .two-col td {
    vertical-align: top;
    width: 50%;
    padding: 0 8px 0 0;
  }
  .two-col td + td { padding: 0 0 0 8px; }

  table.price-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 4px;
  }
  table.price-table th,
  table.price-table td {
    border: 1px solid #d1d5db;
    padding: 6px 10px;
    text-align: left;
    font-size: 10pt;
  }
  table.price-table th {
    background: #f0fdfa;
    color: #0f766e;
    font-weight: bold;
    font-size: 9.5pt;
  }
  table.price-table td.price {
    text-align: right;
    font-weight: bold;
    color: #0f766e;
    width: 30%;
  }

  .callout {
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
    padding: 6px 10px;
    font-size: 9pt;
    color: #78350f;
    margin-top: 8px;
  }

  .contact-box {
    margin-top: 16px;
    padding: 10px 12px;
    background: #f0fdfa;
    border: 1px solid #99f6e4;
    border-radius: 4px;
  }
  .contact-box .title {
    font-weight: bold;
    color: #0f766e;
    font-size: 10.5pt;
    margin-bottom: 4px;
  }
  .contact-box .line {
    font-size: 9.5pt;
    color: #134e4a;
    margin: 2px 0;
  }

  .footer {
    position: fixed;
    bottom: -12mm;
    left: 0; right: 0;
    text-align: center;
    font-size: 7.5pt;
    color: #9ca3af;
    border-top: 1px solid #e5e7eb;
    padding-top: 4px;
  }
  .footer .hash {
    color: #d1d5db;
    letter-spacing: 0.5px;
  }
</style>
</head>
<body>

<div class="brand-bar">
  <div class="brand-name">JAHONGIR TRAVEL</div>
  <div class="brand-tag">Tours in Uzbekistan &amp; Central Asia &middot; jahongir-travel.uz</div>
</div>

<h1 class="tour-title">{{ $tour->title }}</h1>

<div class="meta-line">
  <span class="label">Duration:</span> {{ $tour->durationLabel }}
  <span class="dot">•</span>
  <span class="label">Group:</span> Min 1 person, tailored to your party
  <span class="dot">•</span>
  <span class="label">Type:</span> Private tour
</div>

@if ($tour->description)
  <div class="section-title">About this tour</div>
  <p class="description">{{ $tour->description }}</p>
@endif

@if (! empty($tour->highlights))
  <div class="section-title">Highlights</div>
  <ul class="bullets">
    @foreach ($tour->highlights as $item)
      <li>{{ $item }}</li>
    @endforeach
  </ul>
@endif

<div class="section-title">Prices per person ({{ $tour->currency }})</div>
@if (! empty($tour->priceTiers))
  <table class="price-table">
    <thead>
      <tr>
        <th>Group size</th>
        <th style="text-align:right;">Price per person</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($tour->priceTiers as $tier)
        <tr>
          <td>{{ $tier['label'] }}</td>
          <td class="price">${{ $tier['price_usd'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <div class="callout">
    Prices are per person in USD for private group tours. Larger groups, custom
    itineraries or seasonal adjustments — contact us for a tailored quote.
  </div>
@else
  <div class="callout">
    Please contact us for prices. Rates depend on group size and season — we
    are happy to send you a personalised quote.
  </div>
@endif

@if (! empty($tour->includes) || ! empty($tour->excludes))
  <div class="section-title">Included / Not included</div>
  <table class="two-col">
    <tr>
      <td>
        @if (! empty($tour->includes))
          <strong style="color:#065f46;">Included in the price</strong>
          <ul class="bullets">
            @foreach ($tour->includes as $item)
              <li>{{ $item }}</li>
            @endforeach
          </ul>
        @endif
      </td>
      <td>
        @if (! empty($tour->excludes))
          <strong style="color:#991b1b;">Not included</strong>
          <ul class="bullets">
            @foreach ($tour->excludes as $item)
              <li>{{ $item }}</li>
            @endforeach
          </ul>
        @endif
      </td>
    </tr>
  </table>
@endif

<div class="contact-box">
  <div class="title">Ready to book or have questions?</div>
  <div class="line"><strong>WhatsApp:</strong> +998 94 880 11 99</div>
  <div class="line"><strong>Phone:</strong> +998 91 656 11 00</div>
  <div class="line"><strong>Email:</strong> info@jahongir-travel.uz</div>
  @if ($tour->pageUrl)
    <div class="line"><strong>Book online:</strong> {{ $tour->pageUrl }}</div>
  @endif
</div>

<div class="footer">
  Updated {{ $tour->generatedAtHuman }} &middot; Prices sourced directly from jahongir-travel.uz &middot;
  <span class="hash">rev {{ $tour->contentHash }}</span>
</div>

</body>
</html>
