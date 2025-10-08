@extends('sigac::layouts.master')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2>Agendar visita (Solicitud #{{ $request->id }})</h2>
        </div>

        <div class="card-body">
            {!! Form::open([
                'route' => 'sigac.academic_coordination.visitschedule.store',
                'method' => 'POST',
                'id' => 'visit-form',
            ]) !!}
            @csrf

            {{-- ID de la solicitud --}}
            {!! Form::hidden('visit_request_id', $request->id) !!}

            <div class="row">
                {{-- Encargado (unificado: empleados + contratistas) --}}
                <div class="mb-3">
                    <label class="form-label">Encargado</label>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select id="staffType" class="form-select">
                                <option value="all">Todos</option>
                                <option value="employee">Planta</option>
                                <option value="contractor">Contratista</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <input type="text" id="staffSearch" class="form-control"
                                placeholder="Buscar por nombre o apellido..." autocomplete="off">
                            <input type="hidden" name="person_in_charge_id" id="personInChargeId">
                            <div id="staffResults" class="list-group mt-2"></div>
                        </div>
                    </div>
                    <div class="form-text">Escribe al menos 2 caracteres y selecciona una persona.</div>
                </div>

                <div class="col-6 mb-3">
                    {!! Form::label('activity', 'Actividad a realizar') !!}
                    {!! Form::text('activity', null, [
                        'class' => 'form-control',
                        'list' => 'activities',
                        'required',
                        'id' => 'activity',
                    ]) !!}
                    <datalist id="activities">
                        @foreach ($activities as $activity)
                            <option value="{{ $activity }}"></option>
                        @endforeach
                    </datalist>
                </div>
            </div>

            <div class="row">
                @php
                    $minDate = \Carbon\Carbon::today('America/Bogota')->addDays(5)->toDateString();
                @endphp

                <div class="col-4 mb-3">
                    {!! Form::label('date', 'Fecha') !!}
                    {!! Form::date('date', $minDate, [
                        'class' => 'form-control',
                        'id' => 'date',
                        'required' => true,
                        'min' => $minDate, // 👈 mínimo en el input
                    ]) !!}
                    <small class="text-muted">Solo se permite agendar a partir de {{ $minDate }} (5 días desde
                        hoy).</small>
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

            {{-- Ambiente (opcional) --}}
            <div class="form-group mb-2">
                {!! Form::label('environment_id', 'Ambiente (opcional)') !!}
                {!! Form::select('environment_id', [], null, [
                    'class' => 'form-control',
                    'id' => 'environment_id',
                    'placeholder' => 'Seleccione fecha y horas para cargar ambientes...',
                    'disabled' => true,
                ]) !!}
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="no_env" checked>
                    <label class="form-check-label" for="no_env">No asignar ambiente por ahora</label>
                </div>
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
        // ===================== Encargado (buscador unificado) =====================
        (function() {
            const search = document.getElementById('staffSearch');
            const typeSel = document.getElementById('staffType');
            const results = document.getElementById('staffResults');
            const hiddenId = document.getElementById('personInChargeId');

            let timer;

            function doSearch() {
                const q = search.value.trim();
                const type = typeSel.value;
                if (q.length < 2) {
                    results.innerHTML = '';
                    return;
                }

                fetch(
                        `{{ route('sigac.academic_coordination.visit.staff.search') }}?q=${encodeURIComponent(q)}&type=${encodeURIComponent(type)}`
                        )
                    .then(r => r.json())
                    .then(items => {
                        results.innerHTML = items.map(it => `
                          <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-id="${it.person_id}" data-name="${it.name}" data-type="${it.type}">
                            <span>${it.name}</span>
                            <span class="badge ${it.type === 'employee' ? 'bg-success' : 'bg-warning text-dark'}">
                              ${it.type === 'employee' ? 'Planta' : 'Contratista'}
                            </span>
                          </button>
                        `).join('');

                        results.querySelectorAll('button').forEach(btn => {
                            btn.addEventListener('click', () => {
                                hiddenId.value = btn.dataset.id; // people.id
                                const label =
                                    `${btn.dataset.name} ${btn.dataset.type==='employee'?'(Planta)':'(Contratista)'}`;
                                search.value = label;
                                results.innerHTML = '';
                                validateReady();
                            });
                        });
                    })
                    .catch(() => {
                        results.innerHTML = '';
                    });
            }

            search.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(doSearch, 250);
            });
            typeSel.addEventListener('change', doSearch);
        })();


        // ===================== Ambientes (OPCIONAL) + Validación de envío =====================
        (function() {
            const date = document.getElementById('date');
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            const envSelect = document.getElementById('environment_id');
            const noEnvCB = document.getElementById('no_env');
            const submitBtn = document.getElementById('submitBtn');
            const helper = document.getElementById('env-helper');
            const activity = document.getElementById('activity');
            const form = document.getElementById('visit-form');

            // CSRF para fetch (fallback a input oculto si no hay meta)
            let token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!token) {
                const hidden = document.querySelector('input[name="_token"]');
                if (hidden) token = hidden.value;
            }
            const url = "{{ route('sigac.academic_coordination.visit.environments.search') }}";

            function validateReady() {
                // Requisitos mínimos para permitir enviar
                const baseOk = activity.value.trim() && date.value && startTime.value && endTime.value && (startTime
                    .value < endTime.value);
                const envOk = noEnvCB.checked ? true : !!envSelect.value;

                submitBtn.disabled = !(baseOk && envOk);
            }

            function toggleEnvDisabled() {
                if (noEnvCB.checked) {
                    envSelect.value = '';
                    envSelect.setAttribute('disabled', 'disabled');
                    helper.textContent = 'El ambiente se puede asignar después.';
                } else {
                    envSelect.removeAttribute('disabled');
                    helper.textContent = 'Seleccione un ambiente disponible (o marque "No asignar" para omitir).';
                    // Si ya hay fecha/horas, actualiza lista
                    if (date.value && startTime.value && endTime.value && startTime.value < endTime.value) {
                        fetchEnvironments();
                    }
                }
                validateReady();
            }

            function setLoading(state) {
                if (state) {
                    envSelect.innerHTML = '';
                    envSelect.disabled = true;
                    helper.textContent = 'Cargando ambientes disponibles...';
                } else if (!noEnvCB.checked) {
                    envSelect.disabled = false;
                    helper.textContent = 'Seleccione un ambiente disponible (o marque "No asignar" para omitir).';
                }
            }

            function setEmpty(msg = 'No hay ambientes disponibles para el rango seleccionado.') {
                envSelect.innerHTML = '';
                const optNone = document.createElement('option');
                optNone.value = '';
                optNone.textContent = '(Asignar después)';
                envSelect.appendChild(optNone);
                // no deshabilites si noEnvCB está apagado; deja elegir "(Asignar después)"
                if (!noEnvCB.checked) envSelect.disabled = false;
                helper.textContent = msg + ' También puedes dejar "(Asignar después)".';
                validateReady();
            }

            async function fetchEnvironments() {
                // Si marcaron "No asignar", no consultes
                if (noEnvCB.checked) {
                    validateReady();
                    return;
                }

                const d = date.value,
                    s = startTime.value,
                    e = endTime.value;

                if (!d || !s || !e) {
                    setEmpty('Seleccione fecha y horas para consultar.');
                    return validateReady();
                }
                if (s >= e) {
                    setEmpty('La hora de inicio debe ser menor a la hora de fin.');
                    return validateReady();
                }

                setLoading(true);

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token
                        },
                        body: JSON.stringify({
                            date: d,
                            start_time: s,
                            end_time: e
                        })
                    });

                    if (!res.ok) throw new Error('Error de servidor ' + res.status);

                    const data = await res.json(); // [{id, name}, ...]
                    envSelect.innerHTML = '';

                    // Siempre ofrece "(Asignar después)"
                    const optNone = document.createElement('option');
                    optNone.value = '';
                    optNone.textContent = '(Asignar después)';
                    envSelect.appendChild(optNone);

                    if (Array.isArray(data) && data.length) {
                        data.forEach(env => {
                            const opt = document.createElement('option');
                            opt.value = env.id;
                            opt.textContent = env.name;
                            envSelect.appendChild(opt);
                        });
                        envSelect.disabled = false;
                        helper.textContent =
                            'Ambientes libres para la fecha y rango. También puedes dejar "(Asignar después)".';
                    } else {
                        helper.textContent = 'No hay ambientes libres. Puedes dejar "(Asignar después)".';
                        envSelect.disabled = false;
                    }
                } catch (err) {
                    console.error(err);
                    setEmpty('No se pudieron cargar los ambientes. Puedes dejar "(Asignar después)".');
                } finally {
                    setLoading(false);
                    validateReady();
                }
            }

            // Eventos
            [date, startTime, endTime, activity].forEach(el => el.addEventListener('change', () => {
                fetchEnvironments();
                validateReady();
            }));
            envSelect.addEventListener('change', validateReady);
            noEnvCB.addEventListener('change', toggleEnvDisabled);

            // Validación también al enviar
            form.addEventListener('submit', (e) => {
                if (submitBtn.disabled) e.preventDefault();
            });

            // Estado inicial
            toggleEnvDisabled();
            validateReady();
        })();

        (function() {
            const date = document.getElementById('date');
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            const envSelect = document.getElementById('environment_id');
            const noEnvCB = document.getElementById('no_env');
            const submitBtn = document.getElementById('submitBtn');
            const helper = document.getElementById('env-helper');
            const activity = document.getElementById('activity');
            const form = document.getElementById('visit-form');

            // 👇 mínimo: hoy + 5 días (desde el servidor)
            const MIN_DATE = '{{ $minDate }}';

            function isDateValidMin() {
                return date.value && date.value >= MIN_DATE;
            }

            function validateReady() {
                const baseOk = activity.value.trim() &&
                    date.value &&
                    isDateValidMin() &&
                    startTime.value && endTime.value &&
                    (startTime.value < endTime.value);

                const envOk = noEnvCB.checked ? true : !!envSelect.value;

                submitBtn.disabled = !(baseOk && envOk);
            }

            // Si el usuario intenta poner una fecha menor, la corrige al mínimo
            date.addEventListener('change', () => {
                if (date.value && date.value < MIN_DATE) {
                    date.value = MIN_DATE;
                }
                validateReady();
            });

            // ... tu resto (fetch de ambientes, toggle checkbox, etc.)
        })();
    </script>
@endsection
