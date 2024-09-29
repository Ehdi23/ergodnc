<?php

namespace App\Http\Resources;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->user),
            'images' => ImageResource::collection($this->whenLoaded('images')),
            'tags' => TagResource::make($this->tags),
            $this->merge(Arr::except(parent::toArray($request), ['user_id', 'created_At', 'updated_at', 'deleted_at']))
        ];
    }
}
