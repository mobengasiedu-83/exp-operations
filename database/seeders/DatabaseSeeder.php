<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\ServiceHour;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed permissions
        $permissions = [
            // Users
            'view users',
            'create users',
            'edit users',
            'delete users',
            // Roles
            'view roles',
            'create roles',
            'edit roles',
            'delete roles',
            // Permissions
            'view permissions',
            // Settings
            'view settings',
            'edit settings',
            // Sessions
            'view sessions',
            'terminate sessions',
            // Audit
            'view audit',
            // PBX
            'view pbx',
            // Call history (simplified call list and full call detail)
            'view call history',
            'export call history',
            'control calls',
            // Operational reports (Phase 6)
            'view reports',
            'view executive reports',
            'export reports',
            // Cases
            'view cases',
            'edit cases',
            'view all cases',
            'review cases',
            'manage screening protocols',
            // Dentists
            'view dentists',
            'edit dentists',
            // Callbacks and referrals
            'view callbacks',
            'edit callbacks',
            'view referrals',
            'edit referrals',
            // Knowledge bank
            'view knowledge bank',
            'manage knowledge bank',
            'approve clinical knowledge',
            'approve brand knowledge',
            // Quality assurance, training and incidents
            'view quality assurance',
            'manage quality assurance',
            'approve quality assurance',
            'view training',
            'manage training',
            'authorise live calls',
            'view incidents',
            'report incidents',
            'manage incidents',
            'close incidents',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Seed roles
        $roleDefinitions = [
            'Super Administrator' => $permissions,
            'Platform Manager' => [
                'view users', 'edit users',
                'view roles', 'view permissions',
                'view settings', 'edit settings',
                'view sessions', 'terminate sessions',
                'view audit',
                'view pbx',
                'view reports',
                'view cases', 'edit cases',
                'view dentists', 'edit dentists',
                'view callbacks', 'view referrals',
                'view knowledge bank', 'manage knowledge bank',
                'view quality assurance',
                'view training', 'manage training',
                'view incidents', 'report incidents', 'manage incidents', 'close incidents',
            ],
            'Lead Dentist / Clinical Supervisor' => [
                'view users',
                'view pbx',
                'view cases',
                'edit cases', 'view all cases', 'review cases',
                'manage screening protocols',
                'view audit',
                'view dentists', 'edit dentists',
                'view callbacks', 'edit callbacks', 'view referrals', 'edit referrals',
                'view knowledge bank', 'manage knowledge bank', 'approve clinical knowledge',
                'view quality assurance', 'manage quality assurance', 'approve quality assurance',
                'view training', 'manage training', 'authorise live calls',
                'view reports',
                'view incidents', 'report incidents', 'manage incidents', 'close incidents',
            ],
            'Dental Assistant' => [
                'view users',
                'view cases', 'edit cases',
                'view dentists',
                'view callbacks', 'edit callbacks', 'view referrals', 'edit referrals',
                'view knowledge bank',
                'view training', 'report incidents',
            ],
            'Dentist' => [
                'view cases',
                'view dentists',
            ],
            'QA Reviewer' => [
                'view audit',
                'view reports',
                'view cases',
                'view dentists',
                'view knowledge bank',
                'view quality assurance', 'manage quality assurance', 'approve quality assurance',
                'view training',
                'view incidents', 'report incidents',
            ],
            'Executive / Management' => [
                'view users',
                'view roles',
                'view settings',
                'view audit',
                'view reports', 'view executive reports', 'export reports',
                'view cases',
                'view dentists',
                'view knowledge bank',
                'view quality assurance', 'view training', 'view incidents',
            ],
            // Simplified console roles (Blueprint phase 1)
            'Agent' => [
                'view cases', 'edit cases',
                'control calls', 'view call history',
                'view dentists',
            ],
            'Supervisor' => [
                'view cases', 'edit cases', 'view all cases', 'review cases',
                'control calls', 'view call history', 'view reports',
                'view dentists',
                'view audit',
            ],
            'Administrator' => $permissions,
        ];

        foreach ($roleDefinitions as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }

        // Seed service hours
        $this->call(ScreeningProtocolSeeder::class);
        $this->call(TransferAccessSeeder::class);
        $this->call(FollowUpAccessSeeder::class);
        $this->call(KnowledgeBankAccessSeeder::class);
        $this->call(QualityTrainingAccessSeeder::class);
        $this->call(ReportCatalogueSeeder::class);

        // Seed the practice dentist directory and contact numbers
        $this->call(DentistDirectorySeeder::class);

        // Publish the role manuals and the read-only Viewer role
        $this->call(GuideAccessSeeder::class);

        ServiceHour::firstOrCreate(
            ['name' => 'Standard Hours'],
            [
                'opens_at' => '10:00:00',
                'closes_at' => '14:00:00',
                'days' => [1, 2, 3, 4, 5],
                'is_closed' => false,
                'description' => 'Mon–Fri, 10:00–14:00 Ghana time. Closed on Ghana public holidays.',
            ]
        );

        // Seed settings
        $settings = [
            ['key' => 'app.language', 'group' => 'general', 'value' => 'en', 'cast' => 'string', 'is_feature_flag' => false, 'description' => 'Default application language.'],
            ['key' => 'app.feature_flags.ami_listener', 'group' => 'feature_flags', 'value' => 'true', 'cast' => 'boolean', 'is_feature_flag' => true, 'description' => 'Enable the PBX AMI listener.'],
            ['key' => 'app.feature_flags.reverb_push', 'group' => 'feature_flags', 'value' => 'false', 'cast' => 'boolean', 'is_feature_flag' => true, 'description' => 'Enable Reverb push updates (Phase 4).'],
            ['key' => 'service.default_holiday', 'group' => 'service', 'value' => '2026-12-25', 'cast' => 'string', 'is_feature_flag' => false, 'description' => 'Default holiday date.'],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                [
                    'group' => $setting['group'],
                    'value' => $setting['value'],
                    'cast' => $setting['cast'],
                    'is_feature_flag' => $setting['is_feature_flag'],
                    'description' => $setting['description'],
                ]
            );
        }

        // Seed live-operations alert rules (Phase 4)
        $alertRules = [
            [
                'key' => 'ami_stale',
                'name' => 'AMI telemetry stale',
                'category' => 'telemetry',
                'metric' => 'heartbeat_age_seconds',
                'threshold_seconds' => (int) config('services.exp_pbx.heartbeat_stale_seconds', 90),
                'severity' => 'critical',
                'description' => 'The AMI listener heartbeat is missing or older than the configured threshold.',
            ],
            [
                'key' => 'long_ringing',
                'name' => 'Dentist ringing too long',
                'category' => 'ringing',
                'metric' => 'ringing_seconds',
                'threshold_seconds' => (int) config('services.exp_pbx.long_ringing_seconds', 45),
                'severity' => 'warning',
                'description' => 'A dentist mobile leg is still ringing beyond the threshold without an answer.',
            ],
            [
                'key' => 'failed_transfer',
                'name' => 'Warm transfer not completed',
                'category' => 'transfer',
                'metric' => 'failed_attempts',
                'threshold_seconds' => null,
                'severity' => 'warning',
                'description' => 'A completed journey has failed dentist attempts and no answered dentist leg.',
            ],
        ];

        foreach ($alertRules as $rule) {
            AlertRule::firstOrCreate(['key' => $rule['key']], $rule + ['enabled' => true]);
        }

        // Create default admin user
        $admin = User::firstOrCreate(['email' => 'admin@exp-operations.local'], [
            'name' => 'Administrator',
            'password' => Str::random(64),
            'active' => true,
        ]);
        if ($admin->wasRecentlyCreated) {
            $admin->forceFill(['email_verified_at' => now()])->save();
        }
        $admin->assignRole('Super Administrator');
    }
}
