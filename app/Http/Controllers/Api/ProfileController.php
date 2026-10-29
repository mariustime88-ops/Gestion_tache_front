<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => ['required_with:password', 'current_password'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $previousPhotoPath = $user->profile_photo_path;

        $user->name = $data['name'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('profile-photos', 'public');

            if (! is_string($photoPath)) {
                throw new RuntimeException('La photo de profil n’a pas pu être enregistrée.');
            }

            $user->profile_photo_path = $photoPath;
        }

        $user->save();

        if ($previousPhotoPath && $previousPhotoPath !== $user->profile_photo_path) {
            Storage::disk('public')->delete($previousPhotoPath);
        }

        return response()->json([
            'message' => 'Votre profil a été mis à jour.',
            'data' => ['user' => $user->refresh()],
        ]);
    }
}
