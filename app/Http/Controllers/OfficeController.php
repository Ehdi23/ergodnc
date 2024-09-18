<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Reservation;
use App\Http\Resources\OfficeResource;
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
            ->when(request('host_id'), fn ($builder) => $builder->whereUserId(request('host_id')))
            ->when(request('user_id'), fn ($builder) => $builder->whereRelation('reservations', 'user_id', '=', request('user_id')))
            ->when(
                request('lat') && request('lng'), fn ($builder) => $builder->nearestTo(request('lat'), request('lng')), fn ($builder) => $builder->orderBy('id', 'ASC')
            )
            ->with(['images', 'tags', 'user'])
            ->withCount(['reservations' => fn ($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
            ->paginate(20);

        return OfficeResource::collection(
            $offices
        );
    }

    public function show(Office $office) 
    {
        $office->loadCount(['reservations' => fn ($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
        ->load(['images', 'tags', 'user']);
        return OfficeResource::make($office);
    }
}
