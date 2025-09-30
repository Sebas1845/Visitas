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
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Empresa</th>
                        <th>NIT</th>
                        <th>Solicitante</th>
                        <th>Fecha de recepción</th>
                        <th>Estado</th>
                        <th>Nº personas</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($visitRequests as $visit)
                        <tr>
                            <td>{{ $visit->id }}</td>
                            <td>{{ $visit->company->name }}</td>
                            <td>{{ $visit->company->nit ?? '—' }}</td>
                            <td>{{ $visit->person->first_name }} {{ $visit->person->first_last_name }} {{ $visit->person->second_last_name }}</td>
                            <td>{{ $visit->date_received }}</td>
                            <td>{{ $visit->state }}</td>
                            <td>{{ $visit->number_of_people ?? '—' }}</td>
                            <td>
                                @if($visit->state === 'Sin agendar')
                                    <a href="{{ route('sigac.academic_coordination.visitschedule.create', ['request' => $visit->id]) }}"
                                       class="btn btn-sm btn-primary">
                                        Agendar
                                    </a>
                                @else
                                    <span class="badge bg-success">Agendada</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center">No hay solicitudes registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
