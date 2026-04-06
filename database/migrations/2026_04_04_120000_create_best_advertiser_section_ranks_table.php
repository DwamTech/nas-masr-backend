<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('best_advertiser_section_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('best_advertiser_id')->constrained('best_advertiser')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->timestamps();

            $table->unique(['best_advertiser_id', 'category_id'], 'best_adv_section_unique');
            $table->index(['category_id', 'rank'], 'best_adv_section_rank_idx');
        });

        $records = DB::table('best_advertiser')
            ->select('id', 'category_ids', 'rank', 'created_at', 'updated_at')
            ->orderBy('rank')
            ->orderBy('id')
            ->get();

        $perCategoryCounters = [];

        foreach ($records as $record) {
            $categoryIds = json_decode((string) $record->category_ids, true);
            if (!is_array($categoryIds)) {
                continue;
            }

            foreach ($categoryIds as $categoryId) {
                if (!is_numeric($categoryId)) {
                    continue;
                }

                $categoryKey = (int) $categoryId;
                $perCategoryCounters[$categoryKey] = ($perCategoryCounters[$categoryKey] ?? 0) + 1;

                DB::table('best_advertiser_section_ranks')->insert([
                    'best_advertiser_id' => (int) $record->id,
                    'category_id' => $categoryKey,
                    'rank' => $perCategoryCounters[$categoryKey],
                    'created_at' => $record->created_at ?? now(),
                    'updated_at' => $record->updated_at ?? now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('best_advertiser_section_ranks');
    }
};
