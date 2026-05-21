<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_members', function (Blueprint $table) {
            $table->id();
            $table->enum('tier', ['Bronze', 'Silver', 'Gold', 'Platinum']);
            $table->unsignedInteger('tenure_months');
            $table->unsignedInteger('visits_30d');
            $table->decimal('spend_30d', 10, 2);
            $table->timestamp('last_visit_at');
            $table->boolean('churned');
            $table->decimal('churn_probability', 5, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_members');
    }
};
