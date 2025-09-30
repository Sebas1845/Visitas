<?php

namespace Modules\SIGAC\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\SIGAC\Entities\VisitRequest;
use Modules\SIGAC\Entities\VisitSchedule;
use Modules\SICA\Entities\Person;
use Modules\SICA\Entities\Environment;
use Modules\SIGAC\Entities\InstructorProgram;
use Modules\SIGAC\Entities\EnvironmentInstructorProgram;
use Modules\SICA\Entities\ClassEnvironment;



/**
 * Controlador VisitScheduleController
 *
 * Gestiona la programación (agenda) de las visitas.
 */
class VisitScheduleController extends Controller
{
    /**
     * Mostrar formulario para crear una agenda de visita.
     *
     * @param VisitRequest $request  La solicitud de visita asociada
     */

    public function create(VisitRequest $request)
    {
        // Listar posibles encargados y ambientes
        $persons = Person::all()->mapWithKeys(function ($person) {
            $fullName = trim($person->first_name . ' ' . $person->first_last_name . ' ' . ($person->second_last_name ?? ''));
            return [$person->id => $fullName];
        });
        $environments = Environment::all()->pluck('name', 'id');

        // Listar actividades utilizadas anteriormente para mostrar en datalist
        $activities = VisitSchedule::select('activity')->distinct()->pluck('activity');

        return view('sigac::visitschedule.create', [
            'request'      => $request,
            'persons'      => $persons,
            'environments' => $environments,
            'activities'   => $activities,
            'titlePage'    => 'Agendar visita',
            'titleView'    => 'Agendar visita',
        ]);
    }

    /**
     * Almacenar la agenda de la visita y actualizar el estado de la solicitud.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'visit_request_id'    => 'required|exists:visit_requests,id',
            'person_in_charge_id' => 'nullable|exists:people,id',
            'activity'           => 'required|string',
            'date'               => 'nullable|date',
            'start_time'         => 'required',
            'end_time'           => 'required',
            'environment_id'     => 'nullable|exists:environments,id',
            'observations'       => 'nullable|string',
        ]);

        // Crear el cronograma
        VisitSchedule::create([
            'visit_request_id'     => $validated['visit_request_id'],
            'person_in_charge_id'  => $validated['person_in_charge_id'] ?? null,
            'activity'            => $validated['activity'],
            'date'                => $validated['date'] ?? now()->toDateString(),
            'start_time'          => $validated['start_time'],
            'end_time'            => $validated['end_time'],
            'environment_id'      => $validated['environment_id'] ?? null,
            'observations'        => $validated['observations'] ?? null,
        ]);

        // Actualizar el estado de la solicitud a "Agendada"
        $visitRequest = VisitRequest::findOrFail($validated['visit_request_id']);
        $visitRequest->state = 'Agendada';
        $visitRequest->save();

        return redirect()->route('sigac.academic_coordination.dashboard')->with('success', 'Visita agendada correctamente');
    }
    public function available_environments(Request $request)
    {
        $date       = $request->input('date');
        $start_time = $request->input('start_time');
        $end_time   = $request->input('end_time');

        // 1) Ambientes ocupados por programaciones de clase en la fecha solicitada
        $programIds = InstructorProgram::where('date', $date)->pluck('id');
        $occupiedByClasses = EnvironmentInstructorProgram::whereIn('instructor_program_id', $programIds)
            ->pluck('environment_id');

        // 2) Ambientes ocupados por otras visitas (VisitSchedule) con traslape de horario
        $occupiedByVisits = VisitSchedule::where('date', $date)
            ->where(function ($query) use ($start_time, $end_time) {
                $query->whereBetween('start_time', [$start_time, $end_time])
                    ->orWhereBetween('end_time', [$start_time, $end_time])
                    ->orWhere(function ($q) use ($start_time, $end_time) {
                        $q->where('start_time', '<=', $start_time)
                            ->where('end_time', '>=', $end_time);
                    });
            })
            ->pluck('environment_id');

        // 3) Ambientes externos (opcional: si no deseas considerarlos)
        $externalIds = ClassEnvironment::where('name', 'Externo')->pluck('id');

        // 4) Ambientes libres
        $occupied = $occupiedByClasses->merge($occupiedByVisits)->unique();
        $available = Environment::whereNotIn('id', $occupied)
            ->whereNotIn('class_environment_id', $externalIds)
            ->get();

        // Devuelve la lista como respuesta JSON o como vista parcial,
        // según cómo vayas a consumirla en el frontend
        return response()->json($available->map(function ($env) {
            return ['id' => $env->id, 'name' => $env->name];
        }));
    }
}
