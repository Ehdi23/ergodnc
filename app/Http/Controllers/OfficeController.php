<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Reservation;
use Illuminate\Support\Arr;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\OfficeResource;
use App\Models\Validators\OfficeValidator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
class OfficeController extends Controller
{
    use AuthorizesRequests;
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
        abort_unless(auth()->user()->tokenCan('office.create'), Response::HTTP_FORBIDDEN);

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

        $office = DB::transaction(function () use ($attributes) {
            $user = Auth::user();

            // S'assurer que l'utilisateur est bien authentifié
            if (!$user) {
                abort(403, 'Unauthorized');
            }

            // Créer l'office
            $office = $user->offices()->create(Arr::except($attributes, ['tags']));

            // Synchroniser les tags
            if (isset($attributes['tags'])) {
                $office->tags()->attach($attributes['tags']);
            };

            return $office; // Retourner l'office pour la réponse
        });

        return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }

    public function update(Office $office): JsonResource
    {
        abort_unless(auth()->user()->tokenCan('office.update'), Response::HTTP_FORBIDDEN);

        $this->authorize('update', $office);

        $attributes = (new OfficeValidator())->validate(
            $office,
            request()->all()
        );

        DB::transaction(function () use ($office, $attributes) {
            
            $office->save();

            $office->fill(Arr::except($attributes, ['tags']))->save();

            // Synchroniser les tags
            if (isset($attributes['tags'])) {
                $office->tags()->sync($attributes['tags']);
            }

            return $office; // Retourner l'office pour la réponse
        });

        return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }
}
