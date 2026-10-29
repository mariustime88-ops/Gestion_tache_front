<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        throw ValidationException::withMessages([
            'email' => ['La création de comptes est fermée. La plateforme n’accepte qu’un seul compte administrateur IT.'],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($credentials['email'])))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Ces identifiants ne correspondent à aucun compte.'],
            ]);
        }

        if ($user->profile_status !== 'active') {
            $isAdministrator = $user->role === 'admin';

            return response()->json([
                'message' => match ($user->profile_status) {
                    'rejected' => $isAdministrator
                        ? 'Votre compte administrateur a été refusé.'
                        : 'Votre demande de compte a été refusée.',
                    'suspended' => $isAdministrator
                        ? 'Votre compte administrateur est suspendu. Contactez l’administration informatique.'
                        : 'Votre compte est suspendu. Contactez l’administration informatique.',
                    default => $isAdministrator
                        ? 'Votre compte administrateur attend l’activation de la plateforme.'
                        : 'Votre compte attend l’activation de l’administration informatique.',
                },
                'account_status' => $user->profile_status,
            ], 403);
        }

        return response()->json([
            'data' => [
                'user' => $user,
                'token' => $user->createToken('angular-client')->plainTextToken,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Vous êtes déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['user' => $request->user()]]);
    }
}
