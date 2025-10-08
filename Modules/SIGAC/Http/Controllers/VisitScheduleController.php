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
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Support\IcsBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Mail\VisitScheduledMail; 
use Illuminate\Support\Facades\Mail;






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

    public function searchStaff(Request $request)
    {
        $q    = trim((string) $request->input('q', ''));
        $type = $request->input('type', 'all'); // all | employee | contractor

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $needle = '%' . str_replace(' ', '%', $q) . '%';

        // Empleados (planta): employees.person_id -> people.id
        $employees = DB::table('employees')
            ->join('people', 'people.id', '=', 'employees.person_id')
            ->where(function ($w) use ($needle) {
                $w->where('people.first_name', 'like', $needle)
                    ->orWhere('people.first_last_name', 'like', $needle)
                    ->orWhere('people.second_last_name', 'like', $needle);
            })
            ->select([
                DB::raw('people.id as person_id'),
                DB::raw("TRIM(CONCAT_WS(' ', people.first_name, people.first_last_name, COALESCE(people.second_last_name,''))) as name"),
                DB::raw("'employee' as type"),
                DB::raw('employees.id as source_id'),
            ]);

        // Contratistas: contractors.person_id -> people.id
        $contractors = DB::table('contractors')
            ->join('people', 'people.id', '=', 'contractors.person_id')
            ->where(function ($w) use ($needle) {
                $w->where('people.first_name', 'like', $needle)
                    ->orWhere('people.first_last_name', 'like', $needle)
                    ->orWhere('people.second_last_name', 'like', $needle);
            })
            ->select([
                DB::raw('people.id as person_id'),
                DB::raw("TRIM(CONCAT_WS(' ', people.first_name, people.first_last_name, COALESCE(people.second_last_name,''))) as name"),
                DB::raw("'contractor' as type"),
                DB::raw('contractors.id as source_id'),
            ]);

        // Unificar según filtro
        if ($type === 'employee') {
            $union = $employees;
        } elseif ($type === 'contractor') {
            $union = $contractors;
        } else {
            $union = $employees->unionAll($contractors);
        }

        // Ordenar y limitar
        $results = DB::query()
            ->fromSub($union, 'u')
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json($results);
    }


    /**
     * Almacenar la agenda de la visita y actualizar el estado de la solicitud.
     */
    public function store(Request $request)
    {
        // mínimo permitido: hoy + 5 días en zona Bogotá
        $minDate = Carbon::today('America/Bogota')->addDays(5)->toDateString();

        $validated = $request->validate([
            'visit_request_id'    => 'required|exists:visit_requests,id',
            'person_in_charge_id' => 'nullable|exists:people,id',
            'activity'            => 'required|string',
            'date'                => ['required', 'date', 'after_or_equal:' . $minDate], // 👈 regla 5 días
            'start_time'          => ['required', 'date_format:H:i'],
            'end_time'            => ['required', 'date_format:H:i', 'after:start_time'], // fin > inicio
            'environment_id'      => 'nullable|exists:environments,id',
            'observations'        => 'nullable|string',
        ], [
            'date.after_or_equal' => "La fecha debe ser igual o posterior a $minDate.",
            'end_time.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
        ]);

        // 1) Crear el schedule
        $visitSchedule = \Modules\SIGAC\Entities\VisitSchedule::create([
            'visit_request_id'    => $validated['visit_request_id'],
            'person_in_charge_id' => $validated['person_in_charge_id'] ?? null,
            'activity'            => $validated['activity'],
            'date'                => $validated['date'], // ya es required con regla
            'start_time'          => $validated['start_time'],
            'end_time'            => $validated['end_time'],
            'environment_id'      => $validated['environment_id'] ?? null,
            'observations'        => $validated['observations'] ?? null,
        ]);

        // 2) Actualizar la solicitud
        $visitRequest = \Modules\SIGAC\Entities\VisitRequest::findOrFail($validated['visit_request_id']);
        $visitRequest->state = 'Agendada';
        $visitRequest->save();

        // 3) Enviar correo de notificación
        \Illuminate\Support\Facades\Mail::to(
            $visitRequest->contact_email ?? config('mail.from.address')
        )->send(new \App\Mail\VisitScheduledMail($visitRequest, $visitSchedule));

        return redirect()
            ->route('sigac.academic_coordination.dashboard')
            ->with('success', 'Visita agendada correctamente y correo enviado.');
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
    public function calendar(VisitRequest $request)
    {
        // Buscar los agendamientos de esta solicitud
        $schedules = VisitSchedule::with('environment') // asegúrate de tener relación environment() en el modelo
            ->where('visit_request_id', $request->id)
            ->orderBy('date')
            ->get();

        // Fecha inicial del calendario: la primera fecha agendada, o la fecha de recepción como fallback
        $initialDate = $schedules->first()->date ?? ($request->date_received ?? now()->toDateString());

        return view('sigac::visitschedule.calendar', [
            'visitRequest' => $request,
            'schedules'    => $schedules,
            'initialDate'  => $initialDate,
            'titlePage'    => 'Agenda de la solicitud',
            'titleView'    => 'Agenda de la solicitud',
        ]);
    }
    public function eventsByRequest(VisitRequest $request)
    {
        $events = VisitSchedule::with('environment')
            ->where('visit_request_id', $request->id)
            ->get()
            ->map(function ($v) {
                $env = $v->environment?->name ?? 'Ambiente';
                return [
                    'id'    => 'visit-' . $v->id,
                    'title' => ($v->activity ? $v->activity . ' — ' : '') . $env,
                    'start' => $v->date . 'T' . $v->start_time,
                    'end'   => $v->date . 'T' . $v->end_time,
                    'color' => '#5b9bd5', // azul
                ];
            });

        // En VisitScheduleController@eventsByRequest
        return response()->json(
            VisitSchedule::with('environment')
                ->where('visit_request_id', $request->id)
                ->get()
                ->map(function ($v) {
                    return [
                        'id'    => 'visit-' . $v->id,
                        'title' => $v->activity ?: 'Visita',
                        'start' => $v->date . 'T' . $v->start_time,
                        'end'   => $v->date . 'T' . $v->end_time,
                        'color' => '#5b9bd5',
                        'extendedProps' => [
                            'activity'         => $v->activity,
                            'environment_name' => $v->environment?->name,
                        ],
                    ];
                })
        );
    }
    public function environment()
    {
        return $this->belongsTo(\Modules\SICA\Entities\Environment::class, 'environment_id');
    }
    public function calendarAll()
    {
        // Vista del calendario general (centrado en hoy)
        $initialDate = now()->toDateString();

        return view('sigac::visitschedule.calendar_all', [
            'initialDate' => $initialDate,
            'titlePage'   => 'Calendario general de visitas',
            'titleView'   => 'Calendario general de visitas',
        ]);
    }

    /**
     * Feed de eventos para FullCalendar (todas las visitas).
     * Soporta filtros opcionales por ?from=YYYY-MM-DD&to=YYYY-MM-DD&environment_id=&company=
     */
    public function eventsAll(Request $request)
    {
        $from = $request->query('from'); // opcional
        $to   = $request->query('to');   // opcional

        $q = VisitSchedule::query()
            ->with(['environment', 'visitRequest.company'])
            ->when($from, fn($qq) => $qq->whereDate('date', '>=', $from))
            ->when($to,   fn($qq) => $qq->whereDate('date', '<=', $to))
            ->when($request->query('environment_id'), fn($qq, $envId) => $qq->where('environment_id', $envId))
            ->when($request->query('company'), function ($qq, $companyName) {
                $qq->whereHas('visitRequest.company', function ($c) use ($companyName) {
                    $c->where('name', 'like', "%{$companyName}%");
                });
            })
            ->orderBy('date');

        $events = $q->get()->map(function ($v) {
            $env = $v->environment?->name ?? 'Ambiente';
            $comp = $v->visitRequest?->company?->name ?? 'Empresa';
            $title = trim(($v->activity ?: 'Visita') . ' — ' . $env);

            return [
                'id'    => 'visit-' . $v->id,
                'title' => $title,
                'start' => $v->date . 'T' . $v->start_time,
                'end'   => $v->date . 'T' . $v->end_time,
                'color' => '#5b9bd5', // azul
                'extendedProps' => [
                    'activity'          => $v->activity,
                    'environment_name'  => $env,
                    'company'           => $comp,
                    'request_id'        => $v->visit_request_id,
                    'observations'      => $v->observations,
                    'date'              => $v->date,
                    'start_time'        => $v->start_time,
                    'end_time'          => $v->end_time,
                ],
            ];
        });

        return response()->json($events);
    }

    public function downloadIcs(VisitSchedule $schedule)
    {
        $visitRequest = VisitRequest::find($schedule->visit_request_id);

        $summary = 'Visita - ' . optional($visitRequest->company)->name;
        $description = "Actividad: {$schedule->activity}\n"
            . "Encargado: " . optional($visitRequest->person)->first_name . "\n"
            . "Observaciones: " . ($schedule->observations ?? '—');

        $ics = IcsBuilder::singleEvent([
            'uid'         => "visit-{$schedule->id}@sicefa.local",
            'summary'     => $summary,
            'description' => $description,
            'location'    => optional($schedule->environment)->name ?? 'SENA',
            'start'       => "{$schedule->date} {$schedule->start_time}",
            'end'         => "{$schedule->date} {$schedule->end_time}",
            'organizer'   => config('mail.from.address'),
            'attendees'   => array_filter([
                $visitRequest->contact_email ?? null,
            ]),
        ]);

        $filename = "visita-{$schedule->id}.ics";

        return new StreamedResponse(function () use ($ics) {
            echo $ics;
        }, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    public function notify(VisitRequest $visit)
    {
        // Busca la agenda más reciente de esta solicitud
        $schedule = VisitSchedule::where('visit_request_id', $visit->id)
            ->latest('id')
            ->first();

        if (!$schedule) {
            return back()->with('error', 'No hay una agenda asociada a esta solicitud.');
        }

        if (empty($visit->contact_email)) {
            return back()->with('error', 'La solicitud no tiene correo de contacto.');
        }

        try {
            Mail::to($visit->contact_email)->send(new VisitScheduledMail($visit, $schedule));
            return back()->with('success', 'Notificación enviada correctamente.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo enviar el correo. Verifica la configuración de correo.');
        }
    }
    public function quickReschedule(Request $request, VisitSchedule $schedule)
    {
        $minDate = Carbon::today('America/Bogota')->addDays(5)->toDateString();

        $validated = $request->validate([
            'date'           => ['required', 'date', 'after_or_equal:' . $minDate], // 👈
            'start_time'     => ['required'],
            'end_time'       => ['required'],
            'environment_id' => ['nullable', 'exists:environments,id'],
        ], [
            'date.after_or_equal' => "La fecha debe ser igual o posterior a $minDate.",
        ]);

        // ... resto tal cual (actualizas date, start_time, end_time, etc.)
    }
}
