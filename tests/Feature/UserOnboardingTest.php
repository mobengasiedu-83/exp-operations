<?php

use App\Models\User;
use App\Notifications\SetupPassword;
use App\Notifications\VerifyUserEmail;
use App\Notifications\WelcomeUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;

test('admin creation sends welcome verification and working password setup emails', function () {
    Notification::fake();
    Permission::findOrCreate('create users', 'web');
    $admin = User::factory()->create();
    $admin->givePermissionTo('create users');

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Colleague',
        'email' => 'colleague@example.com',
        'password' => 'Initial-password-123!',
        'password_confirmation' => 'Initial-password-123!',
    ])->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'colleague@example.com')->sole();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, WelcomeUser::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        expect($mail->greeting)->toBe('Hello New Colleague!');

        return $notification->via($user) === ['mail'];
    });
    Notification::assertSentTo($user, VerifyUserEmail::class, function ($notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;
        expect(URL::hasValidSignature(Request::create($url)))->toBeTrue();
        $this->actingAs($user)->get($url)->assertRedirect();
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

        return true;
    });
    Notification::assertSentTo($user, SetupPassword::class, function ($notification) use ($user) {
        expect(Password::tokenExists($user, $notification->token))->toBeTrue();
        expect($notification->toMail($user)->actionText)->toBe('Set password');
        auth()->logout();
        $this->get($notification->toMail($user)->actionUrl)->assertOk();
        $this->post(route('password.update'), [
            'email' => $user->email,
            'token' => $notification->token,
            'password' => 'Chosen-password-456!',
            'password_confirmation' => 'Chosen-password-456!',
        ])->assertSessionHasNoErrors();
        expect(Hash::check('Chosen-password-456!', $user->fresh()->password))->toBeTrue();
        expect(Password::tokenExists($user, $notification->token))->toBeFalse();

        return true;
    });
    Notification::assertCount(3);
});

test('refused user creation sends no onboarding emails', function () {
    Notification::fake();
    Permission::findOrCreate('create users', 'web');
    $this->actingAs(User::factory()->create())->post(route('admin.users.store'), [
        'name' => 'New Colleague',
        'email' => 'colleague@example.com',
        'password' => 'Initial-password-123!',
        'password_confirmation' => 'Initial-password-123!',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'colleague@example.com']);
    Notification::assertNothingSent();
});
