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
    Schema::table('users', function (Blueprint $table) {
        $table->string('username')->unique();
        $table->string('phone');
        $table->string('country');
        $table->string('address')->nullable();
        $table->string('id_type');
        $table->string('id_document'); // store file path
    });
}

public function down()
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['username','phone','country','address','id_type','id_document']);
    });
}
    /**
     * Reverse the migrations.
     */
    
};
