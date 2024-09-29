<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use App\Notifications\HostReservationStarting;
use App\Notifications\UserReservationStarting;

class SendDueReservationsNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ergodnc-send-reservations-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Reservation::query()
            ->with('office.user')
            ->where('status', Reservation::STATUS_ACTIVE)
            ->where('start_date', now()->toDateString())
            ->each(function ($reservation) {
                Notification::send($reservation->user, new UserReservationStarting($reservation));
                Notification::send($reservation->office->user, new HostReservationStarting($reservation));
            });


        return 0;
    }
}
