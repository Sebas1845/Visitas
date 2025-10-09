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
use App\Mail\VisitUpdateMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;






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
        $minDate = \Carbon\Carbon::today('America/Bogota')->addDays(5)->toDateString();

        $validated = $request->validate([
            'visit_request_id'     => 'required|exists:visit_requests,id',
            'person_in_charge_id'  => 'nullable|exists:people,id',
            'activity'             => 'required|string',
            'date'                 => ['required', 'date', 'after_or_equal:' . $minDate],
            'start_time'           => ['required', 'date_format:H:i'],
            'end_time'             => ['required', 'date_format:H:i', 'after:start_time'],
            'environment_id'       => ['nullable', 'exists:environments,id'],
            'observations'         => ['nullable', 'string'],
        ]);

        $schedule = VisitSchedule::create([
            'visit_request_id'     => $validated['visit_request_id'],
            'person_in_charge_id'  => $validated['person_in_charge_id'] ?? null,
            'activity'             => $validated['activity'],
            'date'                 => $validated['date'],
            'start_time'           => $validated['start_time'],
            'end_time'             => $validated['end_time'],
            'environment_id'       => $validated['environment_id'] ?? null,
            'observations'         => $validated['observations'] ?? null,
        ]);

        $visitRequest = VisitRequest::findOrFail($validated['visit_request_id']);
        $visitRequest->update(['state' => 'Agendada']);

        // 🔔 Notificación (no bloquea si faltan emails)
        $schedule->load(['personInCharge', 'environment', 'visitRequest.company']);
        $res = $this->notifyChanges($schedule, [], 'created');

        $msg = 'Visita agendada.';
        if (!empty($res['sent'])) {
            $msg .= ' Notificaciones enviadas a: ' . implode(', ', $res['sent']) . '.';
        }
        if (!empty($res['skipped'])) {
            $msg .= ' Sin correo para: ' . implode(', ', $res['skipped']) . '.';
        }

        return redirect()
            ->route('sigac.academic_coordination.dashboard')
            ->with('success', $msg);
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
    public function update(Request $request, VisitSchedule $schedule)
    {
        $minDate = \Carbon\Carbon::today('America/Bogota')->addDays(5)->toDateString();

        $validated = $request->validate([
            'date'                => ['required', 'date', 'after_or_equal:' . $minDate],
            'start_time'          => ['required', 'date_format:H:i'],
            'end_time'            => ['required', 'date_format:H:i', 'after:start_time'],
            'environment_id'      => ['nullable', 'exists:environments,id'],
            'person_in_charge_id' => ['nullable', 'exists:people,id'],
            'observations'        => ['nullable', 'string'],
        ]);

        $before = $schedule->replicate(['id', 'created_at', 'updated_at']);

        $schedule->fill([
            'date'                => $validated['date'],
            'start_time'          => $validated['start_time'],
            'end_time'            => $validated['end_time'],
            'environment_id'      => $validated['environment_id'] ?? null,
            'person_in_charge_id' => $validated['person_in_charge_id'] ?? $schedule->person_in_charge_id,
            'observations'        => $validated['observations'] ?? $schedule->observations,
        ])->save();

        $schedule->visitRequest?->update(['state' => 'Agendada']);

        $schedule->load(['personInCharge', 'environment', 'visitRequest.company']);
        $changed = $this->changedFields($before, $schedule);

        $res = $this->notifyChanges($schedule, $changed, 'updated');

        $msg = 'Visita actualizada.';
        if (!empty($res['sent'])) {
            $msg .= ' Notificaciones enviadas a: ' . implode(', ', $res['sent']) . '.';
        }
        if (!empty($res['skipped'])) {
            $msg .= ' Sin correo para: ' . implode(', ', $res['skipped']) . '.';
        }

        return back()->with('success', $msg);
    }


    /**
     * Cancela la visita y notifica.
     */
    public function cancel(VisitSchedule $schedule, Request $request)
    {
        $schedule->visitRequest?->update(['state' => 'Cancelada']);

        if ($reason = trim((string) $request->input('reason'))) {
            $schedule->observations = trim(($schedule->observations ? $schedule->observations . "\n" : '') . 'Cancelada: ' . $reason);
            $schedule->save();
        }

        $schedule->load(['personInCharge', 'environment', 'visitRequest.company']);
        $res = $this->notifyChanges($schedule, ['canceled' => true], 'canceled');

        $msg = 'Visita cancelada.';
        if (!empty($res['sent'])) {
            $msg .= ' Notificaciones enviadas a: ' . implode(', ', $res['sent']) . '.';
        }
        if (!empty($res['skipped'])) {
            $msg .= ' Sin correo para: ' . implode(', ', $res['skipped']) . '.';
        }

        return back()->with('success', $msg);
    }


    /**
     * Devuelve qué campos relevantes cambiaron.
     * @return array<string, mixed>
     */
    private function changedFields(VisitSchedule $before, VisitSchedule $after): array
    {
        $changes = [];

        if ($before->date !== $after->date || $before->start_time !== $after->start_time || $before->end_time !== $after->end_time) {
            $changes['schedule'] = [
                'before' => "{$before->date} {$before->start_time}-{$before->end_time}",
                'after'  => "{$after->date} {$after->start_time}-{$after->end_time}",
            ];
        }
        if ((int) $before->environment_id !== (int) $after->environment_id) {
            $changes['environment'] = [
                'before' => optional($before->environment)->name ?? '—',
                'after'  => optional($after->environment)->name ?? '—',
            ];
        }
        if ((int) $before->person_in_charge_id !== (int) $after->person_in_charge_id) {
            $changes['assignee'] = [
                'before' => optional($before->personInCharge)->first_name
                    ? trim($before->personInCharge->first_name . ' ' . $before->personInCharge->first_last_name) : '—',
                'after'  => optional($after->personInCharge)->first_name
                    ? trim($after->personInCharge->first_name . ' ' . $after->personInCharge->first_last_name) : '—',
            ];
        }

        return $changes;
    }

    /**
     * Envía correos al contacto de la solicitud y al encargado asignado.
     * $event: 'created' | 'updated' | 'canceled'
     */
    private function notifyChanges(VisitSchedule $schedule, array $changed, string $event): array
    {
        $visit = $schedule->visitRequest;

        // Destinos posibles
        $contactEmail  = filter_var($visit->contact_email, FILTER_VALIDATE_EMAIL) ? $visit->contact_email : null;
        $assigneeEmail = $this->getPersonEmail($schedule->personInCharge);

        $sent = [];
        $skipped = [];

        $targets = [
            'Contacto'  => $contactEmail,
            'Encargado' => $assigneeEmail,
        ];

        foreach ($targets as $label => $to) {
            if ($to) {
                try {
                    \Mail::to($to)->send(new \App\Mail\VisitUpdateMail($visit, $schedule, $event, $changed));
                    $sent[] = $label;
                } catch (\Throwable $e) {
                    \Log::warning("Fallo enviando correo a {$label} ({$to}): " . $e->getMessage());
                    $skipped[] = $label; // si falla el envío, lo marcamos como omitido
                }
            } else {
                $skipped[] = $label; // sin correo → omitido
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }


    /**
     * Extrae email desde People (ajusta campo si en tu esquema es distinto).
     */
    private function getPersonEmail(?\Modules\SICA\Entities\Person $p): ?string
    {
        if (!$p) return null;

        $candidatos = [
            $p->misena_email ?? null,
            $p->sena_email ?? null,
            $p->personal_email ?? null,
        ];

        foreach ($candidatos as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        return null;
    }
    public function viewPeopleList(\Modules\SIGAC\Entities\VisitRequest $visit)
    {
        // Ruta guardada en BD (campo people_list_path)
        $excelPathRaw = (string) $visit->people_list_path;

        if (empty($excelPathRaw)) {
            return back()->with('error', 'No hay archivo asociado a esta solicitud.');
        }

        // Normaliza separadores
        $excelPath = str_replace('\\', '/', $excelPathRaw);

        // Ajusta si está guardado con prefijo "storage/app/"
        if (str_starts_with($excelPath, 'storage/app/')) {
            $excelPath = Str::after($excelPath, 'storage/app/');
        }

        // Verifica que exista
        if (!Storage::disk('local')->exists($excelPath)) {
            return back()->with('error', 'El archivo no existe en el almacenamiento.');
        }

        // Obtiene ruta absoluta
        $fullPath = storage_path('app/' . $excelPath);
        $mime = mime_content_type($fullPath);

        // Si es Excel, lo mostramos usando Office Viewer online
        if (in_array($mime, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel'
        ])) {
            $publicUrl = url('/storage/' . basename($fullPath));
            return redirect()->away('https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($publicUrl));
        }

        // Si no es Excel, lo descarga directamente
        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($excelPath) . '"',
        ]);
    }
}
