<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_sticky_promotions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('torrent_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->unsignedTinyInteger('sp_state');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->index();
            $t->dateTime('settled_at')->nullable();
            $t->decimal('occupancy', 18, 2)->default(0);
            $t->decimal('bid', 18, 2)->default(0);
            $t->decimal('reward', 18, 2)->default(0);
            $t->unsignedInteger('winners')->default(0);
            $t->index(['settled_at', 'torrent_id']);
        });
        Schema::create('plugin_sticky_contributions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('promotion_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('uploaded')->default(0);
            $t->decimal('paid', 18, 2)->default(0);
            $t->unique(['promotion_id', 'user_id']);
        });
        Schema::create('plugin_sticky_payments', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('promotion_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('request_key', 64)->unique();
            $t->decimal('occupancy', 18, 2);
            $t->decimal('bid', 18, 2);
            $t->decimal('reward', 18, 2);
            $t->dateTime('created_at');
        });
        Schema::create('plugin_sticky_peer_counters', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('promotion_id');
            $t->unsignedBigInteger('peer_id');
            $t->unsignedBigInteger('counter');
            $t->unique(['promotion_id', 'peer_id']);
        });
    }

    public function down(): void
    {
        // Financial records deliberately survive uninstall. Remove only after an explicit backup/review.
    }
};
