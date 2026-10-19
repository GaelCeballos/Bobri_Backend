<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador que se encarga de la gestión de usuarios.
 * 
 * @package App\Http\Controllers
 */
class UserController extends Controller
{
    /**
     * Petición para obtener la lista de usuarios.
     * Nota: estos solo tendra acceso los usuarios con rol "Administrador".
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $users = User::select(['id', 'name', 'email', 'rol', 'estado', 'created_at'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $users
        ]);
    }

    /**
     * Petición para crear un nuevo usuario.
     * Nota: estos solo tendra acceso los usuarios con rol "Administrador".
     * @param StoreUserRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        // Si no se envió `estado`, la base de datos pone 'Activo' por defecto,
        // pero el modelo en memoria no lo sabe; refresh() lo relee para que la
        // respuesta no lleve `estado: null`.
        $user->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario creado exitosamente.',
            'data' => $user
        ], Response::HTTP_CREATED);
    }

    /**
     * Petición para obtener un usuario específico.
     * Nota: estos solo tendra acceso los usuarios con rol "Administrador".
     * @param User $usuario
     * @return JsonResponse
     */
    public function show(User $usuario): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $usuario
        ]);
    }

    /**
     * Petición para actualizar la información de un usuario específico.
     * Nota : tanto el administrador como el propio usuario pueden actualizar su información.
     * @param UpdateUserRequest $request
     * @param User $usuario
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $usuario): JsonResponse
    {
        $data = $request->validated();

        // Si la contraseña viene vacía o nula, la removemos para no sobrescribir con hash nulo
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $usuario->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Información de usuario actualizada correctamente.',
            'data' => $usuario
        ]);
    }

    /**
     * Petición para eliminar un usuario específico.
     * nota: estos solo tendra acceso los usuarios con rol "Administrador".
     * @param User $usuario
     * @return JsonResponse
     */
    public function toggleState(User $usuario): JsonResponse
    {
        $nuevoEstado = $usuario->estado === 'Activo' ? 'Inactivo' : 'Activo';

        if ($nuevoEstado === 'Inactivo' && $usuario->current_token) {
            try {
                JWTAuth::setToken($usuario->current_token)->invalidate();
            } catch (\Exception $e) {
                // Token ya destruido
            }
            $usuario->current_token = null;
        }

        $usuario->estado = $nuevoEstado;
        $usuario->save();

        return response()->json([
            'status' => 'success',
            'message' => "Estado de usuario cambiado a: {$nuevoEstado}.",
            'data' => $usuario
        ]);
    }
}
