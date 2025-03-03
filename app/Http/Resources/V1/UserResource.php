<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "email" => $this->whenLoaded('email'),
            "photoUrl" => $this->photo_url != null ? str_replace("private", env("APP_URL"), $this->photo_url) : "https://images.unsplash.com/photo-1529665253569-6d01c0eaf7b6?q=80&w=3185&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D",
            "phoneNumber" => $this->whenLoaded('phone_number'),
            "role" => new RoleResource($this->whenLoaded('role')),
            // "status" => $this->whenLoaded('status'),
            // "balance" => $this->balance,
            "employees" => new UserCollection($this->whenLoaded('employees')),
        ];
    }
}
