<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Dipakai untuk data milik user itu sendiri. Untuk anggota lain di sebuah sesi, CircleResource
// hanya membuka id dan nama (email partner tidak perlu terlihat).
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
