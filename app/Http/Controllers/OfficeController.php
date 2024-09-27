<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Office;
use App\Models\Reservation;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\OfficeResource;
use Illuminate\Support\Facades\Storage;
use App\Models\Validators\OfficeValidator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Notifications\OfficePendingApprovalNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfficeController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResource
    {
        $offices = Office::query()
            // if the request contains a host_id (condition ture), invoke the closure fn ($builder) => $builder->whereUserId(request('host_id'))
            ->when(request('user_id') && Auth::check() && request('user_id') === auth()->user()->id,
            fn($builder) => $builder,
            fn($builder) => $builder->where('approval_status', Office::APPROVAL_APPROVED)->where('hidden', false))
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

        Notification::send(User::where('is_admin', true)->get(), new OfficePendingApprovalNotification($office));

        return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }

    public function update(Office $office): JsonResource
    {
        abort_unless(
            auth()->user()->tokenCan('office.update'),
            Response::HTTP_FORBIDDEN
        );

        $this->authorize('update', $office);

        $attributes = (new OfficeValidator())->validate(
            $office,
            request()->all()
        );

        $office->fill(Arr::except($attributes, ['tags']));

        if ($requiresReview = $office->isDirty(['lat', 'lng', 'price_per_day'])) {
            $office->fill(['approval_status' => Office::APPROVAL_PENDING]);
        }

        DB::transaction(function () use ($office, $attributes) {

            $office->save();

            // Synchroniser les tags
            if (isset($attributes['tags'])) {
                $office->tags()->sync($attributes['tags']);
            }

            return $office; // Retourner l'office pour la réponse
        });

        if ($requiresReview) {
            Notification::send(User::where("is_admin", true)->get(), new OfficePendingApprovalNotification($office));
        }

        return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }

    public function delete(Office $office)
    {
        abort_unless(
            auth()->user()->tokenCan('office.delete'),
            Response::HTTP_FORBIDDEN
        );

        $this->authorize('delete', $office);

        throw_if(
            $office->reservations()->where('status', Reservation::STATUS_ACTIVE)->exists(),
            ValidationException::withMessages(['office' => 'Cannot delete this office!'])
        );

        $office->images()->each(function ($image) {
            Storage::delete($image->path);

            $image->delete();
        });

        $office->delete();
    }
}
