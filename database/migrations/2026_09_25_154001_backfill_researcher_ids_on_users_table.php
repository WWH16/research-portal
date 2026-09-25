<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give existing accounts without a researcher ID one in the ISU-<year>-<number> format,
     * numbered in sign-up order within the year each account was created.
     */
    public function up(): void
    {
        $users = DB::table('users')
            ->whereNull('researcher_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at']);

        foreach ($users as $user) {
            $prefix = 'ISU-'.Carbon::parse($user->created_at ?? now())->year.'-';

            $last = DB::table('users')
                ->where('researcher_id', 'like', $prefix.'%')
                ->orderByRaw('LENGTH(researcher_id) DESC')
                ->orderByDesc('researcher_id')
                ->value('researcher_id');

            $next = $last === null ? 1 : (int) substr($last, strlen($prefix)) + 1;

            DB::table('users')
                ->where('id', $user->id)
                ->update(['researcher_id' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]);
        }
    }

    /**
     * Generated IDs can't be told apart from ones set by hand, so they are kept.
     */
    public function down(): void
    {
        //
    }
};
