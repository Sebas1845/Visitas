@extends('sigac::layouts.master')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2>Agendar visita (Solicitud #{{ $request->id }})</h2>
        </div>

        <div class="card-body">
            {!! Form::open(['route' => 'sigac.academic_coordination.visitschedule.store', 'method' => 'POST', 'id' => 'visit-form']) !!}
            @csrf

            {{-- ID de la solicitud --}}
            {!! Form::hidden('visit_request_id', $request->id) !!}

            <div class="row">
                <div class="col-6 mb-3">
                    {!! Form::label('person_in_charge_id', 'Encargado') !!}
                    {!! Form::select('person_in_charge_id', $persons, null, [
                        'class' => 'form-control',
                        'placeholder' => 'Seleccione...',
                    ]) !!}
                </div>

                <div class="col-6 mb-3">
                    {!! Form::label('activity', 'Actividad a realizar') !!}
                    {!! Form::text('activity', null, ['class' => 'form-control', 'list' => 'activities', 'required']) !!}

                    <datalist id="activities">
                        @foreach ($activities as $activity)
                            <option value="{{ $activity }}"></option>
                        @endforeach
                    </datalist>
                </div>
            </div>

            <div class="row">
                <div class="col-4 mb-3">
                    {!! Form::label('date', 'Fecha') !!}
                    {!! Form::date('date', \Carbon\Carbon::now(), ['class' => 'form-control', 'id' => 'date']) !!}
                </div>

                <div class="col-4 mb-3">
                    {!! Form::label('start_time', 'Hora de inicio') !!}
                    {!! Form::time('start_time', null, ['class' => 'form-control', 'id' => 'start_time', 'required']) !!}
                </div>

                <div class="col-4 mb-3">
                    {!! Form::label('end_time', 'Hora de fin') !!}
                    {!! Form::time('end_time', null, ['class' => 'form-control', 'id' => 'end_time', 'required']) !!}
                </div>
            </div>

            <div class="form-group mb-2">
                {!! Form::label('environment_id', 'Ambiente disponible') !!}
                {!! Form::select('environment_id', [], null, [
                    'class' => 'form-control',
                    'id' => 'environment_id',
                    'placeholder' => 'Seleccione fecha y horas para cargar ambientes...',
                    'disabled' => true,
                ]) !!}
                <small id="env-helper" class="form-text text-muted"></small>
            </div>

            <div class="mb-3">
                {!! Form::label('observations', 'Observaciones') !!}
                {!! Form::textarea('observations', null, ['class' => 'form-control', 'rows' => 3]) !!}
            </div>

            <div class="d-flex justify-content-end">
                <button id="submitBtn" type="submit" class="btn btn-primary" disabled>Agendar</button>
            </div>

            {!! Form::close() !!}
        </div>
    </div>

    <script>
        (function () {
            const date      = document.getElementById('date');
            const startTime = document.getElementById('start_time');
            const endTime   = document.getElementById('end_time');
            const envSelect = document.getElementById('environment_id');
            const submitBtn = document.getElementById('submitBtn');
            const helper    = document.getElementById('env-helper');
            const token     = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            const url = "{{ route('sigac.academic_coordination.visit.environments.search') }}";

            function setLoading(state) {
                if (state) {
                    envSelect.innerHTML = '';
                    envSelect.disabled = true;
                    submitBtn.disabled = true;
                    helper.textContent = 'Cargando ambientes disponibles...';
                } else {
                    helper.textContent = '';
                }
            }

            function setEmpty(msg = 'No hay ambientes disponibles para el rango seleccionado.') {
                envSelect.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '-- ' + msg + ' --';
                envSelect.appendChild(opt);
                envSelect.value = '';
                envSelect.disabled = true;
                submitBtn.disabled = true;
                helper.textContent = msg;
            }

            async function fetchEnvironments() {
                const d = date.value;
                const s = startTime.value;
                const e = endTime.value;

                // Validaciones mínimas antes de consultar
                if (!d || !s || !e) {
                    setEmpty('Seleccione fecha y horas para consultar.');
                    return;
                }
                if (s >= e) {
                    setEmpty('La hora de inicio debe ser menor a la hora de fin.');
                    return;
                }

                setLoading(true);

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token
                        },
                        body: JSON.stringify({ date: d, start_time: s, end_time: e })
                    });

                    if (!res.ok) {
                        throw new Error('Error de servidor ' + res.status);
                    }

                    const data = await res.json(); // [{id, name}, ...]
                    envSelect.innerHTML = '';

                    if (!Array.isArray(data) || data.length === 0) {
                        setEmpty();
                        return;
                    }

                    // placeholder
                    const ph = document.createElement('option');
                    ph.value = '';
                    ph.textContent = 'Seleccione un ambiente disponible...';
                    envSelect.appendChild(ph);

                    data.forEach(env => {
                        const opt = document.createElement('option');
                        opt.value = env.id;
                        opt.textContent = env.name;
                        envSelect.appendChild(opt);
                    });

                    envSelect.disabled = false;
                    submitBtn.disabled = true; // hasta que elija uno
                    helper.textContent = 'Solo se listan ambientes libres para la fecha y el rango horario indicados.';
                } catch (err) {
                    console.error(err);
                    setEmpty('No se pudieron cargar los ambientes. Intente de nuevo.');
                } finally {
                    setLoading(false);
                }
            }

            // Cambios que disparan la búsqueda
            [date, startTime, endTime].forEach(el => el.addEventListener('change', fetchEnvironments));

            // Habilitar submit solo si hay selección válida
            envSelect.addEventListener('change', () => {
                submitBtn.disabled = !(envSelect.value && envSelect.value !== '');
            });

            // Consulta inicial (opcional): si tienes ya valores pre-cargados
            // fetchEnvironments();
        })();
    </script>
@endsection
