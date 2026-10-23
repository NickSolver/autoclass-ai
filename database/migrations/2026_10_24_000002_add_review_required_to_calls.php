<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Признак «требуется вмешательство человека» по последнему анализу — для списка, фильтра и счётчика. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->boolean('review_required')->default(false)->after('status_changed_at');
            $table->index(['review_required', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['review_required', 'occurred_at']);
            $table->dropColumn('review_required');
        });
    }
};
