<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Support\IcsBuilder;
use Carbon\Carbon;

class VisitUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $visitRequest;
    public $schedule;
    public $event;      // 'created' | 'updated' | 'canceled'
    public $changed;    // array con detalles de cambios
    public $googleAddUrl;
    public $icsContent;
    public $invitationUrl;

    public function __construct($visitRequest, $schedule, string $event, array $changed = [])
    {
        $this->visitRequest = $visitRequest;
        $this->schedule     = $schedule;
        $this->event        = $event;
        $this->changed      = $changed;

        $summary = 'Visita - ' . optional($visitRequest->company)->name;
        $description = "Actividad: {$schedule->activity}\n"
             . "Encargado: " . (optional($schedule->personInCharge)->first_name ?? 'N/D') . "\n"
             . "Observaciones: " . ($schedule->observations ?? '—');
        $location = optional($schedule->environment)->name ?? 'Por definir';

        // .ICS adjunto
        $this->icsContent = IcsBuilder::singleEvent([
            'uid'         => "visit-{$schedule->id}@sicefa.local",
            'summary'     => $summary,
            'description' => $description,
            'location'    => $location,
            'start'       => "{$schedule->date} {$schedule->start_time}",
            'end'         => "{$schedule->date} {$schedule->end_time}",
            'organizer'   => config('mail.from.address'),
            'attendees'   => array_filter([$visitRequest->contact_email ?? null]),
        ]);

        // Google Calendar (opcional)
        $startUtc = Carbon::parse("{$schedule->date} {$schedule->start_time}", 'America/Bogota')->utc()->format('Ymd\THis\Z');
        $endUtc   = Carbon::parse("{$schedule->date} {$schedule->end_time}", 'America/Bogota')->utc()->format('Ymd\THis\Z');
        $this->googleAddUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . urlencode($summary)
            . '&dates=' . $startUtc . '/' . $endUtc
            . '&details=' . urlencode($description)
            . '&location=' . urlencode($location)
            . '&sf=true&output=xml';

        // Link a invitación (tu vista pública de invitación)
        $this->invitationUrl = route('sigac.visits.invitation', $schedule);
    }

    public function build()
    {
        $subject = match ($this->event) {
            'created'  => 'Visita agendada',
            'updated'  => 'Visita actualizada',
            'canceled' => 'Visita cancelada',
            default    => 'Actualización de visita',
        } . ' - ' . (optional($this->visitRequest->company)->name ?? 'SICEFA');

        return $this->subject($subject)
            ->view('emails.visit_update')
            ->attachData($this->icsContent, "visita-{$this->schedule->id}.ics", [
                'mime' => 'text/calendar; charset=utf-8',
            ]);
    }
}
