<?php
namespace Modules\SIGAC\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\SIGAC\Entities\VisitRequest;
use Modules\SIGAC\Entities\Company;

class VisitRequestController extends Controller
{
    /**
     * Mostrar formulario para crear una solicitud de visita.
     */
    public function application_index()
    {
        $visitRequests = VisitRequest::with(['company', 'person'])
            ->orderBy('id', 'desc')
            ->get();

        return view('sigac::visitrequest.index', [
            'visitRequests' => $visitRequests,
            'titlePage'     => 'Solicitudes de visita',
            'titleView'     => 'Solicitudes de visita',
        ]);
    }
    public function application_create()
    {
        // Obtener la colección completa de empresas para poder usar sus atributos en la vista
        $companies = Company::all();

        return view('sigac::visitrequest.create', [
            'companies' => $companies,
            'titlePage' => 'Crear solicitud de visita',
            'titleView' => 'Crear solicitud de visita',
        ]);
    }

    /**
     * Almacenar la solicitud de visita.
     */
    public function application_store(Request $request)
    {
        // Validar los datos ingresados
        $validated = $request->validate([
            'company_name'          => 'required|string',
            'company_nit'           => 'nullable|string',
            'company_contact_name'  => 'nullable|string',
            'company_contact_phone' => 'nullable|string',
            'company_contact_email' => 'nullable|email',
            'company_address'       => 'nullable|string',
            'date_received'         => 'nullable|date',
            'response_date'         => 'nullable|date',
            'response_method'       => 'nullable|string',
            'number_of_people'      => 'nullable|integer|min:1',
            'people_list'           => 'nullable|file|mimes:xlsx,csv',
            'attachments.*'         => 'nullable|file',
            'observations'          => 'nullable|string',
        ]);

        // Buscar o crear la empresa según el nombre y completar sus datos
        $company = Company::firstOrCreate(
            ['name' => $validated['company_name']],
            [
                'nit'          => $validated['company_nit'] ?? null,
                'contact_name' => $validated['company_contact_name'] ?? null,
                'contact_phone'=> $validated['company_contact_phone'] ?? null,
                'contact_email'=> $validated['company_contact_email'] ?? null,
                'address'      => $validated['company_address'] ?? null,
            ]
        );
        // Si ya existía, actualizar los campos que lleguen en la solicitud
        $company->nit           = $validated['company_nit'] ?? $company->nit;
        $company->contact_name  = $validated['company_contact_name'] ?? $company->contact_name;
        $company->contact_phone = $validated['company_contact_phone'] ?? $company->contact_phone;
        $company->contact_email = $validated['company_contact_email'] ?? $company->contact_email;
        $company->address       = $validated['company_address'] ?? $company->address;
        $company->save();

        // Crear nueva solicitud vinculada a la empresa
        $user = Auth::user();
        $visitRequest = new VisitRequest();
        $visitRequest->company_id       = $company->id;                  // ← usar el ID de $company
        $visitRequest->person_id        = $user->person->id;
        $visitRequest->user_id          = $user->id;
        $visitRequest->date_received    = $validated['date_received'] ?? now()->toDateString();
        $visitRequest->response_date    = $validated['response_date'] ?? null;
        $visitRequest->response_method  = $validated['response_method'] ?? null;
        $visitRequest->state            = 'Sin agendar';
        $visitRequest->number_of_people = $validated['number_of_people'] ?? null;

        // Guardar listado de personas, si se cargó
        if ($request->hasFile('people_list')) {
            $visitRequest->people_list_path = $request->file('people_list')->store('visit_people_lists');
        }

        // Guardar documentos adjuntos, si se cargaron
        if ($request->hasFile('attachments')) {
            $paths = [];
            foreach ($request->file('attachments') as $file) {
                $paths[] = $file->store('visit_attachments');
            }
            $visitRequest->attachments_path = json_encode($paths);
        }

        $visitRequest->observations = $validated['observations'] ?? null;
        $visitRequest->save();

        return redirect()->back()->with('success', 'La solicitud de visita se ha registrado correctamente');
    }

    
}
