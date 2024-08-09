<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid("id")->unique();
            $table->string('hubspot_account_id')->nullable();
            $table->string('hubspot_user_id')->nullable();
            $table->string('hubspot_access_token')->nullable();
            $table->string('hubspot_refresh_token')->nullable();
            $table->string('hubspot_access_token_expires_in')->nullable();
            $table->string('hubspot_state')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
};
