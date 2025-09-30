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
    public function up(): void
    {
        Schema::create('visit_schedules', function (Blueprint $table) {
            $table->id();
            // Relación con la solicitud de visita
            $table->foreignId('visit_request_id')->constrained('visit_requests');
            // Persona a cargo de la visita (responsable)
            $table->foreignId('person_in_charge_id')->nullable()->constrained('people');
            // Actividad a realizar durante la visita
            $table->string('activity');
            // Fecha programada para la visita
            $table->date('date')->nullable();
            // Hora de inicio y fin de la visita
            $table->time('start_time');
            $table->time('end_time');
            // Ambiente asignado para la visita
            $table->foreignId('environment_id')->nullable()->constrained('environments');
            // Observaciones de la agenda
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_schedules');
    }
};
