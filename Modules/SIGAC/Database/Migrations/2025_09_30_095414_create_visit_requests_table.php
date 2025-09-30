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
        Schema::create('visit_requests', function (Blueprint $table) {
            $table->id();
            // Empresa asociada a la visita
            $table->foreignId('company_id')->constrained('companies');
            // Persona que realiza la solicitud (relacionado con "people" de SICA)
            $table->foreignId('person_id')->constrained('people');
            // Usuario del sistema que registra la solicitud
            $table->foreignId('user_id')->constrained('users');
            // Fecha en que se recibió la solicitud (por correo, por ejemplo)
            $table->date('date_received')->nullable();
            // Fecha en que se respondió la solicitud (o se gestionó)
            $table->date('response_date')->nullable();
            // Medio por el cual se respondió: llamada, correo, etc.
            $table->string('response_method')->nullable();
            // Estado de la solicitud: inicial "Sin agendar", luego "Agendada" u otros
            $table->string('state')->default('Sin agendar');
            // Cantidad de personas incluidas en la visita
            $table->integer('number_of_people')->nullable();
            // Ruta del archivo con listado de personas (Excel)
            $table->string('people_list_path')->nullable();
            // Ruta de los documentos adjuntos asociados a la solicitud
            $table->text('attachments_path')->nullable();
            // Observaciones adicionales
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
        Schema::dropIfExists('visit_requests');
    }
};
