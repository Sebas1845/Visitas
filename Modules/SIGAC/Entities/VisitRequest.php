<?php

namespace Modules\SIGAC\Entities;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\SICA\Entities\Person;
use app\Models\User;

/**
 * Modelo VisitRequest
 *
 * Gestiona las solicitudes de visita realizadas por instructores, coordinación o bienestar.
 */
class VisitRequest extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, HasFactory;

    protected $table = 'visit_requests';

    protected $fillable = [
        'company_id',
        'person_id',
        'user_id',
        'date_received',
        'response_date',
        'response_method',
        'state',
        'number_of_people',
        'people_list_path',
        'attachments_path',
        'observations',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Relación con la empresa
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relación con la persona que realiza la solicitud
     */
    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Relación con el usuario que registra la solicitud
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con las agendas de la visita
     */
    public function schedules()
    {
        return $this->hasMany(VisitSchedule::class);
    }
}
