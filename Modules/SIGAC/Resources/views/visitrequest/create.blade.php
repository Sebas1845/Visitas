@extends('sigac::layouts.master')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2>Crear solicitud de visita</h2>
        </div>
        <div class="card-body">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            {{-- Formulario para crear la solicitud --}}
            {!! Form::open(['route' => 'sigac.academic_coordination.visitrequest.store', 'method' => 'POST', 'files' => true]) !!}
            @csrf

            <div class="row">
                {!! Form::label('company_name', 'Empresa o Institución') !!}
                {!! Form::text('company_name', old('company_name'), [
                    'class' => 'form-control',
                    'list' => 'companies-list',
                    'placeholder' => 'Escriba o seleccione...',
                    'required',
                ]) !!}
                <datalist id="companies-list">
                    @foreach ($companies as $company)
                        <option value="{{ $company->name }}"></option>
                    @endforeach
                </datalist>
                <div class="col-6 mb-3">
                    {!! Form::label('number_of_people', 'Cantidad de personas') !!}
                    {!! Form::number('number_of_people', null, ['class' => 'form-control', 'min' => 1]) !!}
                </div>
            </div>

            <div class="row">
                <div class="col-6 mb-3">
                    {!! Form::label('date_received', 'Fecha de recepción') !!}
                    {!! Form::date('date_received', \Carbon\Carbon::now(), ['class' => 'form-control']) !!}
                </div>
                <div class="col-6 mb-3">
                    {!! Form::label('response_date', 'Fecha de respuesta') !!}
                    {!! Form::date('response_date', null, ['class' => 'form-control']) !!}
                </div>
            </div>

            <div class="row">
                <div class="col-6 mb-3">
                    {!! Form::label('response_method', 'Método de respuesta') !!}
                    {!! Form::select('response_method', ['Llamada' => 'Llamada', 'Correo' => 'Correo'], null, [
                        'class' => 'form-control',
                        'placeholder' => 'Seleccione...',
                    ]) !!}
                </div>
                <div class="col-6 mb-3">
                    {!! Form::label('people_list', 'Listado de personas (Excel)') !!}
                    {!! Form::file('people_list', ['class' => 'form-control']) !!}
                </div>
            </div>

            <div class="mb-3">
                {!! Form::label('attachments', 'Documentos adjuntos') !!}
                {!! Form::file('attachments[]', ['class' => 'form-control', 'multiple']) !!}
            </div>

            <div class="mb-3">
                {!! Form::label('observations', 'Observaciones') !!}
                {!! Form::textarea('observations', null, ['class' => 'form-control', 'rows' => 3]) !!}
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Enviar solicitud</button>
            </div>

            {!! Form::close() !!}
        </div>
    </div>
@endsection
