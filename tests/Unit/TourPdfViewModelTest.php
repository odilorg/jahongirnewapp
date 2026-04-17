<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TourPriceTier;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Services\Pdf\TourPdfViewModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function coverage for the PDF ViewModel. Heavy I/O paths
 * (actual PDF bytes, atomic write) are exercised by the staging
 * preview run; this keeps normalization logic honest.
 */
class TourPdfViewModelTest extends TestCase
{
    private function buildModel(array $overrides = []): TourProduct
    {
        $product = new TourProduct(array_merge([
            'slug'             => 'sample-tour',
            'title'            => 'Sample Tour',
            'description'      => 'Lovely sample.',
            'highlights'       => ['Historic mosque', 'Local cuisine'],
            'includes'         => "Transport\nGuide\n",
            'excludes'         => "Entrance fees\n• Gratuities",
            'duration_days'    => 2,
            'duration_nights'  => 1,
            'currency'         => 'USD',
            'hero_image_url'   => null,
            'page_url'         => 'https://jahongir-travel.uz/sample',
        ], $overrides));

        // setRelation expects an Eloquent Collection, not plain array.
        $product->setRelation('priceTiers', new Collection([
            $this->makeTier(1, 100),
            $this->makeTier(2, 60),
            $this->makeTier(3, 40),
        ]));

        return $product;
    }

    private function makeTier(int $size, int $price, bool $active = true, ?string $directionCode = 'default', string $type = TourProduct::TYPE_PRIVATE): TourPriceTier
    {
        $tier = new TourPriceTier([
            'tour_product_direction_id' => $directionCode === null ? null : 1,
            'tour_type'                 => $type,
            'group_size'                => $size,
            'price_per_person_usd'      => $price,
            'is_active'                 => $active,
        ]);

        // Explicitly set the boolean; mass-assignment casts aren't always
        // applied on unsaved models in this app's setup.
        $tier->is_active = $active;

        // Attach a materialized direction relation so the ViewModel can
        // filter by code without hitting the DB.
        if ($directionCode !== null) {
            $dir = new TourProductDirection(['code' => $directionCode]);
            $tier->setRelation('direction', $dir);
        } else {
            $tier->setRelation('direction', null);
        }

        return $tier;
    }

    public function test_tiers_ordered_and_last_row_labelled_as_plus(): void
    {
        $vm = TourPdfViewModel::fromModel($this->buildModel(), Carbon::parse('2026-04-17T10:00:00Z'));

        $this->assertCount(3, $vm->priceTiers);
        $this->assertSame('1 person', $vm->priceTiers[0]['label']);
        $this->assertSame('2 persons', $vm->priceTiers[1]['label']);
        $this->assertSame('3+ persons', $vm->priceTiers[2]['label']);
        $this->assertTrue($vm->priceTiers[2]['is_last']);
    }

    public function test_non_default_direction_tiers_excluded_from_datasheet(): void
    {
        $product = $this->buildModel();
        $product->setRelation('priceTiers', new Collection([
            $this->makeTier(1, 100),
            $this->makeTier(2, 60),
            $this->makeTier(1, 999, true, 'sam-bukhara'), // variant — skipped
        ]));

        $vm = TourPdfViewModel::fromModel($product, Carbon::now());

        $this->assertCount(2, $vm->priceTiers);
        foreach ($vm->priceTiers as $tier) {
            $this->assertNotSame(999, $tier['price_usd']);
        }
    }

    public function test_null_direction_tiers_accepted(): void
    {
        // Legacy schema resilience: if somehow direction_id is NULL,
        // still accept the tier.
        $product = $this->buildModel();
        $product->setRelation('priceTiers', new Collection([
            $this->makeTier(1, 100, true, null),
            $this->makeTier(2, 60, true, null),
        ]));

        $vm = TourPdfViewModel::fromModel($product, Carbon::now());

        $this->assertCount(2, $vm->priceTiers);
    }

    public function test_group_tour_type_tiers_excluded(): void
    {
        $product = $this->buildModel();
        $product->setRelation('priceTiers', new Collection([
            $this->makeTier(1, 100),
            $this->makeTier(2, 60, true, 'default', TourProduct::TYPE_GROUP),
        ]));

        $vm = TourPdfViewModel::fromModel($product, Carbon::now());

        $this->assertCount(1, $vm->priceTiers);
        $this->assertSame(100, $vm->priceTiers[0]['price_usd']);
    }

    public function test_split_list_strips_bullet_prefixes_and_blanks(): void
    {
        $product = $this->buildModel([
            'includes' => "✓ Transport\n- Guide\n\n• Meals\n",
        ]);

        $vm = TourPdfViewModel::fromModel($product, Carbon::now());

        $this->assertSame(['Transport', 'Guide', 'Meals'], $vm->includes);
    }

    public function test_duration_label_formats(): void
    {
        $vm = TourPdfViewModel::fromModel(
            $this->buildModel(['duration_days' => 3, 'duration_nights' => 2]),
            Carbon::now()
        );
        $this->assertSame('3 days / 2 nights', $vm->durationLabel);

        $vm2 = TourPdfViewModel::fromModel(
            $this->buildModel(['duration_days' => 1, 'duration_nights' => null]),
            Carbon::now()
        );
        $this->assertSame('1 day', $vm2->durationLabel);
    }

    public function test_content_hash_is_deterministic_and_sensitive(): void
    {
        $a = TourPdfViewModel::fromModel($this->buildModel(), Carbon::now());
        $b = TourPdfViewModel::fromModel($this->buildModel(), Carbon::now()->addHour());

        // Same content, different timestamp — hash stays the same.
        $this->assertSame($a->contentHash, $b->contentHash);

        // Price change — hash must diverge.
        $changed = $this->buildModel();
        $changed->setRelation('priceTiers', new Collection([
            $this->makeTier(1, 999),
            $this->makeTier(2, 60),
            $this->makeTier(3, 40),
        ]));
        $c = TourPdfViewModel::fromModel($changed, Carbon::now());
        $this->assertNotSame($a->contentHash, $c->contentHash);
    }
}
