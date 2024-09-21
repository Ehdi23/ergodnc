<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Reservation;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\OfficeResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfficeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $offices = Office::query()
            ->where('approval_status', Office::APPROVAL_APPROVED)
            ->where('hidden', false)
            // if the request contains a host_id (condition ture), invoke the closure fn ($builder) => $builder->whereUserId(request('host_id'))
            ->when(request('user_id'), fn($builder) => $builder->whereUserId(request('user_id')))
            ->when(request('visitor_id'), fn($builder) => $builder->whereRelation('reservations', 'user_id', '=', request('visitor_id')))
            ->when(
                request('lat') && request('lng'),
                fn($builder) => $builder->nearestTo(request('lat'), request('lng')),
                fn($builder) => $builder->orderBy('id', 'ASC')
            )
            ->with(['images', 'tags', 'user'])
            ->withCount(['reservations' => fn($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
            ->paginate(20);

        return OfficeResource::collection(
            $offices
        );
    }

    public function show(Office $office)
    {
        $office->loadCount(['reservations' => fn($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
            ->load(['images', 'tags', 'user']);
        return OfficeResource::make($office);
    }

    public function create(): JsonResource
    {
        if(!auth()->user()->tokenCan('office.create')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $attributes = validator(
            request()->all(),
            [
                'title' => ['required', 'string'],
                'description' => ['required', 'string'],
                'lat' => ['required', 'numeric'],
                'lng' => ['required', 'numeric'],
                'address_line1' => ['required', 'string'],
                'hidden' => ['bool'],
                'price_per_day' => ['required', 'integer', 'min:100'],
                'monthly_discount' => ['integer', 'min:0'],

                'tags' => ['array'],
                'tags.*' => ['integer', Rule::exists('tags', 'id')]
            ]
        )->validate();
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Unauthorized');
        }
        // On peut directement appliquer la modification sur l'user_id comme ceci :
        // $attributes['user_id'] = $user->id;

        $attributes['approval_status'] = Office::APPROVAL_PENDING;

        // Sinon, on peut utiliser la relation entre les deux tables pour directement attacher l'user_id.
        $office = Auth::user()->offices()->create(Arr::except($attributes, ['tags']));

        $office->tags()->sync($attributes['tags']);

        return new OfficeResource($office);
    }
}
