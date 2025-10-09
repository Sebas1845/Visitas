@extends('sigac::layouts.master')

@section('content')
<div class="card">
    <div class="card-header">
        <h2>Solicitudes de visita</h2>
    </div>
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Empresa</th>
                        <th>NIT</th>
                        <th>Solicitante</th>
                        <th>Fecha de recepción</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Nº personas</th>
                        <th style="width: 300px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($visitRequests as $visit)
                        @php
                            $hasEmail = filled($visit->contact_email);

                            // Último schedule asociado (si tienes la relación $visit->schedules)
                            $lastSchedule = optional($visit->schedules)->last();
                            $scheduleId   = $lastSchedule->id ?? null;

                            // Normalizar ruta del excel (people_list_path)
                            $excelPathRaw = (string) ($visit->people_list_path ?? '');
                            $excelPath = str_replace('\\', '/', $excelPathRaw);
                            if (\Illuminate\Support\Str::startsWith($excelPath, ['storage/app/', '/storage/app/'])) {
                                $excelPath = \Illuminate\Support\Str::after($excelPath, 'storage/app/');
                            }
                            $canViewExcel = $excelPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($excelPath);
                        @endphp

                        <tr>
                            <td>{{ $visit->id }}</td>
                            <td>{{ $visit->company->name }}</td>
                            <td>{{ $visit->company->nit ?? '—' }}</td>
                            <td>{{ $visit->person->full_name ?? '—' }}</td>
                            <td>{{ $visit->date_received }}</td>
                            <td class="text-capitalize">{{ $visit->type }}</td>
                            <td>
                                <span class="badge bg-{{ $visit->state === 'Agendada' ? 'success' : ($visit->state === 'Cancelada' ? 'danger' : 'secondary') }}">
                                    {{ $visit->state }}
                                </span>
                            </td>
                            <td>{{ $visit->number_of_people ?? '—' }}</td>

                            <td class="d-flex flex-wrap gap-2 align-items-center">
                                {{-- Ver modal --}}
                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#modal{{ $visit->id }}" title="Ver detalle">
                                    {{-- ícono ojo --}}
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                         fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8"/>
                                        <path d="M8 5.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5"/>
                                    </svg>
                                </button>

                                @if($visit->state === 'Sin agendar')
                                    {{-- Agendar --}}
                                    <a href="{{ route('sigac.academic_coordination.visitschedule.create', ['request' => $visit->id]) }}"
                                       class="btn btn-sm btn-primary" title="Agendar">
                                        {{-- ícono calendario con + --}}
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                             fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                            <path d="M3.5 0a.5.5 0 0 0-.5.5V1H2a2 2 0 0 0-2 2v1h16V3a2 2 0 0 0-2-2h-1V.5a.5.5 0 0 0-1 0V1H4V.5a.5.5 0 0 0-.5-.5z"/>
                                            <path d="M16 14V5H0v9a2 2 0 0 0 2 2h7.5a.5.5 0 0 0 .5-.5V14h-2a.5.5 0 0 1 0-1h2v-2a.5.5 0 0 1 1 0v2h2a.5.5 0 0 1 0 1h-2v1.5a.5.5 0 0 0 .5.5H14a2 2 0 0 0 2-2z"/>
                                        </svg>
                                    </a>
                                @else
                                    {{-- (Opcional) Reenviar notificación manual si conservas esa ruta/controlador --}}
                                    @if(Route::has('sigac.academic_coordination.visitrequest.notify'))
                                    <form action="{{ route('sigac.academic_coordination.visitrequest.notify', $visit->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Se enviará la notificación por correo. ¿Continuar?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success"
                                                {{ $hasEmail ? '' : 'disabled' }}
                                                title="{{ $hasEmail ? 'Notificar contacto' : 'No hay correo de contacto' }}">
                                            {{-- ícono sobre/correo --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                 fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                                <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v.217L8 8.5 0 4.217z"/>
                                                <path d="M0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.03A2 2 0 0 0 2 14h12a2 2 0 0 0 1.809-1.14l-6.57-4.03L8 9.583l-1.239-.753zM16 4.697l-5.803 3.546L16 11.801V4.697z"/>
                                            </svg>
                                        </button>
                                    </form>
                                    @endif

                                    {{-- Reprogramar (usa el último schedule) --}}
                                    @if($scheduleId)
                                    <form action="{{ route('sigac.academic_coordination.visitschedule.update', $scheduleId) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('¿Confirmar reprogramación?');">
                                        @csrf
                                        {{-- Campos ocultos que se llenan con prompts --}}
                                        <input type="date" name="date" id="date-{{ $visit->id }}" class="d-none" required>
                                        <input type="time" name="start_time" id="start-{{ $visit->id }}" class="d-none" required>
                                        <input type="time" name="end_time" id="end-{{ $visit->id }}" class="d-none" required>
                                        {{-- environment_id y person_in_charge_id son opcionales; si necesitas cambiarlos, haz otro flujo (modal) --}}

                                        <button type="button" class="btn btn-sm btn-warning" title="Reprogramar"
                                                onclick="
                                                    (function(){
                                                        const d = prompt('Nueva fecha (YYYY-MM-DD):', '{{ $lastSchedule->date ?? '' }}'); if(!d) return;
                                                        const s = prompt('Hora inicio (HH:MM):', '{{ $lastSchedule->start_time ?? '' }}'); if(!s) return;
                                                        const e = prompt('Hora fin (HH:MM):', '{{ $lastSchedule->end_time ?? '' }}'); if(!e) return;
                                                        document.getElementById('date-{{ $visit->id }}').value  = d;
                                                        document.getElementById('start-{{ $visit->id }}').value = s;
                                                        document.getElementById('end-{{ $visit->id }}').value   = e;
                                                        this.form.submit();
                                                    }).call(this);
                                                ">
                                            {{-- ícono arrow-clockwise --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                 fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M8 3a5 5 0 1 1-4.546 2.914.5.5 0 1 0-.908-.418A6 6 0 1 0 8 2v1z"/>
                                                <path d="M8 0a.5.5 0 0 1 .5.5v3.793l1.146-1.147a.5.5 0 0 1 .708.708L8.354 5.854a.5.5 0 0 1-.708 0L5.646 3.854a.5.5 0 1 1 .708-.708L7.5 4.293V.5A.5.5 0 0 1 8 0z"/>
                                            </svg>
                                        </button>
                                    </form>
                                    @endif

                                    {{-- Cancelar (usa el último schedule) --}}
                                    @if($scheduleId)
                                    <form action="{{ route('sigac.academic_coordination.visitschedule.cancel', $scheduleId) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="
                                            const reason = prompt('Motivo de cancelación (opcional):', '');
                                            if (reason !== null) {
                                                this.querySelector('input[name=&quot;reason&quot;]').value = reason;
                                                return confirm('Se cancelará la visita. ¿Continuar?');
                                            }
                                            return false;
                                          ">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancelar">
                                            {{-- ícono x-circle --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                 fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14"/>
                                                <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>
                                            </svg>
                                        </button>
                                    </form>
                                    @endif
                                @endif

                                {{-- Ver Excel (si existe en storage/app/...) --}}
                                @if($canViewExcel)
                                    <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute(
                                                'sigac.visits.peoplelist.view',
                                                now()->addDays(7),
                                                ['visit' => $visit->id]
                                            ) }}"
                                       class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" title="Ver Excel">
                                        {{-- ícono archivo --}}
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                             fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                            <path d="M4 0h5.5L14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2z"/>
                                            <path d="M9.5 0v4a1 1 0 0 0 1 1H14"/>
                                        </svg>
                                    </a>
                                @else
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Sin archivo">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                             fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                            <path d="M4 0h5.5L14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2z"/>
                                            <path d="M9.5 0v4a1 1 0 0 0 1 1H14"/>
                                        </svg>
                                    </button>
                                @endif
                            </td>
                        </tr>

                        {{-- Modal por solicitud --}}
                        <div class="modal fade" id="modal{{ $visit->id }}" tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                              <div class="modal-header">
                                <h5 class="modal-title">Solicitud #{{ $visit->id }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">
                                <dl class="row">
                                    <dt class="col-sm-4">Empresa</dt>
                                    <dd class="col-sm-8">{{ $visit->company->name }}</dd>

                                    <dt class="col-sm-4">Contacto</dt>
                                    <dd class="col-sm-8">{{ $visit->contact_name ?? '—' }}</dd>

                                    <dt class="col-sm-4">Correo</dt>
                                    <dd class="col-sm-8">{{ $visit->contact_email ?? '—' }}</dd>

                                    <dt class="col-sm-4">Teléfono</dt>
                                    <dd class="col-sm-8">{{ $visit->contact_phone ?? '—' }}</dd>

                                    <dt class="col-sm-4">Tipo</dt>
                                    <dd class="col-sm-8 text-capitalize">{{ $visit->type }}</dd>

                                    @if($visit->type === 'practica')
                                        <dt class="col-sm-4">Requerimientos</dt>
                                        <dd class="col-sm-8">{{ $visit->practice_requirements ?? '—' }}</dd>
                                    @endif

                                    <dt class="col-sm-4">Observaciones</dt>
                                    <dd class="col-sm-8">{{ $visit->observations ?? '—' }}</dd>

                                    <dt class="col-sm-4">Estado</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-{{ $visit->state === 'Agendada' ? 'success' : ($visit->state === 'Cancelada' ? 'danger' : 'secondary') }}">
                                            {{ $visit->state }}
                                        </span>
                                    </dd>
                                </dl>
                              </div>
                              <div class="modal-footer d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>

                                @if($visit->state === 'Agendada' && Route::has('sigac.academic_coordination.visitrequest.notify'))
                                    <form action="{{ route('sigac.academic_coordination.visitrequest.notify', $visit->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Se enviará la notificación por correo. ¿Continuar?');">
                                        @csrf
                                        <button type="submit" class="btn btn-success"
                                                {{ $hasEmail ? '' : 'disabled' }}
                                                title="{{ $hasEmail ? 'Notificar contacto' : 'No hay correo de contacto' }}">
                                            Notificar
                                        </button>
                                    </form>
                                @endif
                              </div>
                            </div>
                          </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center">No hay solicitudes registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
