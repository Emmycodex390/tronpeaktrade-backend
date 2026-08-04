<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->string('header')->nullable()->after('name');
            $table->text('description')->nullable()->after('header');
            // store code as string so leading zeros are kept
            $table->string('code', 6)->nullable()->after('description')->unique();
            $table->boolean('active')->default(false)->change(); // if active already exists and is non-boolean adjust accordingly
        });
    }

    public function down()
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->dropColumn(['header', 'description', 'code']);
            // don't revert active type here — adjust if necessary for your schema
        });
    }
};

