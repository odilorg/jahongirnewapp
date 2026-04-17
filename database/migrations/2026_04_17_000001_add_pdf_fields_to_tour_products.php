<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds pdf_enabled + pdf_relative_path columns used by the datasheet PDF
 * exporter (TourPdfExportService). Seeds the relative paths that already
 * exist on the static site so regenerated files replace the current ones
 * in place and existing <a href> links on the PHP pages keep working.
 *
 * Mapping is deliberately explicit — the legacy PDF filenames predate the
 * admin slugs (and in some cases contain spaces), so we cannot derive the
 * path from the slug alone without breaking indexed URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_products', function (Blueprint $t): void {
            $t->boolean('pdf_enabled')
                ->default(false)
                ->after('is_active');

            $t->string('pdf_relative_path')
                ->nullable()
                ->after('pdf_enabled');
        });

        // Seed existing PDF paths so the first export overwrites the files
        // currently linked from the static site, no redirect plumbing needed.
        $seeds = [
            'bukhara-city-tour'                   => '/tours-from-bukhara/tours/bukhara-city-tour.pdf',
            'daytrip-shahrisabz'                  => '/tours-from-samarkand/tours/day-tour-shahrisabz.pdf',
            'daytrip-urgut-bazar-konigul-village' => '/tours-from-samarkand/tours/urgut-konigil.pdf',
            'hiking-amankutan'                    => '/tours-from-samarkand/tours/aman-kutan-tour.pdf',
            'hiking-amankutan-shahrisabz'         => '/tours-from-samarkand/tours/aman-kutan-shahrisabz.pdf',
            // Space in filename preserved — this is the currently indexed URL.
            'nuratau-homestay-2-days'             => '/tours-from-samarkand/tours/nuratau 2d-1n.pdf',
            'nuratau-homestay-3-days'             => '/tours-from-samarkand/tours/nuratau-homestay-yurt-camp-3days.pdf',
            'nuratau-homestay-4-days'             => '/tours-from-samarkand/tours/nuratau 4d-3n.pdf',
            'samarkand-city-tour'                 => '/tours-from-samarkand/tours/samarkand-city-tour.pdf',
            'seven-lakes-tajikistan-tour'         => '/tajikistan-tours/tours/seven-lakes-tajikistan-tour.pdf',
            'tour-from-khiva-ancient-fortresses'  => '/tours-from-khiva/tours/ancient-fortresses-tour.pdf',
            'yurt-camp-tour'                      => '/tours-from-samarkand/tours/yurt-camp-aydarkul.pdf',
        ];

        foreach ($seeds as $slug => $path) {
            DB::table('tour_products')
                ->where('slug', $slug)
                ->update([
                    'pdf_enabled'       => true,
                    'pdf_relative_path' => $path,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tour_products', function (Blueprint $t): void {
            $t->dropColumn(['pdf_enabled', 'pdf_relative_path']);
        });
    }
};
