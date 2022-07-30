<?php

namespace App\Notifications;

use App\Mail\WarningMail;
use App\Models\Endorsement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use anlutro\LaravelSettings\Facade as Setting;
use Carbon\Carbon;

class InactiveOnlineStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $user, $position, $logonTime;

    /**
     * Create a new notification instance.
     *
     * @param Endorsement $endorsement
     */
    public function __construct($user, $position, $logonTime)
    {
        $this->user = $user;
        $this->position = $position;
        $this->logonTime = $logonTime;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return EndorsementMail
     */
    public function toMail($notifiable)
    {
        $textLines = [
            $this->user->name.' has been warned for logging on the network with an **inactive** ATC status.',
            'Position: '.$this->position,
            'Logon time: '.Carbon::parse($this->logonTime)->toEuropeanDateTime(),
        ];

        return (new WarningMail('Unauthorized network logon recorded', $this->user, $textLines))
            ->to($this->user->email, $this->user->name);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [];
    }
}
