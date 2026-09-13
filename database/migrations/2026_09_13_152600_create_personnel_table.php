<?php

use App\Models\Personnel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personnel')) {
            Schema::create('personnel', function (Blueprint $table) {
                $table->id();
                $table->string('first_name');
                $table->string('middle_name')->nullable();
                $table->string('last_name');
                $table->string('role');
                $table->foreignId('home_unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('personnel_status')->default('active');
                $table->timestamps();
            });
        }

        $ambiguous = Personnel::ambiguousLegacyRosterNames();

        $crewColumns = ['crew_member_1_name' => 'crew', 'crew_member_2_name' => 'crew'];

        foreach ($crewColumns as $column => $role) {
            $units = DB::table('units')->whereNotNull($column)->get(['id', $column]);

            foreach ($units as $unit) {
                $fullName = trim((string) $unit->{$column});

                if ($fullName === '') {
                    continue;
                }

                if (in_array($role . '|' . mb_strtolower($fullName), $ambiguous, true)) {
                    continue;
                }

                $alreadyExists = DB::table('personnel')
                    ->whereRaw("trim(concat_ws(' ', first_name, last_name)) = ?", [$fullName])
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $parts = preg_split('/\s+/', $fullName);
                $lastName = array_pop($parts);
                $firstName = implode(' ', $parts) ?: $lastName;

                DB::table('personnel')->insert([
                    'first_name' => $firstName,
                    'middle_name' => null,
                    'last_name' => $lastName,
                    'role' => $role,
                    'home_unit_id' => $unit->id,
                    'personnel_status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
