<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The package's model has no HasFactory: build with PushSubscriptionFactory::new().
 *
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        $user = User::factory();

        return [
            'subscribable_type' => (new User())->getMorphClass(),
            'subscribable_id' => $user,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->unique()->uuid(),
            // A P-256 public key and an auth secret, base64url, as a browser hands them over.
            'public_key' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth_token' => 'tBHItJI5svbpez7KI4CCXg',
            'content_encoding' => 'aes128gcm',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['subscribable_type' => $user->getMorphClass(), 'subscribable_id' => $user->id]);
    }
}
