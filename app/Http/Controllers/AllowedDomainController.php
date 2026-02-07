<?php

namespace App\Http\Controllers;

use App\Models\AllowedDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AllowedDomainController extends Controller
{
    /**
     * Listar todos los dominios permitidos
     */
    public function index()
    {
        $domains = AllowedDomain::with('empresa')->get();
        
        return response()->json([
            'domains' => $domains,
            'total' => $domains->count()
        ]);
    }

    /**
     * Crear un nuevo dominio permitido
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'domain' => 'required|string|unique:allowed_domains,domain',
            'tenant_id' => 'required|string',
            'tenant_name' => 'required|string|max:255',
            'id_empresa' => 'nullable|exists:ent_empresas,id',
            'activo' => 'boolean',
            'descripcion' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Asegurar que el dominio tenga @
        $domain = $request->domain;
        if (!str_starts_with($domain, '@')) {
            $domain = '@' . $domain;
        }

        $allowedDomain = AllowedDomain::create([
            'domain' => $domain,
            'tenant_id' => $request->tenant_id,
            'tenant_name' => $request->tenant_name,
            'id_empresa' => $request->id_empresa,
            'activo' => $request->activo ?? true,
            'descripcion' => $request->descripcion
        ]);

        return response()->json([
            'message' => 'Dominio permitido creado exitosamente',
            'domain' => $allowedDomain
        ], 201);
    }

    /**
     * Actualizar un dominio permitido
     */
    public function update(Request $request, $id)
    {
        $domain = AllowedDomain::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'domain' => 'sometimes|required|string|unique:allowed_domains,domain,' . $id,
            'tenant_id' => 'sometimes|required|string',
            'tenant_name' => 'sometimes|required|string|max:255',
            'id_empresa' => 'nullable|exists:ent_empresas,id',
            'activo' => 'boolean',
            'descripcion' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only(['tenant_id', 'tenant_name', 'id_empresa', 'activo', 'descripcion']);
        
        if ($request->has('domain')) {
            $domainValue = $request->domain;
            if (!str_starts_with($domainValue, '@')) {
                $domainValue = '@' . $domainValue;
            }
            $updateData['domain'] = $domainValue;
        }

        $domain->update($updateData);

        return response()->json([
            'message' => 'Dominio permitido actualizado exitosamente',
            'domain' => $domain->fresh()
        ]);
    }

    /**
     * Eliminar un dominio permitido
     */
    public function destroy($id)
    {
        $domain = AllowedDomain::findOrFail($id);
        $domain->delete();

        return response()->json([
            'message' => 'Dominio permitido eliminado exitosamente'
        ]);
    }

    /**
     * Verificar si un email está permitido
     */
    public function checkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        $domain = '@' . substr(strrchr($email, "@"), 1);
        
        $allowedDomain = AllowedDomain::getByEmail($email);
        $isAllowed = AllowedDomain::isEmailAllowed($email);

        return response()->json([
            'email' => $email,
            'domain' => $domain,
            'is_allowed' => $isAllowed,
            'domain_info' => $allowedDomain ? [
                'id' => $allowedDomain->id,
                'tenant_name' => $allowedDomain->tenant_name,
                'tenant_id' => $allowedDomain->tenant_id,
                'empresa' => $allowedDomain->empresa ? $allowedDomain->empresa->nombre : null,
                'descripcion' => $allowedDomain->descripcion
            ] : null,
            'message' => $isAllowed 
                ? "✅ El dominio {$domain} está permitido" 
                : "❌ El dominio {$domain} NO está permitido"
        ], $isAllowed ? 200 : 403);
    }

    /**
     * Activar/Desactivar un dominio
     */
    public function toggleStatus($id)
    {
        $domain = AllowedDomain::findOrFail($id);
        $domain->activo = !$domain->activo;
        $domain->save();

        $status = $domain->activo ? 'activado' : 'desactivado';

        return response()->json([
            'message' => "Dominio {$status} exitosamente",
            'domain' => $domain
        ]);
    }
}