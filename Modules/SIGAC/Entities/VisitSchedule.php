<?php

namespace Modules\SIGAC\Entities;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\SICA\Entities\Person;
use Modules\SICA\Entities\Environment;

/**
 * Modelo VisitSchedule
 *
 * Almacena la agenda programada de una solicitud de visita.
 */
class VisitSchedule extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, HasFactory;

    protected $table = 'visit_schedules';

    protected $fillable = [
        'visit_request_id',
        'person_in_charge_id',
        'activity',
        'date',
        'start_time',
        'end_time',
        'environment_id',
        'observations',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Relación con la solicitud de visita
     */
    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }

    /**
     * Relación con la persona encargada de la visita
     */
    public function person()
    {
        return $this->belongsTo(Person::class, 'person_in_charge_id');
    }

    /**
     * Relación con el ambiente
     */
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
}
