<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CarMakesModelsSeeder extends Seeder
{
    public function run(): void
    {
        // Database-agnostic foreign key handling
        $driver = DB::getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }

        // امسح القديم
        DB::table('models')->truncate();
        DB::table('makes')->truncate();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $makes = [
            'هيونداي'   => ['إلنترا', 'أكسنت', 'توسان', 'سوناتا'],
            'كيا'       => ['سيراتو', 'سبورتاج', 'بيكانتو', 'كارنفال'],
            'تويوتا'    => ['كورولا', 'يارس', 'كامري', 'راف 4'],
            'نيسان'     => ['صني', 'قشقاي', 'سنترا'],
            'شيفروليه'  => ['أفيو', 'أوبترا', 'كابتيفا'],
            'بي إم دبليو' => ['320i', 'X5', 'X3'],
            'مرسيدس'    => ['C200', 'E200', 'GLC'],
        ];

        // نضيف "غير ذلك" للماركات
        $makes['غير ذلك'] = [];

        foreach ($makes as $makeName => $models) {
            $makeId = DB::table('makes')->insertGetId([
                'name'       => $makeName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // إضافة "غير ذلك" للموديلات لكل ماركة
            $models[] = 'غير ذلك';

            foreach ($models as $modelName) {
                DB::table('models')->insert([
                    'make_id'    => $makeId,
                    'name'       => $modelName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
