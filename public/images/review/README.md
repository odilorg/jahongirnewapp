# TripAdvisor review card image

This directory hosts the QR review card that drivers/guides show to
guests at the end of the tour.

## File required

`tripadvisor-review-card-jahongir-travel.png`

## How it's referenced

- Config: `config/services.php` → `services.tripadvisor.review_card_url`
- Default path: `/images/review/tripadvisor-review-card-jahongir-travel.png`
- Override via `.env`: `TRIPADVISOR_REVIEW_CARD_URL=...`

## Used by

- Driver dispatch template (`config/inquiry_templates.php` → `driver_dispatch_uz`)
- Guide dispatch template (`config/inquiry_templates.php` → `guide_dispatch_uz`)
- `App\Services\DriverDispatchNotifier::reviewCardUrl()`

## Upload workflow

1. Save the PNG locally with the exact filename above
2. SCP onto the VPS:
   `scp tripadvisor-review-card-jahongir-travel.png jahongir:/var/www/jahongirnewapp/public/images/review/`
3. Verify it's reachable: `https://jahongir-app.uz/images/review/tripadvisor-review-card-jahongir-travel.png`

The file is intentionally NOT committed to git — it's a binary asset.
The git-tracked `.gitkeep` keeps the directory present in fresh
checkouts so the dispatch URL doesn't 404 mid-deploy.
