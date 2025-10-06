<p>Hola,</p>

<p>Tu visita ha sido agendada:</p>

<ul>
    <li><strong>Empresa:</strong> {{ optional($visitRequest->company)->name }}</li>
    <li><strong>Fecha:</strong> {{ $schedule->date }}</li>
    <li><strong>Hora:</strong> {{ $schedule->start_time }} - {{ $schedule->end_time }}</li>
    <li><strong>Actividad:</strong> {{ $schedule->activity }}</li>
    <li><strong>Ambiente:</strong> {{ optional($schedule->environment)->name ?? 'SENA' }}</li>
</ul>

<p>
    👉 Puedes <a href="{{ route('sigac.academic_coordination.visitschedule.ics', ['schedule' => $schedule->id]) }}">descargar la invitación (.ics)</a> y abrirla con tu calendario.<br>
    👉 O añadirlo en Google Calendar con un click: <a href="{{ $googleAddUrl }}" target="_blank">Añadir a Google Calendar</a>
</p>

<p>¡Gracias!</p>
