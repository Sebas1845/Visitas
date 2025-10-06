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
                        <th style="width: 260px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($visitRequests as $visit)
                        @php
                            $hasEmail = filled($visit->contact_email);
                        @endphp
                        <tr>
                            <td>{{ $visit->id }}</td>
                            <td>{{ $visit->company->name }}</td>
                            <td>{{ $visit->company->nit ?? '—' }}</td>
                            <td>{{ $visit->person->full_name ?? '—' }}</td>
                            <td>{{ $visit->date_received }}</td>
                            <td>{{ ucfirst($visit->type) }}</td>
                            <td>
                                <span class="badge bg-{{ $visit->state === 'Agendada' ? 'success' : 'secondary' }}">
                                    {{ $visit->state }}
                                </span>
                            </td>
                            <td>{{ $visit->number_of_people ?? '—' }}</td>
                            <td class="d-flex gap-2">
                                {{-- Ver modal --}}
                                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#modal{{ $visit->id }}">
                                    Ver
                                </button>

                                @if($visit->state === 'Sin agendar')
                                    {{-- Agendar --}}
                                    <a href="{{ route('sigac.academic_coordination.visitschedule.create', ['request' => $visit->id]) }}"
                                       class="btn btn-sm btn-primary">
                                        Agendar
                                    </a>
                                @else
                                    {{-- Notificar (solo si Agendada) --}}
                                    <form action="{{ route('sigac.academic_coordination.visitrequest.notify', $visit->id) }}"
                                          method="POST" onsubmit="return confirm('Se enviará la notificación por correo. ¿Continuar?');">
                                        @csrf
                                        <button type="submit"
                                                class="btn btn-sm btn-success"
                                                {{ $hasEmail ? '' : 'disabled' }}
                                                title="{{ $hasEmail ? 'Enviar notificación por correo' : 'No hay correo de contacto' }}">
                                            Notificar
                                        </button>
                                    </form>
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
                                        <span class="badge bg-{{ $visit->state === 'Agendada' ? 'success' : 'secondary' }}">
                                            {{ $visit->state }}
                                        </span>
                                    </dd>
                                </dl>
                              </div>
                              <div class="modal-footer d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>

                                @if($visit->state === 'Agendada')
                                    <form action="{{ route('sigac.academic_coordination.visitrequest.notify', $visit->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Se enviará la notificación por correo. ¿Continuar?');">
                                        @csrf
                                        <button type="submit"
                                                class="btn btn-success"
                                                {{ $hasEmail ? '' : 'disabled' }}
                                                title="{{ $hasEmail ? 'Enviar notificación por correo' : 'No hay correo de contacto' }}">
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
