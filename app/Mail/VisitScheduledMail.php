<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Support\IcsBuilder;

class VisitScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public $visitRequest;
    public $schedule;
    public $googleAddUrl;
    public $icsContent;

    public function __construct($visitRequest, $schedule)
    {
        $this->visitRequest = $visitRequest;
        $this->schedule     = $schedule;

        $summary = 'Visita - ' . optional($visitRequest->company)->name;
        $description = "Actividad: {$schedule->activity}\n"
                     . "Encargado: " . optional($visitRequest->person)->first_name . "\n"
                     . "Observaciones: " . ($schedule->observations ?? '—');
        $location = optional($schedule->environment)->name ?? 'SENA';

        // .ics inline (para adjuntar o linkear)
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

        // URL “Añadir a Google Calendar” (sin API, público)
        $startUtc = \Carbon\Carbon::parse("{$schedule->date} {$schedule->start_time}", 'America/Bogota')->utc()->format('Ymd\THis\Z');
        $endUtc   = \Carbon\Carbon::parse("{$schedule->date} {$schedule->end_time}", 'America/Bogota')->utc()->format('Ymd\THis\Z');

        $this->googleAddUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . urlencode($summary)
            . '&dates=' . $startUtc . '/' . $endUtc
            . '&details=' . urlencode($description)
            . '&location=' . urlencode($location)
            . '&sf=true&output=xml';
    }

    public function build()
    {
        $filename = "visita-{$this->schedule->id}.ics";

        return $this->subject('Visita agendada - ' . optional($this->visitRequest->company)->name)
            ->view('emails.visit_scheduled')
            ->attachData($this->icsContent, $filename, [
                'mime' => 'text/calendar; charset=utf-8',
            ]);
    }
}
