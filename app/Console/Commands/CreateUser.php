<?php

namespace App\Console\Commands;

use App\Models\IptvUser;
use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'iptv:create-user';
    protected $description = 'Create a new IPTV user interactively';

    public function handle()
    {
        $this->info('Creating new IPTV user...');

        $username = $this->ask('Username');

        if (IptvUser::where('username', $username)->exists()) {
            $this->error('Username already exists!');
            return 1;
        }

        $password = $this->secret('Password (leave empty for random)');

        if (empty($password)) {
            $password = Str::random(12);
            $this->info("Generated password: {$password}");
        }

        $email = $this->ask('Email (optional)');

        // List packages
        $packages = Package::where('is_active', true)->get();

        if ($packages->isEmpty()) {
            $this->warn('No active packages found. User will be created without a package.');
            $packageId = null;
        } else {
            $this->info('Available packages:');
            foreach ($packages as $package) {
                $this->line("  [{$package->id}] {$package->name} - {$package->duration_days} days - \${$package->price}");
            }

            $packageId = $this->ask('Package ID (leave empty for none)');

            if ($packageId && !$packages->contains('id', $packageId)) {
                $this->error('Invalid package ID');
                return 1;
            }
        }

        $expiryDays = $this->ask('Expiry days from now (0 for no expiry)', 30);

        $expiresAt = $expiryDays > 0 ? now()->addDays($expiryDays) : null;

        $user = IptvUser::create([
            'username' => $username,
            'password' => $password,
            'email' => $email ?: null,
            'package_id' => $packageId ?: null,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        $this->info('✓ User created successfully!');
        $this->line('');
        $this->line('Credentials:');
        $this->line("  Username: {$user->username}");
        $this->line("  Password: {$password}");
        $this->line("  Expires: " . ($expiresAt ? $expiresAt->format('Y-m-d H:i:s') : 'Never'));

        return 0;
    }
}
