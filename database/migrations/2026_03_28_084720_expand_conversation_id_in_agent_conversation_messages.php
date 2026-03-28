<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('conversation_id', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('conversation_id', 60)->change();
        });
    }
};
